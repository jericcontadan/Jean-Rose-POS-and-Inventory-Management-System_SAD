<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Void ONE item of a sale (Sales Records). Sales are made with the real SaleService::pay(),
// so stock, history and alerts are real. DatabaseTransactions rolls everything back after each test.
class ItemVoidTest extends TestCase
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

    private function sell(array $cart): Sale
    {
        return app(SaleService::class)->pay($this->cashier, $cart, 100000);
    }

    // Press "Confirm void item" with this reason.
    private function voidItem(SaleItem $item, ?string $reason = 'item entered twice')
    {
        return $this->post(route('sales.items.void', $item), $reason === null ? [] : ['reason' => $reason]);
    }

    // The sale_item row of this product in the sale.
    private function itemOf(Sale $sale, Product $product): SaleItem
    {
        return $sale->items()->whereIn('batch_id', $product->batches->pluck('batch_id'))->firstOrFail();
    }

    private function qtyOf(Product $product): int
    {
        return (int) $product->batches()->with('inventory')->get()->sum(fn ($b) => $b->inventory->quantity_on_hand);
    }

    private function counts(): array
    {
        return [
            'sale_item_voided' => SaleItem::where('item_status', 'VOIDED')->count(),
            'inventory_history' => InventoryHistory::count(),
            'audit_log' => AuditLog::count(),
        ];
    }

    // Serum near expiry (10% off): 1 x 120 = 108.00. Makeup: 3 x 150 = 450.00. Total 558.00, subtotal 570.00.
    private function twoItemSale(): array
    {
        $serum = $this->makeProduct('Test Serum', 120, [[10, 10, 5]]);
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$serum->product_id => 1, $makeup->product_id => 3]);

        return [$sale, $serum, $makeup];
    }

    // ---------- void one item ----------

    public function test_void_one_item_returns_stock_and_recalculates_totals(): void
    {
        [$sale, $serum, $makeup] = $this->twoItemSale();
        $this->assertSame('558.00', $sale->fresh()->total_amount);
        $this->assertSame(7, $this->qtyOf($makeup));
        $item = $this->itemOf($sale, $makeup);

        $this->voidItem($item)
            ->assertRedirect(route('sales.index', ['date' => $sale->sale_datetime->format('Y-m-d'), 'open' => $sale->sale_id]))
            ->assertSessionHas('success', "Item Test Makeup x3 was voided from sale {$sale->receiptNumber()}.");

        // The item is VOIDED with the reason, time and owner (not deleted).
        $item->refresh();
        $this->assertSame('VOIDED', $item->item_status);
        $this->assertSame('item entered twice', $item->void_reason);
        $this->assertNotNull($item->voided_at);
        $this->assertSame($this->owner->user_id, $item->voided_by);
        $this->assertSame('SOLD', $this->itemOf($sale, $serum)->item_status);

        // Stock back to the same batch, one VOID history row.
        $this->assertSame(10, $this->qtyOf($makeup));
        $this->assertSame(9, $this->qtyOf($serum));
        $history = InventoryHistory::where('batch_id', $item->batch_id)->where('transaction_type', 'VOID')->get();
        $this->assertCount(1, $history);
        $this->assertSame(3, $history[0]->quantity_change);

        // Sale still PAID, totals from the serum only.
        $sale->refresh();
        $this->assertSame('PAID', $sale->sale_status);
        $this->assertSame('120.00', $sale->subtotal);
        $this->assertSame('108.00', $sale->total_amount);
        $this->assertEquals(0, (float) $sale->discount_amount);

        // Audit VOID row naming the item.
        $audit = AuditLog::where('sale_id', $sale->sale_id)->where('action_type', 'VOID')->get();
        $this->assertCount(1, $audit);
        $this->assertSame('SALES', $audit[0]->module);
        $this->assertSame('Item Test Makeup x3 voided, refund 450.00, new total 108.00. Reason: item entered twice', $audit[0]->details);

        // The day's total leaves the voided item out.
        $summary = app(SaleService::class)->dailySummary($sale->sale_datetime->copy());
        $paid = round((float) Sale::whereBetween('sale_datetime', [$sale->sale_datetime->copy()->startOfDay(), $sale->sale_datetime->copy()->endOfDay()])
            ->where('sale_status', 'PAID')->sum('total_amount'), 2);
        $this->assertSame($paid, $summary['total_sales']);
    }

    public function test_void_without_a_reason_is_refused(): void
    {
        [$sale, , $makeup] = $this->twoItemSale();
        $item = $this->itemOf($sale, $makeup);
        $before = $this->counts();

        $this->voidItem($item, null)->assertSessionHasErrors(['reason' => 'Enter a reason.']);
        $this->voidItem($item, '   ')->assertSessionHasErrors(['reason' => 'Enter a reason.']);
        $this->voidItem($item, 'abc')->assertSessionHasErrors(['reason' => 'The reason must be at least 5 characters.']);

        $this->assertSame($before, $this->counts());
        $this->assertSame('SOLD', $item->fresh()->item_status);
        $this->assertSame('558.00', $sale->fresh()->total_amount);
        $this->assertSame(7, $this->qtyOf($makeup));
    }

    public function test_voiding_the_same_item_twice_is_refused(): void
    {
        [$sale, , $makeup] = $this->twoItemSale();
        $item = $this->itemOf($sale, $makeup);

        $this->voidItem($item, 'first reason')->assertSessionHas('success');
        $after = $this->counts();

        $this->voidItem($item, 'second reason')->assertSessionHas('error', 'This item is already voided.');

        $this->assertSame($after, $this->counts());
        $this->assertSame(10, $this->qtyOf($makeup)); // not 13
        $this->assertSame('first reason', $item->fresh()->void_reason);
        $this->assertSame('108.00', $sale->fresh()->total_amount);
    }

    public function test_voiding_the_last_item_cancels_the_sale(): void
    {
        [$sale, $serum, $makeup] = $this->twoItemSale();

        $this->voidItem($this->itemOf($sale, $makeup))->assertSessionHas('success');
        $this->voidItem($this->itemOf($sale, $serum))
            ->assertSessionHas('success', "Item Test Serum x1 was voided from sale {$sale->receiptNumber()}. No items are left, so the sale is now cancelled.");

        $sale->refresh();
        $this->assertSame('CANCELLED', $sale->sale_status);
        $this->assertSame('All items voided', $sale->void_reason);
        $this->assertNotNull($sale->voided_at);
        $this->assertSame($this->owner->user_id, $sale->voided_by);
        $this->assertSame('0.00', $sale->subtotal);
        $this->assertSame('0.00', $sale->total_amount);
        $this->assertSame(10, $this->qtyOf($serum));
        $this->assertSame(10, $this->qtyOf($makeup));
    }

    public function test_cashier_gets_403_and_nothing_changes(): void
    {
        [$sale, , $makeup] = $this->twoItemSale();
        $item = $this->itemOf($sale, $makeup);
        $before = $this->counts();

        $this->actingAs($this->cashier);
        $this->voidItem($item)->assertForbidden();
        $this->get(route('sales.index', ['void_item' => $item->sale_item_id]))->assertForbidden();

        $this->assertSame($before, $this->counts());
        $this->assertSame('SOLD', $item->fresh()->item_status);
    }

    // ---------- whole-sale void after an item void ----------

    public function test_whole_void_after_an_item_void_returns_stock_only_once(): void
    {
        [$sale, $serum, $makeup] = $this->twoItemSale();
        $this->voidItem($this->itemOf($sale, $makeup))->assertSessionHas('success');

        $this->post(route('sales.void', $sale), ['reason' => 'customer changed mind'])->assertSessionHas('success');

        $this->assertSame('CANCELLED', $sale->fresh()->sale_status);
        $this->assertSame(10, $this->qtyOf($makeup)); // not 13
        $this->assertSame(10, $this->qtyOf($serum));
    }

    // ---------- the page and the receipt ----------

    public function test_page_shows_void_item_card_and_voided_item(): void
    {
        [$sale, , $makeup] = $this->twoItemSale();
        $item = $this->itemOf($sale, $makeup);
        $date = $sale->sale_datetime->format('Y-m-d');

        $this->get(route('sales.index', ['date' => $date, 'void_item' => $item->sale_item_id]))
            ->assertOk()
            ->assertSeeInOrder(["Void item from {$sale->receiptNumber()}", 'SELECTED ITEM', 'Test Makeup x3', 'Amount ₱450.00', 'Confirm void item'])
            ->assertSee('action="'.route('sales.items.void', $item).'"', false);

        $this->voidItem($item);

        $this->get(route('sales.index', ['date' => $date, 'open' => $sale->sale_id]))
            ->assertOk()
            ->assertSee('jr-item-voided', false)
            ->assertSee('Reason: item entered twice')
            ->assertDontSee('id="voidItemForm"', false);

        // A voided item cannot be opened in the card again.
        $this->get(route('sales.index', ['date' => $date, 'void_item' => $item->sale_item_id]))
            ->assertOk()->assertDontSee('id="voidItemForm"', false);
    }

    public function test_receipt_shows_the_item_as_voided(): void
    {
        [$sale, , $makeup] = $this->twoItemSale();
        $this->voidItem($this->itemOf($sale, $makeup));

        $this->get(route('receipts.show', $sale))
            ->assertOk()
            ->assertSee('Test Makeup x3 (VOIDED)')
            ->assertSee('jr-receipt-row-voided', false)
            // Paid 558.00 at first; the makeup (450.00) was voided, so the new total is 108.00.
            ->assertSeeInOrder(['NEW TOTAL', '108.00', 'Refunded (voided items)', '450.00', 'Cash']);
    }

    public function test_receipt_without_voided_items_has_no_refund_line(): void
    {
        [$sale] = $this->twoItemSale();

        $this->get(route('receipts.show', $sale))
            ->assertOk()
            ->assertSeeInOrder(['TOTAL', '558.00'])
            ->assertDontSee('NEW TOTAL')
            ->assertDontSee('Refunded (voided items)');
    }

    public function test_receipt_of_a_sale_cancelled_by_item_voids_refunds_everything(): void
    {
        [$sale, $serum, $makeup] = $this->twoItemSale();
        $this->voidItem($this->itemOf($sale, $makeup));
        $this->voidItem($this->itemOf($sale, $serum));

        $this->get(route('receipts.show', $sale))
            ->assertOk()
            ->assertSee('jr-receipt-void-stamp', false)
            ->assertSeeInOrder(['NEW TOTAL', '0.00', 'Refunded (voided items)', '558.00']);
    }
}
