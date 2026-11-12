<?php

namespace Tests\Feature;

use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use App\Services\AuditService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Void a Sale (screen 8, extra 7): T12 and T13 from docs/06-testing.md, plus: cashier 403,
// a forced error in the middle saves nothing, stock status and alerts after a void, and the page / receipt.
// Sales are made with the real SaleService::pay(), so the stock, history and alerts are real.
// DatabaseTransactions rolls everything back after each test.
class VoidTest extends TestCase
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

    // A cashier sells: $cart = [product_id => quantity]. Enough cash is always given.
    private function sell(array $cart): Sale
    {
        return app(SaleService::class)->pay($this->cashier, $cart, 100000);
    }

    // Press "Confirm void" with this reason.
    private function void(Sale $sale, ?string $reason = 'wrong item entered')
    {
        return $this->post(route('sales.void', $sale), $reason === null ? [] : ['reason' => $reason]);
    }

    // The Sales Records page of the sale's day.
    private function salesPage(Sale $sale): string
    {
        return route('sales.index', ['date' => $sale->sale_datetime->format('Y-m-d')]);
    }

    // Row counts of every table a void writes, to prove "nothing changed".
    private function counts(): array
    {
        return [
            'sale' => Sale::count(),
            'sale_item' => SaleItem::count(),
            'inventory_history' => InventoryHistory::count(),
            'audit_log' => AuditLog::count(),
            'alert_notification' => AlertNotification::count(),
            'open_alerts' => AlertNotification::whereNull('resolved_at')->count(),
        ];
    }

    // [quantity_on_hand, stock_status] of each batch, by batch_id.
    private function stockOf(Product $product): array
    {
        return $product->batches()->with('inventory')->orderBy('batch_id')->get()
            ->map(fn ($batch) => [$batch->inventory->quantity_on_hand, $batch->inventory->stock_status])->all();
    }

    private function assertStillPaid(Sale $sale): void
    {
        $sale->refresh();
        $this->assertSame('PAID', $sale->sale_status);
        $this->assertNull($sale->void_reason);
        $this->assertNull($sale->voided_at);
        $this->assertNull($sale->voided_by);
    }

    // ---------- T12: void a PAID sale with a reason ----------

    public function test_t12_void_paid_sale_saves_everything_and_returns_stock(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 3]);
        $this->assertSame([[7, 'OKAY']], $this->stockOf($makeup));
        $itemCount = $sale->items()->count();

        $this->void($sale)
            ->assertRedirect($this->salesPage($sale))
            ->assertSessionHas('success', "Sale {$sale->receiptNumber()} was voided.");

        // Sale CANCELLED with reason, time and the owner. Not deleted, items kept.
        $sale->refresh();
        $this->assertSame('CANCELLED', $sale->sale_status);
        $this->assertSame('wrong item entered', $sale->void_reason);
        $this->assertNotNull($sale->voided_at);
        $this->assertSame($this->owner->user_id, $sale->voided_by);
        $this->assertSame($itemCount, $sale->items()->count());

        // Stock back to 10.
        $this->assertSame([[10, 'OKAY']], $this->stockOf($makeup));

        // History: one VOID row, positive, by the owner.
        $history = InventoryHistory::where('batch_id', $makeup->batches->first()->batch_id)
            ->where('transaction_type', 'VOID')->get();
        $this->assertCount(1, $history);
        $this->assertSame(3, $history[0]->quantity_change);
        $this->assertSame($this->owner->user_id, $history[0]->user_id);

        // Audit VOID row.
        $audit = AuditLog::where('sale_id', $sale->sale_id)->where('action_type', 'VOID')->get();
        $this->assertCount(1, $audit);
        $this->assertSame('SALES', $audit[0]->module);
        $this->assertSame($sale->receiptNumber(), $audit[0]->transaction_reference);
        $this->assertSame('Reason: wrong item entered', $audit[0]->details);
        $this->assertSame($this->owner->user_id, $audit[0]->user_id);
    }

    public function test_t12_fefo_split_sale_returns_stock_to_each_batch(): void
    {
        // B0: 300 days, 5 pcs. B1: 100 days, 3 pcs (sold first). Selling 5 takes 3 from B1 and 2 from B0.
        $toner = $this->makeProduct('Test Toner', 100, [[300, 5, 1], [100, 3, 1]]);
        $sale = $this->sell([$toner->product_id => 5]);
        $this->assertSame([[3, 'OKAY'], [0, 'OUT OF STOCK']], $this->stockOf($toner));

        $this->void($sale)->assertSessionHas('success');

        $this->assertSame([[5, 'OKAY'], [3, 'OKAY']], $this->stockOf($toner));
        $changes = InventoryHistory::whereIn('batch_id', $toner->batches->pluck('batch_id'))
            ->where('transaction_type', 'VOID')->orderBy('batch_id')->pluck('quantity_change')->all();
        $this->assertSame([2, 3], $changes);
    }

    public function test_longest_reason_247_fits_in_full_in_the_audit_log(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 1]);
        $reason = str_repeat('a', 247);

        $this->void($sale, $reason)->assertSessionHas('success');

        $this->assertSame($reason, $sale->fresh()->void_reason);
        $details = AuditLog::where('sale_id', $sale->sale_id)->where('action_type', 'VOID')->value('details');
        $this->assertSame('Reason: '.$reason, $details); // 8 + 247 = 255 characters, nothing cut
    }

    // ---------- T13: no reason, or void twice ----------

    public function test_t13_void_without_a_good_reason_is_refused(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 2]);
        $before = $this->counts();

        $this->void($sale, null)->assertSessionHasErrors(['reason' => 'Enter a reason.']);
        $this->void($sale, '     ')->assertSessionHasErrors(['reason' => 'Enter a reason.']); // only spaces
        $this->void($sale, 'abcd')->assertSessionHasErrors(['reason' => 'The reason must be at least 5 characters.']);
        $this->void($sale, str_repeat('a', 248))->assertSessionHasErrors(['reason' => 'The reason can have at most 247 characters.']);

        $this->assertSame($before, $this->counts());
        $this->assertSame([[8, 'OKAY']], $this->stockOf($makeup));
        $this->assertStillPaid($sale);
    }

    public function test_t13_voiding_the_same_sale_twice_is_refused_and_changes_nothing(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 2]);

        $this->void($sale, 'first reason')->assertSessionHas('success');
        $after = $this->counts();
        $firstVoid = $sale->fresh()->only(['sale_status', 'void_reason', 'voided_at', 'voided_by']);

        $this->void($sale, 'second reason')
            ->assertRedirect($this->salesPage($sale))
            ->assertSessionHas('error', "Sale {$sale->receiptNumber()} is already voided.");

        $this->assertSame($after, $this->counts());
        $this->assertSame([[10, 'OKAY']], $this->stockOf($makeup)); // not 12
        $this->assertEquals($firstVoid, $sale->fresh()->only(['sale_status', 'void_reason', 'voided_at', 'voided_by']));
    }

    // ---------- access ----------

    public function test_cashier_gets_403_and_nothing_changes(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 1]);
        $before = $this->counts();

        $this->actingAs($this->cashier);
        $this->void($sale)->assertForbidden();
        $this->get($this->salesPage($sale).'&void='.$sale->sale_id)->assertForbidden();

        $this->assertSame($before, $this->counts());
        $this->assertStillPaid($sale);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 1]);

        auth()->logout();
        $this->void($sale)->assertRedirect('/login');
        $this->assertStillPaid($sale);
    }

    public function test_unknown_sale_gives_404(): void
    {
        $this->post('/sales/999999999/void', ['reason' => 'wrong item entered'])->assertNotFound();
    }

    // ---------- a forced error in the middle saves nothing ----------

    public function test_forced_error_in_the_middle_saves_nothing(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 6, 3]]);
        $sale = $this->sell([$makeup->product_id => 6]); // OUT OF STOCK, one open alert
        $before = $this->counts();
        $stockBefore = $this->stockOf($makeup);

        // The audit row is the LAST step: the sale, stock, history and alerts were already written when it fails.
        $this->mock(AuditService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new RuntimeException('Forced error')));

        $this->void($sale)
            ->assertRedirect($this->salesPage($sale))
            ->assertSessionHas('error', 'Void failed. Nothing was changed. Please try again.');

        $this->assertSame($before, $this->counts());
        $this->assertSame($stockBefore, $this->stockOf($makeup));
        $this->assertSame([[0, 'OUT OF STOCK']], $stockBefore);
        $this->assertStillPaid($sale);
    }

    // ---------- stock status and alerts after a void ----------

    public function test_void_after_out_of_stock_sets_okay_and_resolves_the_alert(): void
    {
        $toner = $this->makeProduct('Test Toner', 100, [[300, 6, 3]]);
        $batchId = $toner->batches->first()->batch_id;
        $open = fn () => AlertNotification::where('batch_id', $batchId)->whereNull('resolved_at');

        $sale = $this->sell([$toner->product_id => 6]);
        $this->assertSame([[0, 'OUT OF STOCK']], $this->stockOf($toner));
        $this->assertSame(['OUT OF STOCK'], $open()->pluck('alert_type')->all());

        $this->void($sale)->assertSessionHas('success');

        $this->assertSame([[6, 'OKAY']], $this->stockOf($toner));
        $this->assertSame(0, $open()->count());
        $this->assertSame(1, AlertNotification::where('batch_id', $batchId)->whereNotNull('resolved_at')->count());
    }

    public function test_void_after_low_stock_sets_okay_and_resolves_the_alert(): void
    {
        $toner = $this->makeProduct('Test Toner', 100, [[300, 6, 3]]);
        $batchId = $toner->batches->first()->batch_id;

        $sale = $this->sell([$toner->product_id => 3]); // 3 left = reorder level -> LOW STOCK
        $this->assertSame([[3, 'LOW STOCK']], $this->stockOf($toner));
        $this->assertSame(1, AlertNotification::where('batch_id', $batchId)->where('alert_type', 'LOW STOCK')->whereNull('resolved_at')->count());

        $this->void($sale)->assertSessionHas('success');

        $this->assertSame([[6, 'OKAY']], $this->stockOf($toner));
        $this->assertSame(0, AlertNotification::where('batch_id', $batchId)->whereNull('resolved_at')->count());
    }

    public function test_void_that_stays_low_keeps_one_open_alert(): void
    {
        // 10 pcs, reorder level 8. Sell 4 (6 left, LOW), sell 1 more (5 left, LOW), void the second: 6 left, still LOW.
        $toner = $this->makeProduct('Test Toner', 100, [[300, 10, 8]]);
        $batchId = $toner->batches->first()->batch_id;
        $this->sell([$toner->product_id => 4]);
        $second = $this->sell([$toner->product_id => 1]);

        $this->void($second)->assertSessionHas('success');

        $this->assertSame([[6, 'LOW STOCK']], $this->stockOf($toner));
        $this->assertSame(1, AlertNotification::where('batch_id', $batchId)->whereNull('resolved_at')->count()); // no duplicate
    }

    // ---------- the page and the receipt ----------

    public function test_void_card_shows_the_selected_sale(): void
    {
        config(['pos.near_expiry_days' => 30]);
        $serum = $this->makeProduct('Test Serum', 120, [[10, 10, 5]]);  // near expiry -> 10% off
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$serum->product_id => 1, $makeup->product_id => 3]); // 108 + 450 = 558.00

        $this->get($this->salesPage($sale).'&void='.$sale->sale_id)
            ->assertOk()
            ->assertSeeInOrder([
                "Void sale {$sale->receiptNumber()}",
                'SELECTED SALE',
                $sale->sale_datetime->format('m/d/Y H:i'),
                'Test Serum x1, Test Makeup x3',
                'Total ₱558.00',
                'Reason *',
                'Stock will be returned to inventory. This is saved in the audit log.',
                'Cancel',
                'Confirm void',
            ])
            ->assertSee('action="'.route('sales.void', $sale).'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('jr-btn-void-on', false);
    }

    public function test_no_void_card_without_a_paid_sale(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 1]);
        $this->void($sale)->assertSessionHas('success');

        // Already voided, unknown id: the page opens without the card.
        $this->get($this->salesPage($sale).'&void='.$sale->sale_id)->assertOk()->assertDontSee('id="voidForm"', false);
        $this->get($this->salesPage($sale).'&void=999999999')->assertOk()->assertDontSee('id="voidForm"', false);
        $this->get($this->salesPage($sale))->assertOk()->assertDontSee('id="voidForm"', false);
    }

    public function test_reason_error_reopens_the_card(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 1]);
        $card = $this->salesPage($sale).'&void='.$sale->sale_id;

        $this->from($card)->void($sale, 'abc')->assertRedirect($card);

        $this->get($card)
            ->assertSee('The reason must be at least 5 characters.')
            ->assertSee('>abc</textarea>', false); // what the owner typed is kept
    }

    public function test_after_void_the_sale_is_grey_out_of_totals_and_the_receipt_is_stamped(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $sale = $this->sell([$makeup->product_id => 2]);

        $this->followingRedirects()->void($sale)
            ->assertOk()
            ->assertSee("Sale {$sale->receiptNumber()} was voided.")
            ->assertSee('jr-sales-voided', false)
            ->assertSee('VOIDED')
            ->assertSee('Reason: wrong item entered');

        // The day's totals leave the voided sale out.
        $date = $sale->sale_datetime->copy()->startOfDay();
        $summary = app(SaleService::class)->dailySummary($date);
        $paidTotal = round((float) Sale::whereBetween('sale_datetime', [$date, $date->copy()->endOfDay()])
            ->where('sale_status', 'PAID')->sum('total_amount'), 2);
        $this->assertSame($paidTotal, $summary['total_sales']);
        $this->assertGreaterThanOrEqual(1, $summary['cancelled_count']);

        $this->get(route('receipts.show', $sale))
            ->assertOk()
            ->assertSee('jr-receipt-void-stamp', false)
            ->assertSee('VOIDED')
            ->assertSee('Reason: wrong item entered');
    }
}
