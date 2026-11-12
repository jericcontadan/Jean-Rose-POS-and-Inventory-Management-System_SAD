<?php

namespace Tests\Feature;

use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\UserAccount;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\SaleService;
use App\Services\StockStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Update Stock and Price (screen 10, extra 9): T16 from docs/06-testing.md, plus: restock adds to both quantity
// columns, history and audit rows, LOW STOCK -> OKAY resolves the alert, invalid input refused, unchanged price
// saves nothing, cashier 403, and a forced error saves nothing. DatabaseTransactions rolls everything back after each test.
class StockPriceTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;
    private UserAccount $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed rules for the tests, whatever .env says.
        config(['pos.near_expiry_days' => 30, 'pos.near_expiry_discount_percent' => 10]);

        $this->owner = $this->makeUser('OWNER');
        $this->cashier = $this->makeUser('CASHIER');
        $this->actingAs($this->owner);
    }

    // ---------- helpers ----------

    // The only inventory row of a product made with one batch.
    private function stockRow(Product $product): Inventory
    {
        return $product->batches()->first()->inventory()->with('batch')->first();
    }

    // Vitamins, batch B0: 2 pieces, reorder level 5, expiry far away. Status and alerts set like after a sale: LOW STOCK.
    private function lowStockVitamins(): Inventory
    {
        $vitamins = $this->makeProduct('Test Vitamins', 80, [[300, 2, 5]]);
        $inventory = $this->stockRow($vitamins);
        app(StockStatusService::class)->refresh($inventory);
        app(AlertService::class)->sync($inventory);

        return $inventory;
    }

    private function restock(Inventory $inventory, $quantity)
    {
        return $this->put(route('inventory.stock.update', $inventory), ['quantity' => $quantity]);
    }

    private function changePrice(Product $product, $price)
    {
        return $this->put(route('products.price.update', $product), ['unit_price' => $price]);
    }

    // Row counts of every table these screens can write, to prove "nothing changed".
    private function counts(): array
    {
        return [
            'inventory_history' => InventoryHistory::count(),
            'audit_log' => AuditLog::count(),
            'alert_notification' => AlertNotification::count(),
            'open_alerts' => AlertNotification::whereNull('resolved_at')->count(),
        ];
    }

    // [quantity_on_hand, quantity_in_batch, stock_status] of the row, read again from the database.
    private function state(Inventory $inventory): array
    {
        $fresh = Inventory::with('batch')->find($inventory->inventory_id);

        return [$fresh->quantity_on_hand, $fresh->batch->quantity_in_batch, $fresh->stock_status];
    }

    // ---------- T16: price change ----------

    public function test_t16_price_change_keeps_past_sales_and_writes_audit_row(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $sale = app(SaleService::class)->pay($this->cashier, [$serum->product_id => 1], 1000);

        $this->changePrice($serum, '125.00')
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success', 'Price of Serum is now ₱125.00.');

        // The product has the new price; the past sale keeps the price it was sold at.
        $this->assertSame('125.00', $serum->fresh()->unit_price);
        $this->assertSame('120.00', (string) SaleItem::where('sale_id', $sale->sale_id)->value('unit_price'));
        $this->assertSame('120.00', (string) $sale->fresh()->total_amount);

        // One audit row with the old and new price.
        $audit = AuditLog::where('user_id', $this->owner->user_id)->latest('audit_log_id')->first();
        $this->assertSame('INVENTORY_UPDATE', $audit->action_type);
        $this->assertSame('INVENTORY', $audit->module);
        $this->assertSame($serum->product_number, $audit->transaction_reference);
        $this->assertSame('Price Serum: 120.00 to 125.00', $audit->details);
        $this->assertNull($audit->sale_id);
    }

    // ---------- restock ----------

    public function test_restock_adds_to_both_quantities_and_writes_history_and_audit(): void
    {
        $inventory = $this->lowStockVitamins();
        $this->assertSame([2, 2, 'LOW STOCK'], $this->state($inventory));

        $this->restock($inventory, '20')
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success', 'Stock of Test Vitamins (batch B0) is now 22.');

        // Both quantity columns go up by 20.
        $this->assertSame([22, 22, 'OKAY'], $this->state($inventory));

        // History: one RESTOCK row, positive, by the owner.
        $history = InventoryHistory::where('inventory_id', $inventory->inventory_id)->get();
        $this->assertCount(1, $history);
        $this->assertSame('RESTOCK', $history[0]->transaction_type);
        $this->assertSame(20, $history[0]->quantity_change);
        $this->assertSame($inventory->batch_id, $history[0]->batch_id);
        $this->assertSame($this->owner->user_id, $history[0]->user_id);

        // Audit row like "Restock B-VITA-01 +20 (2 to 22)".
        $audit = AuditLog::where('user_id', $this->owner->user_id)->latest('audit_log_id')->first();
        $this->assertSame('INVENTORY_UPDATE', $audit->action_type);
        $this->assertSame('INVENTORY', $audit->module);
        $this->assertSame('B0', $audit->transaction_reference);
        $this->assertSame('Restock B0 +20 (2 to 22)', $audit->details);
    }

    public function test_big_restock_turns_low_stock_to_okay_and_resolves_the_alert(): void
    {
        $inventory = $this->lowStockVitamins();
        $open = fn () => AlertNotification::where('batch_id', $inventory->batch_id)->whereNull('resolved_at');
        $this->assertSame(1, $open()->where('alert_type', 'LOW STOCK')->count());

        $this->restock($inventory, '20');

        // OKAY now: the LOW STOCK alert is resolved and no new alert was opened.
        $this->assertSame('OKAY', $this->state($inventory)[2]);
        $this->assertSame(0, $open()->count());
        $this->assertNotNull(AlertNotification::where('batch_id', $inventory->batch_id)->value('resolved_at'));
        $this->assertSame(1, AlertNotification::where('batch_id', $inventory->batch_id)->count());
    }

    public function test_small_restock_stays_low_stock_without_a_duplicate_alert(): void
    {
        $inventory = $this->lowStockVitamins();

        $this->restock($inventory, '1'); // 3 pieces, still <= reorder level 5

        $this->assertSame([3, 3, 'LOW STOCK'], $this->state($inventory));
        $this->assertSame(1, AlertNotification::where('batch_id', $inventory->batch_id)->whereNull('resolved_at')->count());
    }

    // ---------- invalid input is refused ----------

    public function test_invalid_quantity_is_refused_and_nothing_changes(): void
    {
        $inventory = $this->lowStockVitamins();
        $before = $this->counts();

        foreach (['', '0', '-1', '1.5', 'abc', '100001'] as $bad) {
            $this->from(route('inventory.stock.edit', $inventory))
                ->restock($inventory, $bad)
                ->assertRedirect(route('inventory.stock.edit', $inventory))
                ->assertSessionHasErrors('quantity');
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame([2, 2, 'LOW STOCK'], $this->state($inventory));
    }

    public function test_invalid_price_is_refused_and_nothing_changes(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();

        foreach (['', '0', '-5', 'abc', '12.345', '99999999999'] as $bad) {
            $this->from(route('products.price.edit', $serum))
                ->changePrice($serum, $bad)
                ->assertRedirect(route('products.price.edit', $serum))
                ->assertSessionHasErrors('unit_price');
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame('120.00', $serum->fresh()->unit_price);
    }

    public function test_unchanged_price_saves_nothing(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();

        foreach (['120', '120.00', '120.0'] as $same) {
            $this->changePrice($serum, $same)
                ->assertRedirect(route('inventory.index'))
                ->assertSessionHas('success', 'Price of Serum is unchanged. Nothing was saved.');
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame('120.00', $serum->fresh()->unit_price);
    }

    // ---------- owner only ----------

    public function test_cashier_gets_403_and_nothing_changes(): void
    {
        $inventory = $this->lowStockVitamins();
        $product = $inventory->batch->product;
        $before = $this->counts();
        $this->actingAs($this->cashier);

        $this->get(route('inventory.stock.edit', $inventory))->assertForbidden();
        $this->restock($inventory, '20')->assertForbidden();
        $this->get(route('products.price.edit', $product))->assertForbidden();
        $this->changePrice($product, '99.00')->assertForbidden();

        $this->assertSame($before, $this->counts());
        $this->assertSame([2, 2, 'LOW STOCK'], $this->state($inventory));
        $this->assertSame('80.00', $product->fresh()->unit_price);
    }

    // ---------- a forced error in the middle saves nothing ----------

    public function test_forced_error_in_restock_saves_nothing(): void
    {
        $inventory = $this->lowStockVitamins();
        $before = $this->counts();

        // The audit row is the LAST step: batch, stock, status, history and alerts were already written when it fails.
        $this->mock(AuditService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new RuntimeException('Forced error')));

        $this->restock($inventory, '20')
            ->assertRedirect(route('inventory.stock.edit', $inventory))
            ->assertSessionHas('error', 'Save failed. Nothing was changed. Please try again.');

        $this->assertSame($before, $this->counts());
        $this->assertSame([2, 2, 'LOW STOCK'], $this->state($inventory));
    }

    public function test_forced_error_in_price_change_saves_nothing(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();

        $this->mock(AuditService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new RuntimeException('Forced error')));

        $this->changePrice($serum, '125.00')
            ->assertRedirect(route('products.price.edit', $serum))
            ->assertSessionHas('error', 'Save failed. Nothing was changed. Please try again.');

        $this->assertSame($before, $this->counts());
        $this->assertSame('120.00', $serum->fresh()->unit_price);
    }

    // ---------- the pages ----------

    public function test_pages_show_the_cards_and_inventory_links_to_them(): void
    {
        $inventory = $this->lowStockVitamins();
        $product = $inventory->batch->product;

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertSee(route('inventory.stock.edit', $inventory), false)
            ->assertSee(route('products.price.edit', $product), false);

        $this->get(route('inventory.stock.edit', $inventory))
            ->assertOk()
            ->assertSee('Update stock')
            ->assertSee('Test Vitamins')
            ->assertSee('B0 (expires '.$inventory->batch->expiry_date->format('m/d/Y').')')
            ->assertSee('Quantity to add *')
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('NEW STOCK')
            ->assertSee('Saved as a RESTOCK in inventory history.')
            ->assertSee('Save stock');

        $this->get(route('products.price.edit', $product))
            ->assertOk()
            ->assertSee('Update price')
            ->assertSee('₱80.00')
            ->assertSee('New unit price *')
            ->assertSee('inputmode="decimal"', false)
            ->assertSee('The old and new price are saved in the audit log.')
            ->assertSee('Past sales keep the price they were sold at.')
            ->assertSee('Save price');
    }
}
