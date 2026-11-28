<?php

namespace Tests\Feature;

use App\Mail\DailyStockAlertMail;
use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\UserAccount;
use App\Services\AlertService;
use App\Services\SaleService;
use App\Services\StockStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Bug fixes from testing:
// 1. EXPIRED status (06:00 refresh, alert, 08:00 email, Inventory, Alerts page and count)
// 2. POS "Expired" badge   3. no restock of an expired batch + Remove stock (ADJUSTMENT)
// 4. sale_item keeps the product name and number   5. product edit audit shows old -> new values.
// DatabaseTransactions rolls everything back after each test.
class ExpiredStockTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;
    private UserAccount $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pos.near_expiry_days' => 30, 'pos.near_expiry_discount_percent' => 10]);

        $this->owner = $this->makeUser('OWNER');
        $this->cashier = $this->makeUser('CASHIER');
    }

    // ---------- helpers ----------

    private function uniqueName(string $name): string
    {
        return $name.' '.Str::upper(Str::random(5));
    }

    // The only inventory row of a product made with one batch.
    private function stockRow(Product $product): Inventory
    {
        return $product->batches()->first()->inventory()->with('batch')->first();
    }

    // A product whose only batch expired yesterday with 5 pieces left, after the 06:00 refresh ran.
    private function expiredProduct(): Product
    {
        $product = $this->makeProduct($this->uniqueName('Test Old Cream'), 100, [[-1, 5, 1]]);
        $this->artisan('pos:refresh-stock-status')->assertSuccessful();

        return $product;
    }

    // ---------- 1. EXPIRED status ----------

    public function test_status_rule_expired_comes_before_out_of_stock(): void
    {
        $status = app(StockStatusService::class);
        $yesterday = now()->subDay();

        $this->assertSame('EXPIRED', $status->statusFor(5, 1, $yesterday));
        $this->assertSame('EXPIRED', $status->statusFor(1, 5, $yesterday)); // also low, expired still wins
        $this->assertSame('OUT OF STOCK', $status->statusFor(0, 5, $yesterday)); // nothing left to remove
    }

    public function test_refresh_sets_expired_and_opens_one_expired_alert(): void
    {
        $product = $this->expiredProduct();
        $this->artisan('pos:refresh-stock-status')->assertSuccessful(); // a second run makes no duplicate

        $row = $this->stockRow($product);
        $this->assertSame('EXPIRED', $row->stock_status);

        $open = AlertNotification::where('batch_id', $row->batch_id)->whereNull('resolved_at')->get();
        $this->assertCount(1, $open);
        $this->assertSame('EXPIRED', $open[0]->alert_type);
    }

    public function test_alerts_page_and_count_show_expired_alerts(): void
    {
        $product = $this->expiredProduct();
        $count = app(AlertService::class)->openCount();

        $this->actingAs($this->cashier)->get('/alerts')
            ->assertOk()
            ->assertSee("Alerts ({$count})")
            ->assertSee('EXPIRED (', false)
            ->assertSee('<span class="jr-badge-expired align-self-start">EXPIRED</span>', false)
            ->assertSee($product->product_name)
            ->assertSee('one daily email (Brevo, Gmail as backup)');
    }

    public function test_daily_email_lists_expired_alerts(): void
    {
        Mail::fake();
        $product = $this->expiredProduct();
        $batchId = $product->batches()->first()->batch_id;

        $this->artisan('pos:send-daily-alerts')->assertSuccessful();

        Mail::assertSent(DailyStockAlertMail::class, fn (DailyStockAlertMail $mail) => $mail->alerts
            ->contains(fn ($alert) => $alert->batch_id === $batchId && $alert->alert_type === 'EXPIRED'));
        $this->assertNotNull(AlertNotification::where('batch_id', $batchId)->where('alert_type', 'EXPIRED')->value('emailed_at'));
    }

    public function test_inventory_shows_expired_pill_card_and_remove_button(): void
    {
        $product = $this->expiredProduct();
        $row = $this->stockRow($product);

        $this->actingAs($this->owner)->get('/inventory')
            ->assertOk()
            ->assertSee('<span class="jr-badge-expired jr-badge-table">EXPIRED</span>', false)
            ->assertSee('aria-label="EXPIRED"', false)
            ->assertSee(route('inventory.remove.edit', $row->inventory_id));
    }

    // ---------- 2. POS badge ----------

    public function test_pos_shows_expired_badge_and_unavailable(): void
    {
        $product = $this->expiredProduct();

        $html = $this->actingAs($this->cashier)->get('/pos')->assertOk()->getContent();

        // Cut out this product's card and check only that one.
        $start = strpos($html, 'data-search="'.mb_strtolower($product->product_name));
        $card = substr($html, $start, strpos($html, '</article>', $start) - $start);

        $this->assertStringContainsString('>Expired</span>', $card);
        $this->assertStringNotContainsString('Out of stock', $card);
        $this->assertStringContainsString('Unavailable', $card);
    }

    public function test_cashier_adding_expired_product_gets_expired_message(): void
    {
        $expired = $this->expiredProduct();
        $empty = $this->makeProduct($this->uniqueName('Test Empty Lotion'), 90, [[300, 0, 1]]);
        $this->actingAs($this->cashier);

        // Expired pieces left: the expired message, and nothing goes in the cart.
        $this->post(route('pos.cart.add', $expired))
            ->assertSessionHas('error', 'This product has expired and cannot be sold.');
        $this->assertSame([], session('pos_cart', []));

        // Really nothing left: still the out-of-stock message.
        $this->post(route('pos.cart.add', $empty))
            ->assertSessionHas('error', "{$empty->product_name} is out of stock.");
    }

    // ---------- 3. Restock refused, Remove stock ----------

    public function test_restock_of_expired_batch_is_refused_and_nothing_changes(): void
    {
        $row = $this->stockRow($this->expiredProduct());
        $history = InventoryHistory::count();

        $html = $this->actingAs($this->owner)->get(route('inventory.stock.edit', $row))
            ->assertOk()
            ->assertSee('This batch has expired. Add a new batch instead.')
            ->assertDontSee('NEW STOCK') // no preview for a batch that cannot be restocked
            ->getContent();

        // The quantity field and the Save button are both turned off.
        $this->assertMatchesRegularExpression('/<input id="quantity"[^>]*\bdisabled\b/', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*\bdisabled\b[^>]*>Save stock/', $html);

        $this->put(route('inventory.stock.update', $row), ['quantity' => '10'])
            ->assertRedirect(route('inventory.stock.edit', $row))
            ->assertSessionHas('error', 'This batch has expired. Add a new batch instead.');

        $this->assertSame(5, (int) $row->fresh()->quantity_on_hand);
        $this->assertSame($history, InventoryHistory::count());
    }

    public function test_remove_stock_writes_adjustment_history_and_audit(): void
    {
        $row = $this->stockRow($this->expiredProduct());

        $this->actingAs($this->owner)->get(route('inventory.remove.edit', $row))->assertOk()->assertSee('Remove stock');

        $this->put(route('inventory.remove.update', $row), ['quantity' => '5', 'reason' => 'Expired'])
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success');

        $fresh = $row->fresh();
        $this->assertSame(0, (int) $fresh->quantity_on_hand);
        $this->assertSame('OUT OF STOCK', $fresh->stock_status);

        $history = InventoryHistory::where('inventory_id', $row->inventory_id)->latest('transaction_id')->first();
        $this->assertSame('ADJUSTMENT', $history->transaction_type);
        $this->assertSame(-5, (int) $history->quantity_change);
        $this->assertSame($this->owner->user_id, $history->user_id);

        $audit = AuditLog::where('user_id', $this->owner->user_id)->latest('audit_log_id')->first();
        $this->assertSame('INVENTORY_UPDATE', $audit->action_type);
        $this->assertSame("Removed 5 from {$row->batch->batch_number} (5 to 0), reason: Expired", $audit->details);
    }

    public function test_remove_stock_needs_a_valid_reason_and_not_more_than_on_hand(): void
    {
        $row = $this->stockRow($this->makeProduct('Test Toner', 90, [[300, 4, 1]]));
        $this->actingAs($this->owner);

        $this->from(route('inventory.remove.edit', $row))
            ->put(route('inventory.remove.update', $row), ['quantity' => '1'])
            ->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->from(route('inventory.remove.edit', $row))
            ->put(route('inventory.remove.update', $row), ['quantity' => '1', 'reason' => 'Lost'])
            ->assertSessionHasErrors('reason');
        $this->put(route('inventory.remove.update', $row), ['quantity' => '9', 'reason' => 'Damaged'])
            ->assertSessionHas('error', 'Only 4 pieces are in this batch. You cannot remove 9.');

        $this->assertSame(4, (int) $row->fresh()->quantity_on_hand);
    }

    public function test_cashier_cannot_remove_stock(): void
    {
        $row = $this->stockRow($this->makeProduct('Test Toner', 90, [[300, 4, 1]]));
        $this->actingAs($this->cashier);

        $this->get(route('inventory.remove.edit', $row))->assertForbidden();
        $this->put(route('inventory.remove.update', $row), ['quantity' => '1', 'reason' => 'Damaged'])->assertForbidden();

        $this->assertSame(4, (int) $row->fresh()->quantity_on_hand);
    }

    // ---------- 4. Sale item keeps the product name ----------

    public function test_sale_saves_product_name_and_receipt_and_records_keep_it_after_rename(): void
    {
        $product = $this->makeProduct('Test Rose Serum', 120, [[300, 10, 2]]);
        $sale = app(SaleService::class)->pay($this->owner, [$product->product_id => 2], 500);

        $item = SaleItem::where('sale_id', $sale->sale_id)->firstOrFail();
        $this->assertSame('Test Rose Serum', $item->product_name);
        $this->assertSame($product->product_number, $item->product_number);

        // Rename the product afterwards: the old sale must still show the name it was sold under.
        $product->update(['product_name' => 'Test Renamed Serum']);
        $this->actingAs($this->owner);

        $this->get(route('receipts.show', $sale))->assertOk()
            ->assertSee('Test Rose Serum')
            ->assertSee('2 x 120.00')
            ->assertDontSee('Test Renamed Serum');
        $this->get(route('sales.index', ['date' => $sale->sale_datetime->format('Y-m-d')]))->assertOk()
            ->assertSee('Test Rose Serum')
            ->assertDontSee('Test Renamed Serum');
    }

    // ---------- 5. Product edit audit: old -> new ----------

    public function test_product_edit_audit_shows_old_and_new_values(): void
    {
        $product = $this->makeProduct('Test Serum', 120, [[300, 10, 2]]);
        $oldCategory = $product->category->category_name;
        $newCategory = Category::create(['category_name' => $this->uniqueName('Test Lotions')]);

        $this->actingAs($this->owner)->put(route('products.update', $product), [
            'product_number' => $product->product_number,
            'product_name' => 'Test Rose Serum',
            'category_id' => $newCategory->category_id,
            'supplier_id' => $product->supplier_id,
            'cost_price' => '90',
            'unit_price' => $product->unit_price,
        ])->assertRedirect(route('inventory.index'));

        $audit = AuditLog::where('user_id', $this->owner->user_id)->latest('audit_log_id')->first();
        $this->assertSame(
            "Edited product Test Rose Serum {$product->product_number}: name Test Serum -> Test Rose Serum, "
            ."category {$oldCategory} -> {$newCategory->category_name}, cost price 1.00 -> 90.00",
            $audit->details,
        );
    }
}
