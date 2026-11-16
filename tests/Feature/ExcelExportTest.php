<?php

namespace Tests\Feature;

use App\Exports\InventoryExport;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\UserAccount;
use App\Services\SaleService;
use App\Services\StockStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Excel export (screen 11, export side): T20 from docs/06-testing.md, plus: cashier 403, guest to login,
// date validation, voided sales not in the totals, and the EXPORT audit row.
// The files are really built and then opened with PhpSpreadsheet to check the cells.
// Time is frozen on a day with no other sales, so only the sales made here are in the report.
// DatabaseTransactions rolls everything back after each test.
class ExcelExportTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private const DAY = '2030-01-15';

    private UserAccount $owner;
    private UserAccount $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::DAY.' 10:00:00');
        // Fixed rules for the tests, whatever .env says.
        config(['pos.near_expiry_days' => 30, 'pos.near_expiry_discount_percent' => 10]);

        $this->owner = $this->makeUser('OWNER');
        $this->cashier = $this->makeUser('CASHIER');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- helpers ----------

    private function exportUrl(array $query): string
    {
        return route('exports.download', $query);
    }

    // Open the downloaded .xlsx and return its first sheet.
    private function sheetFrom($response): Worksheet
    {
        $path = $response->baseResponse->getFile()->getPathname();

        return IOFactory::load($path)->getActiveSheet();
    }

    // A zero must be written as 0 (shown as ₱0.00), not left blank.
    // (assertEquals(0, null) would pass, so check "not blank" first.)
    private function assertZeroCell(Worksheet $sheet, string $cell): void
    {
        $value = $sheet->getCell($cell)->getValue();
        $this->assertNotNull($value, "{$cell} is blank, expected 0");
        $this->assertEquals(0, $value, "{$cell} should be 0");
    }

    // A cashier sells: $cart = [product_id => quantity]. Enough cash is always given.
    private function sell(array $cart): Sale
    {
        return app(SaleService::class)->pay($this->cashier, $cart, 1000);
    }

    // ---------- Sales report ----------

    public function test_owner_downloads_the_sales_report(): void
    {
        $serum = $this->makeProduct('Serum', 100, [[200, 10, 2]]);
        $sale = $this->sell([$serum->product_id => 2]);

        $response = $this->actingAs($this->owner)
            ->get($this->exportUrl(['report' => 'sales', 'from' => self::DAY, 'to' => self::DAY]));

        $response->assertOk();
        $response->assertDownload('JeanRose_Sales_2030-01-15_to_2030-01-15.xlsx');

        $sheet = $this->sheetFrom($response);

        // Header row, in bold.
        $this->assertSame(
            ['Sale ID', 'Date/Time', 'Cashier', 'Items', 'Subtotal', 'Discount', 'Total', 'Cash Received', 'Change', 'Status'],
            $sheet->rangeToArray('A1:J1')[0],
        );
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());

        // The sale row.
        $this->assertSame($sale->receiptNumber(), $sheet->getCell('A2')->getValue());
        $this->assertSame($this->cashier->username, $sheet->getCell('C2')->getValue());
        $this->assertSame('Serum x2', $sheet->getCell('D2')->getValue());
        $this->assertZeroCell($sheet, 'F2');                          // no discount: ₱0.00, not blank
        $this->assertEquals(200, $sheet->getCell('G2')->getValue());
        $this->assertEquals(1000, $sheet->getCell('H2')->getValue()); // cash received
        $this->assertEquals(800, $sheet->getCell('I2')->getValue());  // change
        $this->assertSame('Completed', $sheet->getCell('J2')->getValue());

        // Date/Time is a real Excel date (a number with a date format), not text.
        $this->assertIsFloat($sheet->getCell('B2')->getValue());
        $this->assertSame('yyyy-mm-dd hh:mm', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());

        // Money uses the peso format (Total, Cash Received and Change).
        foreach (['G2', 'H2', 'I2'] as $cell) {
            $this->assertStringContainsString('₱', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
        }
    }

    public function test_voided_sales_are_listed_but_not_counted_in_the_totals(): void
    {
        $serum = $this->makeProduct('Serum', 100, [[200, 10, 2]]);
        $toner = $this->makeProduct('Toner', 50, [[200, 10, 2]]);

        $this->sell([$serum->product_id => 1]);                  // PAID: 100
        $voided = $this->sell([$toner->product_id => 1]);        // will be voided: 50
        app(SaleService::class)->void($this->owner, $voided, 'wrong item entered');

        $response = $this->actingAs($this->owner)
            ->get($this->exportUrl(['report' => 'sales', 'from' => self::DAY, 'to' => self::DAY]));
        $response->assertOk();

        $sheet = $this->sheetFrom($response);

        // The voided sale is still in the list, marked Voided.
        $this->assertSame('Completed', $sheet->getCell('J2')->getValue());
        $this->assertSame('Voided', $sheet->getCell('J3')->getValue());

        // TOTAL row (row 4): 1 completed sale, 100 pesos. The voided 50 is not added.
        $this->assertSame('TOTAL (completed only)', $sheet->getCell('A4')->getValue());
        $this->assertEquals(1, $sheet->getCell('D4')->getValue());
        $this->assertEquals(100, $sheet->getCell('E4')->getValue()); // subtotal
        $this->assertEquals(100, $sheet->getCell('G4')->getValue()); // total
        $this->assertEquals(1000, $sheet->getCell('H4')->getValue()); // cash received, the voided sale's 1000 not added
        $this->assertEquals(900, $sheet->getCell('I4')->getValue());  // change
        $this->assertZeroCell($sheet, 'F4');                          // no discount in the TOTAL row: ₱0.00
        $this->assertTrue($sheet->getStyle('A4')->getFont()->getBold());
    }

    public function test_totals_are_zero_when_the_only_sale_is_voided(): void
    {
        $toner = $this->makeProduct('Toner', 50, [[200, 10, 2]]);
        $voided = $this->sell([$toner->product_id => 1]);
        app(SaleService::class)->void($this->owner, $voided, 'wrong item entered');

        $response = $this->actingAs($this->owner)
            ->get($this->exportUrl(['report' => 'sales', 'from' => self::DAY, 'to' => self::DAY]));
        $response->assertOk();
        $sheet = $this->sheetFrom($response);

        // Row 2: the voided sale. Its own Discount is ₱0.00 too.
        $this->assertSame('Voided', $sheet->getCell('J2')->getValue());
        $this->assertZeroCell($sheet, 'F2');

        // Row 3: TOTAL with count 0 and every money cell ₱0.00 (not blank).
        $this->assertSame('TOTAL (completed only)', $sheet->getCell('A3')->getValue());
        foreach (['D3', 'E3', 'F3', 'G3', 'H3', 'I3'] as $cell) {
            $this->assertZeroCell($sheet, $cell);
        }
        $this->assertStringContainsString('₱', $sheet->getStyle('G3')->getNumberFormat()->getFormatCode());
    }

    public function test_no_sales_shows_a_message_row_and_zero_totals(): void
    {
        $response = $this->actingAs($this->owner)
            ->get($this->exportUrl(['report' => 'sales', 'from' => '2030-01-01', 'to' => '2030-01-02']));
        $response->assertOk();
        $sheet = $this->sheetFrom($response);

        $this->assertSame('No sales in this date range', $sheet->getCell('A2')->getValue());
        $this->assertSame('TOTAL (completed only)', $sheet->getCell('A3')->getValue());
        foreach (['D3', 'E3', 'F3', 'G3', 'H3', 'I3'] as $cell) {
            $this->assertZeroCell($sheet, $cell);
        }
        $this->assertNull($sheet->getCell('A4')->getValue()); // nothing after the TOTAL row
    }

    public function test_no_dates_means_today(): void
    {
        $this->actingAs($this->owner)
            ->get($this->exportUrl(['report' => 'sales']))
            ->assertOk()
            ->assertDownload('JeanRose_Sales_2030-01-15_to_2030-01-15.xlsx');
    }

    // ---------- Inventory report ----------

    public function test_owner_downloads_the_inventory_report(): void
    {
        $name = 'Zz Export Cream';                                // unique name, easy to find
        $this->makeProduct($name, 250, [[200, 0, 2], [10, 5, 2]]); // B0: out of stock, B1: near expiry

        $response = $this->actingAs($this->owner)->get($this->exportUrl(['report' => 'inventory']));

        $response->assertOk();
        $response->assertDownload('JeanRose_Inventory_2030-01-15.xlsx');

        $sheet = $this->sheetFrom($response);
        $this->assertSame(
            ['Product', 'Category', 'Batch', 'Expiry date', 'Qty', 'Price', 'Stock status'],
            $sheet->rangeToArray('A1:G1')[0],
        );
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());

        // Find our two rows (other products may be in the test database too).
        $statusByBatch = [];
        foreach ($sheet->toArray(null, false, false) as $i => $row) {
            if ($row[0] === $name) {
                $statusByBatch[$row[2]] = $row[6];
                if ($row[2] === 'B0') {
                    $this->assertSame(0, $row[4], 'Qty 0 must be written as 0, not left blank');
                }
                $this->assertEquals(250, $row[5]);
                $this->assertIsFloat($row[3], 'Expiry date must be a real Excel date');
                $this->assertSame('yyyy-mm-dd', $sheet->getStyle('D'.($i + 1))->getNumberFormat()->getFormatCode());
            }
        }

        // Labels from StockStatusService (key order does not matter).
        ksort($statusByBatch);
        $this->assertSame(['B0' => 'OUT OF STOCK', 'B1' => 'NEAR EXPIRY'], $statusByBatch);
    }

    // ---------- Audit log report ----------

    public function test_owner_downloads_the_audit_log_report(): void
    {
        $response = $this->actingAs($this->owner)
            ->get($this->exportUrl(['report' => 'audit', 'from' => '2030-01-01', 'to' => self::DAY]));

        $response->assertOk();
        $response->assertDownload('JeanRose_AuditLog_2030-01-01_to_2030-01-15.xlsx');

        $sheet = $this->sheetFrom($response);
        $this->assertSame(
            ['Date/Time', 'User', 'Action', 'Module', 'Reference', 'Details'],
            $sheet->rangeToArray('A1:F1')[0],
        );
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
    }

    public function test_empty_inventory_shows_a_message_row(): void
    {
        // The test database may already have products, so build the export with no rows directly
        // and write a real .xlsx (same writer as the download).
        $export = new InventoryExport(collect(), app(StockStatusService::class));
        $path = tempnam(sys_get_temp_dir(), 'jr').'.xlsx';
        file_put_contents($path, Excel::raw($export, ExcelType::XLSX));

        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertSame('Product', $sheet->getCell('A1')->getValue());
        $this->assertSame('No products in the inventory', $sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());
    }

    public function test_empty_audit_log_range_shows_a_message_row(): void
    {
        $response = $this->actingAs($this->owner)
            ->get($this->exportUrl(['report' => 'audit', 'from' => '2000-01-01', 'to' => '2000-01-01']));
        $response->assertOk();
        $sheet = $this->sheetFrom($response);

        $this->assertSame('No audit records in this date range', $sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());
    }

    // ---------- Access ----------

    public function test_cashier_gets_403_for_every_report(): void
    {
        foreach (['sales', 'inventory', 'audit'] as $report) {
            $this->actingAs($this->cashier)
                ->get($this->exportUrl(['report' => $report]))
                ->assertForbidden();
        }

        $this->assertSame(0, AuditLog::where('action_type', 'EXPORT')->where('user_id', $this->cashier->user_id)->count());
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get($this->exportUrl(['report' => 'sales']))->assertRedirect(route('login'));
    }

    // ---------- Date validation ----------

    public function test_to_date_before_from_date_shows_an_error(): void
    {
        $before = AuditLog::where('action_type', 'EXPORT')->count();
        $bad = $this->exportUrl(['report' => 'sales', 'from' => '2030-01-10', 'to' => '2030-01-05']);
        $this->actingAs($this->owner);

        // 1. The owner is sent back to the Audit page and the message shows in the Export card.
        //    (Checked first: with JSON sessions, assertSessionHasErrors() empties the flashed errors in tests.)
        $this->from(route('audit.index'))->get($bad)->assertRedirect(route('audit.index'));
        $this->get(route('audit.index'))
            ->assertSee("The To date can't be before the From date.")
            ->assertSee('is-invalid', false);

        // 2. The error is in the "export" bag, on the To field.
        $this->from(route('audit.index'))->get($bad)
            ->assertSessionHasErrors(['to' => "The To date can't be before the From date."], null, 'export');

        // Nothing was logged.
        $this->assertSame($before, AuditLog::where('action_type', 'EXPORT')->count());
    }

    public function test_future_date_shows_an_error(): void
    {
        $before = AuditLog::where('action_type', 'EXPORT')->count();

        $response = $this->actingAs($this->owner)
            ->from(route('audit.index'))
            ->get($this->exportUrl(['report' => 'sales', 'from' => self::DAY, 'to' => '2030-01-16']));

        $response->assertRedirect(route('audit.index'));
        $response->assertSessionHasErrors(['to' => "Dates can't be in the future."], null, 'export');
        $this->assertSame($before, AuditLog::where('action_type', 'EXPORT')->count());
    }

    public function test_unknown_report_shows_an_error(): void
    {
        $this->actingAs($this->owner)
            ->from(route('audit.index'))
            ->get($this->exportUrl(['report' => 'users']))
            ->assertSessionHasErrors(['report'], null, 'export');
    }

    // ---------- Audit row ----------

    public function test_each_export_writes_an_audit_row(): void
    {
        $this->actingAs($this->owner);

        $this->get($this->exportUrl(['report' => 'sales', 'from' => '2030-01-10', 'to' => self::DAY]))->assertOk();
        $this->get($this->exportUrl(['report' => 'inventory']))->assertOk();
        $this->get($this->exportUrl(['report' => 'audit', 'from' => self::DAY, 'to' => self::DAY]))->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'user_id' => $this->owner->user_id,
            'action_type' => 'EXPORT',
            'module' => 'SALES',
            'details' => 'Sales report 2030-01-10 to 2030-01-15',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => $this->owner->user_id,
            'action_type' => 'EXPORT',
            'module' => 'INVENTORY',
            'details' => 'Inventory report 2030-01-15',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => $this->owner->user_id,
            'action_type' => 'EXPORT',
            'module' => 'SALES',
            'details' => 'Audit log report 2030-01-15 to 2030-01-15',
        ]);
    }

    // ---------- Export buttons on the Inventory and Sales Records pages ----------

    public function test_inventory_page_export_button_links_to_the_inventory_report(): void
    {
        $url = route('exports.download', ['report' => 'inventory']);

        $this->actingAs($this->owner)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('href="'.e($url).'"', false);

        $this->get($url)->assertOk()->assertDownload('JeanRose_Inventory_2030-01-15.xlsx');
    }

    public function test_sales_page_export_button_links_to_the_sales_report_of_that_day(): void
    {
        $url = route('exports.download', ['report' => 'sales', 'from' => '2030-01-12', 'to' => '2030-01-12']);

        $this->actingAs($this->owner)
            ->get(route('sales.index', ['date' => '2030-01-12']))
            ->assertOk()
            ->assertSee('href="'.e($url).'"', false);

        $this->get($url)->assertOk()->assertDownload('JeanRose_Sales_2030-01-12_to_2030-01-12.xlsx');
    }

    public function test_sales_page_shows_the_export_error_for_a_future_day(): void
    {
        $page = route('sales.index', ['date' => '2030-01-20']);

        $this->actingAs($this->owner)
            ->from($page)
            ->get(route('exports.download', ['report' => 'sales', 'from' => '2030-01-20', 'to' => '2030-01-20']))
            ->assertRedirect($page);

        $this->get($page)->assertSee("Dates can't be in the future.");
    }

    // ---------- The card on the Audit Log page ----------

    public function test_audit_page_shows_a_working_export_form(): void
    {
        $this->actingAs($this->owner)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('action="'.route('exports.download').'"', false)
            ->assertSee('name="report" value="sales"', false)
            ->assertDontSee('Excel export comes in a later step.');
    }
}
