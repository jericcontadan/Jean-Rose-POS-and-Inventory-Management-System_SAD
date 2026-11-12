<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Sales Records (screen 7, Step 5): owner only, sales of one day grouped by receipt, daily summary, read only.
// (The Void action itself is tested in VoidTest.)
// Sales are made directly here with a fixed date, so dates and statuses are exact.
// The dates are in 2030 so they never mix with other sales in the test database.
// DatabaseTransactions rolls everything back after each test.
class SalesRecordsTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeUser('OWNER');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // undo any fixed "now"
        parent::tearDown();
    }

    // ---------- helper ----------

    /**
     * Save one sale like Pay Now would. $lines: [[product, quantity, unit price, discount percent], ...].
     * Example: makeSale('2030-01-15 09:02', [[$serum, 2, 120, 10]]) = Serum x2 at 120 with 10% off = 216.00.
     */
    private function makeSale(string $datetime, array $lines, string $status = 'PAID', ?string $reason = null): Sale
    {
        $subtotal = 0;
        $total = 0;
        foreach ($lines as [$product, $quantity, $price, $discount]) {
            $subtotal += $quantity * $price;
            $total += round($quantity * $price * (1 - $discount / 100), 2);
        }

        $sale = Sale::create([
            'user_id' => $this->owner->user_id,
            'sale_datetime' => $datetime,
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'discount_percent' => 0,
            'sale_status' => $status,
            'cash_received' => $total,
            'change_amount' => 0,
            'void_reason' => $reason,
            'voided_at' => $status === 'CANCELLED' ? $datetime : null,
            'voided_by' => $status === 'CANCELLED' ? $this->owner->user_id : null,
        ]);

        foreach ($lines as [$product, $quantity, $price, $discount]) {
            SaleItem::create([
                'sale_id' => $sale->sale_id,
                'batch_id' => $product->batches()->first()->batch_id,
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_amount' => round($quantity * $price * (1 - $discount / 100), 2),
                'discount_percent' => $discount,
            ]);
        }

        return $sale;
    }

    private function product(string $name, float $price): Product
    {
        return $this->makeProduct($name, $price, [[200, 50, 2]]);
    }

    // ---------- access ----------

    public function test_cashier_gets_403(): void
    {
        $this->actingAs($this->makeUser('CASHIER'));

        $this->get('/sales')->assertForbidden();
    }

    // ---------- T15 ----------

    public function test_t15_sales_records_by_date_show_correct_rows_and_totals(): void
    {
        $serum = $this->product('Serum', 120);
        $makeup = $this->product('Makeup', 150);

        $first = $this->makeSale('2030-01-15 09:02:00', [[$serum, 2, 120, 10]]);   // 216.00
        $second = $this->makeSale('2030-01-15 14:30:00', [[$makeup, 1, 150, 0]]);  // 150.00
        $otherDay = $this->makeSale('2030-01-14 23:59:00', [[$makeup, 3, 150, 0]]); // not on the 15th

        $response = $this->get('/sales?date=2030-01-15')->assertOk();

        // Only the two sales of that day, oldest first
        $response->assertSeeInOrder([$first->receiptNumber(), $second->receiptNumber()]);
        $response->assertDontSee($otherDay->receiptNumber());

        // Item row: date, category, product, quantity, price, discount, total amount
        $response->assertSeeInOrder([
            '01/15/2030', $serum->category->category_name, 'Serum', '2', '₱120.00', '10%', '₱216.00',
        ]);

        // Daily summary (whole day)
        $response->assertViewHas('summary', fn (array $s) => $s['paid_count'] === 2
            && $s['cancelled_count'] === 0
            && $s['total_sales'] === 366.00
            && $s['total_discount'] === 24.00);
        $response->assertSee('₱366.00');
    }

    public function test_default_date_is_today_in_manila(): void
    {
        // 11:30 PM in Manila (still 3:30 PM UTC): "today" must be the Manila date.
        Carbon::setTestNow(Carbon::parse('2030-02-20 23:30:00', 'Asia/Manila'));
        $serum = $this->product('Serum', 120);

        $today = $this->makeSale('2030-02-20 23:00:00', [[$serum, 1, 120, 0]]);
        $tomorrow = $this->makeSale('2030-02-21 00:30:00', [[$serum, 1, 120, 0]]);

        $this->get('/sales')
            ->assertOk()
            ->assertSee('value="2030-02-20"', false)
            ->assertSee($today->receiptNumber())
            ->assertDontSee($tomorrow->receiptNumber());
    }

    public function test_bad_date_is_refused(): void
    {
        $this->get('/sales?date=abc')->assertSessionHasErrors('date');
    }

    // ---------- voided sales ----------

    public function test_voided_sale_is_shown_grey_with_reason_and_not_in_total(): void
    {
        $serum = $this->product('Serum', 120);
        $makeup = $this->product('Makeup', 150);

        $paid = $this->makeSale('2030-01-16 09:00:00', [[$serum, 2, 120, 10]]);                       // 216.00
        $void = $this->makeSale('2030-01-16 10:00:00', [[$makeup, 1, 150, 0]], 'CANCELLED', 'wrong item'); // 150.00

        $response = $this->get('/sales?date=2030-01-16')->assertOk();

        $response->assertSee($void->receiptNumber())
            ->assertSee('jr-sales-voided', false)
            ->assertSee('VOIDED')
            ->assertSee('Reason: wrong item');

        $response->assertViewHas('summary', fn (array $s) => $s['paid_count'] === 1
            && $s['cancelled_count'] === 1
            && $s['total_sales'] === 216.00);

        // Void is shown once, only for the PAID sale (the voided one has no Void button)
        $this->assertSame(1, substr_count($response->getContent(), 'jr-btn-void'));
    }

    // ---------- price at the time of sale ----------

    public function test_price_shown_is_the_price_at_the_time_of_sale(): void
    {
        $serum = $this->product('Serum', 120);
        $this->makeSale('2030-01-17 09:00:00', [[$serum, 2, 120, 0]]);

        // The owner raises the price later
        $serum->update(['unit_price' => 155]);

        $this->get('/sales?date=2030-01-17')
            ->assertOk()
            ->assertSee('₱120.00')
            ->assertSee('₱240.00')
            ->assertDontSee('155.00');
    }

    // ---------- read only ----------

    public function test_opening_the_page_writes_nothing(): void
    {
        $serum = $this->product('Serum', 120);
        $this->makeSale('2030-01-18 09:00:00', [[$serum, 1, 120, 0]]);
        $this->makeSale('2030-01-18 10:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'test');

        $counts = fn () => [Sale::count(), SaleItem::count(), AuditLog::count(), Inventory::sum('quantity_on_hand')];
        $before = $counts();

        // Record every SQL statement the page runs
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->get('/sales?date=2030-01-18')->assertOk();
        $this->get('/sales?date=2030-01-18&page=1')->assertOk();

        $this->assertSame([], $writes, 'The Sales Records page must not write to the database.');
        $this->assertEquals($before, $counts());
    }

    // ---------- reprint, pagination, disabled buttons ----------

    public function test_each_sale_links_to_its_receipt(): void
    {
        $serum = $this->product('Serum', 120);
        $a = $this->makeSale('2030-01-19 09:00:00', [[$serum, 1, 120, 0]]);
        $b = $this->makeSale('2030-01-19 10:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'test');

        $this->get('/sales?date=2030-01-19')
            ->assertSee(route('receipts.show', $a), false)
            ->assertSee(route('receipts.show', $b), false);
    }

    public function test_more_than_50_sales_are_paginated_but_summary_counts_all(): void
    {
        $serum = $this->product('Serum', 120);
        for ($i = 0; $i < 51; $i++) {
            $this->makeSale('2030-01-20 08:00:00', [[$serum, 1, 120, 0]]);
        }

        $this->get('/sales?date=2030-01-20')
            ->assertOk()
            ->assertViewHas('sales', fn ($sales) => $sales->count() === 50 && $sales->total() === 51)
            ->assertViewHas('summary', fn (array $s) => $s['paid_count'] === 51 && $s['total_sales'] === 6120.00)
            ->assertSee('date=2030-01-20&amp;page=2', false); // page links keep the date

        $this->get('/sales?date=2030-01-20&page=2')
            ->assertViewHas('sales', fn ($sales) => $sales->count() === 1);
    }

    public function test_export_is_disabled_and_void_is_a_link_for_paid_sales_only(): void
    {
        $serum = $this->product('Serum', 120);
        $paid = $this->makeSale('2030-01-21 09:00:00', [[$serum, 1, 120, 0]]);
        $this->makeSale('2030-01-21 10:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'test');

        $response = $this->get('/sales?date=2030-01-21')
            ->assertSee('disabled>Export to Excel</button>', false) // Excel export is Step 12
            ->assertSee('Close Sales'); // the Close summary box is on the page

        // Void (Step 7) is now a working link that opens the void card, shown once, only for the PAID sale.
        $response->assertSee(route('sales.index', ['date' => '2030-01-21', 'void' => $paid->sale_id])); // escaped: &amp;
        $this->assertSame(1, substr_count($response->getContent(), '>Void</a>'));
        $this->assertTrue(Route::has('sales.void'));
    }
}
