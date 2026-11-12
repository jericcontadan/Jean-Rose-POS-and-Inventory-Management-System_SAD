<?php

namespace Tests\Feature;

use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use App\Services\StockStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Inventory screen (screen 9, Step 4): owner only, one row per batch, stored status, live search, read only.
// Every test makes its own products; DatabaseTransactions rolls them back afterwards.
class InventoryTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed rules for the tests, whatever .env says.
        config(['pos.near_expiry_days' => 30]);

        $this->actingAs($this->makeUser('OWNER'));
    }

    // ---------- helpers ----------

    // Save the right stock_status on every batch of the product (what Pay Now / restock would do).
    private function refreshStatuses(Product $product): void
    {
        foreach ($product->batches()->with('inventory')->get() as $batch) {
            app(StockStatusService::class)->refresh($batch->inventory);
        }
    }

    // The table rows of one product, as sent to the view.
    private function rowsOf(Collection $rows, Product $product): Collection
    {
        return $rows->where('product_number', $product->product_number)->values();
    }

    // ---------- access ----------

    public function test_cashier_gets_403(): void
    {
        $this->actingAs($this->makeUser('CASHIER'));

        $this->get('/inventory')->assertForbidden();
    }

    public function test_guest_is_sent_to_login(): void
    {
        auth()->logout();

        $this->get('/inventory')->assertRedirect('/login');
    }

    // ---------- table ----------

    public function test_owner_sees_every_batch_with_its_status(): void
    {
        // Batches: [days until expiry, quantity on hand, reorder level]
        $serum = $this->makeProduct('Serum Inv', 120, [[200, 10, 2], [10, 5, 2]]); // OKAY + NEAR EXPIRY
        $lotion = $this->makeProduct('Lotion Inv', 80, [[300, 0, 2]]);              // OUT OF STOCK
        $vitamins = $this->makeProduct('Vitamins Inv', 90, [[300, 2, 5]]);          // LOW STOCK
        foreach ([$serum, $lotion, $vitamins] as $product) {
            $this->refreshStatuses($product);
        }

        $response = $this->get('/inventory')->assertOk();
        $rows = $response->viewData('rows');

        // One row per batch, earliest expiry first inside a product.
        $serumRows = $this->rowsOf($rows, $serum);
        $this->assertCount(2, $serumRows);
        $this->assertSame(['NEAR EXPIRY', 'OKAY'], $serumRows->pluck('status')->all());
        $this->assertSame(['OUT OF STOCK'], $this->rowsOf($rows, $lotion)->pluck('status')->all());
        $this->assertSame(['LOW STOCK'], $this->rowsOf($rows, $vitamins)->pluck('status')->all());

        // The page shows the batch numbers, the pills (OKAY shown as OK), price and supplier.
        $response->assertSee('Batch B0')
            ->assertSee('Batch B1')
            ->assertSee('NEAR EXPIRY')
            ->assertSee('OUT OF STOCK')
            ->assertSee('LOW STOCK')
            ->assertSee('<span class="jr-badge-ok jr-badge-table">OK</span>', false)
            ->assertDontSee('>OKAY<', false)
            ->assertSee('₱120.00')
            ->assertSee($serum->supplier->supplier_name);
    }

    public function test_expired_batch_shows_expired(): void
    {
        $old = $this->makeProduct('Old Cream Inv', 50, [[-3, 4, 1]]);
        $this->refreshStatuses($old);

        $response = $this->get('/inventory')->assertOk();

        $row = $this->rowsOf($response->viewData('rows'), $old)->first();
        $this->assertTrue($row['expired']);
        $response->assertSee('<span class="jr-inv-expired">Expired</span>', false)
            ->assertSee(Carbon::today()->subDays(3)->format('m/d/Y'));
    }

    public function test_status_comes_from_the_stored_value(): void
    {
        // Stored status is OKAY although the quantity is 0: the screen shows what is stored, it does not recalculate.
        $product = $this->makeProduct('Stored Inv', 60, [[300, 0, 2]]);

        $row = $this->rowsOf($this->get('/inventory')->viewData('rows'), $product)->first();

        $this->assertSame('OKAY', $row['status']);
    }

    // ---------- summary cards ----------

    public function test_summary_counts_and_first_item(): void
    {
        $expiry = Carbon::today()->addDays(13);
        $row = fn ($name, $qty, $reorder, $status) => [
            'product_name' => $name, 'quantity' => $qty, 'reorder_level' => $reorder,
            'status' => $status, 'expiry_date' => $expiry,
        ];
        $rows = collect([
            $row('Lotion', 0, 2, 'OUT OF STOCK'),
            $row('Makeup', 15, 2, 'OKAY'),
            $row('Serum', 10, 2, 'NEAR EXPIRY'),
            $row('Toner', 1, 3, 'LOW STOCK'),
            $row('Vitamins', 2, 5, 'LOW STOCK'),
        ]);

        $summary = app(InventoryService::class)->summary($rows);

        $this->assertSame(['count' => 2, 'first' => 'Toner · 1 left (reorder at 3)'], $summary['LOW STOCK']);
        $this->assertSame(['count' => 1, 'first' => 'Lotion · 0 left'], $summary['OUT OF STOCK']);
        $this->assertSame(['count' => 1, 'first' => 'Serum · expires '.$expiry->format('m/d/Y')], $summary['NEAR EXPIRY']);

        // No batch with that status: count 0 and "None".
        $empty = app(InventoryService::class)->summary(collect([$row('Makeup', 15, 2, 'OKAY')]));
        $this->assertSame(['count' => 0, 'first' => 'None'], $empty['LOW STOCK']);
    }

    // ---------- search ----------

    public function test_search_narrows_the_list(): void
    {
        $serum = $this->makeProduct('Serum Inv', 120, [[200, 10, 2]]);
        $lotion = $this->makeProduct('Lotion Inv', 80, [[300, 6, 2]]);
        ProductBatch::where('product_id', $lotion->product_id)->update(['batch_number' => 'LOT-QZX9']);

        // Returns which of the two products match the search.
        $matching = function (string $q) use ($serum, $lotion) {
            $rows = $this->get('/inventory?q='.urlencode($q))->assertOk()->viewData('rows');

            return [
                'serum' => $this->rowsOf($rows, $serum)->first()['matches'],
                'lotion' => $this->rowsOf($rows, $lotion)->first()['matches'],
            ];
        };

        $this->assertSame(['serum' => true, 'lotion' => false], $matching('serum inv'));                       // product name, case ignored
        $this->assertSame(['serum' => false, 'lotion' => true], $matching($lotion->product_number));           // product number
        $this->assertSame(['serum' => false, 'lotion' => true], $matching('lot-qzx9'));                        // batch number
        $this->assertSame(['serum' => true, 'lotion' => false], $matching($serum->supplier->supplier_name));   // supplier
        $this->assertSame(['serum' => true, 'lotion' => true], $matching(''));                                 // empty = all

        // Non-matching rows are sent hidden; the search text stays in the box.
        $this->get('/inventory?q=lot-qzx9')->assertSee('value="lot-qzx9"', false);
    }

    public function test_search_with_no_match_shows_message(): void
    {
        $this->makeProduct('Serum Inv', 120, [[200, 10, 2]]);

        $response = $this->get('/inventory?q=zz-no-such-product-zz');

        $this->assertFalse($response->viewData('rows')->contains('matches', true));
        // The "No products found." row is sent visible (no hidden attribute).
        $this->assertMatchesRegularExpression('/<tr id="invNoRows"\s*>/', $response->getContent());
    }

    // ---------- read only ----------

    public function test_opening_the_page_writes_nothing(): void
    {
        $product = $this->makeProduct('Stored Inv', 60, [[300, 0, 2]]); // stored OKAY on purpose (see above)
        $before = [Inventory::count(), InventoryHistory::count(), AuditLog::count(), AlertNotification::count()];

        $this->get('/inventory')->assertOk();
        $this->get('/inventory?q=stored')->assertOk();

        $after = [Inventory::count(), InventoryHistory::count(), AuditLog::count(), AlertNotification::count()];
        $this->assertSame($before, $after);
        $this->assertSame('OKAY', $product->batches()->first()->inventory->stock_status); // not recalculated or saved
    }
}
