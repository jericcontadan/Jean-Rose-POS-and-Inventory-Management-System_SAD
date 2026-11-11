<?php

namespace Tests\Feature;

use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\StockStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// T07, T08, T09, T23 from docs/06-testing.md, plus: forced error saves nothing, the mockup numbers,
// double tap, browser totals ignored, FEFO split at payment, and who may open a receipt.
class PayNowTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed rules for the tests, whatever .env says.
        config(['pos.near_expiry_days' => 30, 'pos.near_expiry_discount_percent' => 10]);

        $this->cashier = $this->makeUser('CASHIER');
        $this->actingAs($this->cashier);
    }

    // ---------- helpers ----------

    // Put $quantity pieces of a product in the cart, the same way the POS buttons do.
    private function addToCart(Product $product, int $quantity): void
    {
        $this->post("/pos/cart/{$product->product_id}");
        for ($i = 1; $i < $quantity; $i++) {
            $this->post("/pos/cart/{$product->product_id}/increase");
        }
    }

    // Open the POS (this makes the one-time pay token) and return the token.
    private function payToken(): string
    {
        $this->get('/pos')->assertOk();

        return session('pos_pay_token');
    }

    // Press "Confirm payment".
    private function pay(string $cash, array $extra = [], ?string $token = null)
    {
        return $this->post('/pos/pay', array_merge([
            'payment_method' => 'CASH',
            'cash_received' => $cash,
            'pay_token' => $token ?? $this->payToken(),
        ], $extra));
    }

    // Row counts of every table Pay Now writes, to prove "nothing saved".
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

    private function stockOf(Product $product): array
    {
        return $product->batches()->with('inventory')->orderBy('batch_id')->get()
            ->map(fn ($batch) => [$batch->inventory->quantity_on_hand, $batch->inventory->stock_status])->all();
    }

    private function cart(): array
    {
        return session('pos_cart', []);
    }

    // The two products from the mockup: Serum ₱120 (near expiry) x1 and Makeup ₱150 x3 = ₱558.00.
    private function mockupCart(): array
    {
        $serum = $this->makeProduct('Test Serum', 120, [[10, 10, 5]]);   // expires in 10 days -> 10% off
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($serum, 1);
        $this->addToCart($makeup, 3);

        return [$serum, $makeup];
    }

    // ---------- T07: enough cash ----------

    public function test_t07_pay_with_enough_cash_saves_everything(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 2);

        $response = $this->pay('500');

        $sale = Sale::latest('sale_id')->first();
        $response->assertRedirect(route('receipts.show', $sale));

        // Sale PAID with cash and change.
        $this->assertSame('PAID', $sale->sale_status);
        $this->assertSame($this->cashier->user_id, $sale->user_id);
        $this->assertSame('300.00', $sale->total_amount);
        $this->assertSame('500.00', $sale->cash_received);
        $this->assertSame('200.00', $sale->change_amount);

        // Stock reduced: 10 -> 8.
        $this->assertSame([[8, 'OKAY']], $this->stockOf($makeup));

        // History SALE, negative.
        $history = InventoryHistory::where('batch_id', $makeup->batches->first()->batch_id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(-2, $history[0]->quantity_change);
        $this->assertSame('SALE', $history[0]->transaction_type);

        // Audit SALE with the receipt number.
        $audit = AuditLog::where('sale_id', $sale->sale_id)->first();
        $this->assertSame('SALE', $audit->action_type);
        $this->assertSame('SALES', $audit->module);
        $this->assertSame($sale->receiptNumber(), $audit->transaction_reference);
        $this->assertMatchesRegularExpression('/^S-\d{4,}$/', $audit->transaction_reference);

        // Cart is empty for the next customer.
        $this->assertSame([], $this->cart());
    }

    // ---------- the numbers from the mockup ----------

    public function test_mockup_numbers_570_minus_12_is_558_change_42(): void
    {
        [$serum, $makeup] = $this->mockupCart();

        $this->pay('600.00');

        $sale = Sale::latest('sale_id')->first();
        $this->assertSame('570.00', $sale->subtotal);
        $this->assertSame('558.00', $sale->total_amount);
        $this->assertSame('0.00', $sale->discount_percent); // sale-level discount not used
        $this->assertSame('600.00', $sale->cash_received);
        $this->assertSame('42.00', $sale->change_amount);

        $items = $sale->items()->orderBy('sale_item_id')->get();
        $this->assertCount(2, $items);
        $this->assertSame([1, '120.00', '108.00', '10.00'], [$items[0]->quantity, $items[0]->unit_price, $items[0]->line_amount, $items[0]->discount_percent]);
        $this->assertSame([3, '150.00', '450.00', '0.00'], [$items[1]->quantity, $items[1]->unit_price, $items[1]->line_amount, $items[1]->discount_percent]);

        $this->assertSame('Items 2, total 558.00, cash 600.00, change 42.00', AuditLog::where('sale_id', $sale->sale_id)->value('details'));

        // The receipt shows the same numbers.
        $this->get(route('receipts.show', $sale))
            ->assertOk()
            ->assertSee('SALES RECEIPT')
            ->assertSee($sale->receiptNumber())
            ->assertSee($sale->sale_datetime->format('m/d/Y H:i'))
            ->assertSee($this->cashier->username)
            ->assertSee('Test Serum x1 (-10%)')
            ->assertSee('108.00')
            ->assertSee('Test Makeup x3')
            ->assertSee('450.00')
            ->assertSeeInOrder(['Subtotal', '570.00', 'Discount', '-12.00', 'TOTAL', '558.00', 'Cash', '600.00', 'Change', '42.00'])
            ->assertSee('Thank you!')
            ->assertSee('Print receipt')
            ->assertSee('New order');
    }

    // ---------- T08: cash less than total ----------

    public function test_t08_cash_less_than_total_is_refused_and_nothing_saved(): void
    {
        [$serum, $makeup] = $this->mockupCart();
        $before = $this->counts();
        $cartBefore = $this->cart();

        $this->pay('500')
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error', 'Cash received is less than the total of ₱558.00.');

        $this->assertSame($before, $this->counts());
        $this->assertSame([[10, 'OKAY']], $this->stockOf($serum)); // untouched (status as created)
        $this->assertSame([[10, 'OKAY']], $this->stockOf($makeup));
        $this->assertSame($cartBefore, $this->cart()); // the cashier can fix the cash and try again
    }

    public function test_t08_cash_must_be_a_number(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 1);
        $before = $this->counts();

        $this->pay('abc')->assertSessionHasErrors('cash_received');
        $this->pay('')->assertSessionHasErrors('cash_received');
        $this->pay('200.555')->assertSessionHasErrors('cash_received');

        $this->assertSame($before, $this->counts());
    }

    // ---------- T09: stock changes after the item was put in the cart ----------

    public function test_t09_stock_changed_mid_sale_rolls_back_with_error(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 3);
        $token = $this->payToken();

        // Another till sells 9 pieces in the meantime: only 1 is left.
        Inventory::where('batch_id', $makeup->batches->first()->batch_id)->update(['quantity_on_hand' => 1]);
        $before = $this->counts();

        $this->pay('1000', [], $token)
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error', 'Stock changed: only 1 Test Makeup left. Please check the order.');

        $this->assertSame($before, $this->counts());
        $this->assertSame(1, $this->stockOf($makeup)[0][0]);
    }

    // ---------- a forced error in the middle saves nothing ----------

    public function test_forced_error_in_the_middle_saves_nothing(): void
    {
        [$serum, $makeup] = $this->mockupCart();
        $before = $this->counts();

        // The audit row is the LAST step: sale, sale_item, stock, history and alerts were already written when it fails.
        $this->mock(AuditService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new RuntimeException('Forced error')));

        $this->pay('600')
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error', 'Payment failed. Nothing was saved. Please try again.');

        $this->assertSame($before, $this->counts());
        $this->assertSame([[10, 'OKAY']], $this->stockOf($serum));
        $this->assertSame([[10, 'OKAY']], $this->stockOf($makeup));
        $this->assertNotEmpty($this->cart());
    }

    // ---------- T23: alert tracking ----------

    public function test_t23_alerts_open_once_switch_type_and_resolve(): void
    {
        // 6 pieces, reorder level 3, expiry far away.
        $toner = $this->makeProduct('Test Toner', 100, [[300, 6, 3]]);
        $batchId = $toner->batches->first()->batch_id;
        $open = fn (string $type) => AlertNotification::where('batch_id', $batchId)->where('alert_type', $type)->whereNull('resolved_at')->count();

        // Sell 3 -> 3 left -> LOW STOCK: one open LOW STOCK alert.
        $this->addToCart($toner, 3);
        $this->pay('300');
        $this->assertSame([[3, 'LOW STOCK']], $this->stockOf($toner));
        $this->assertSame(1, $open('LOW STOCK'));

        // Sell 1 more -> still LOW STOCK: no duplicate row.
        $this->addToCart($toner, 1);
        $this->pay('100');
        $this->assertSame(1, $open('LOW STOCK'));
        $this->assertSame(1, AlertNotification::where('batch_id', $batchId)->count());

        // Sell the last 2 -> OUT OF STOCK: the LOW alert is resolved, one OUT alert is open.
        $this->addToCart($toner, 2);
        $this->pay('200');
        $this->assertSame([[0, 'OUT OF STOCK']], $this->stockOf($toner));
        $this->assertSame(0, $open('LOW STOCK'));
        $this->assertSame(1, $open('OUT OF STOCK'));

        // Back to OKAY (the restock screen comes later, so set the stock here): every open alert gets resolved_at.
        $inventory = Inventory::with('batch')->where('batch_id', $batchId)->first();
        $inventory->quantity_on_hand = 20;
        app(StockStatusService::class)->refresh($inventory);
        app(AlertService::class)->sync($inventory);

        $this->assertSame('OKAY', $inventory->fresh()->stock_status);
        $this->assertSame(0, AlertNotification::where('batch_id', $batchId)->whereNull('resolved_at')->count());
        $this->assertSame(2, AlertNotification::where('batch_id', $batchId)->whereNotNull('resolved_at')->count());
    }

    // ---------- double tap, old form, browser totals ----------

    public function test_double_tap_makes_only_one_sale(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 1);
        $token = $this->payToken();
        $before = Sale::count();

        $first = $this->pay('200', [], $token);
        $sale = Sale::latest('sale_id')->first();
        $first->assertRedirect(route('receipts.show', $sale));

        // Same form sent again: no second sale, the same receipt is shown.
        $this->pay('200', [], $token)
            ->assertRedirect(route('receipts.show', $sale))
            ->assertSessionHas('success', 'This payment was already saved.');

        $this->assertSame($before + 1, Sale::count());
        $this->assertSame(9, $this->stockOf($makeup)[0][0]);
    }

    public function test_unknown_token_is_refused(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 1);
        $this->payToken();
        $before = $this->counts();

        $this->pay('200', [], 'not-the-right-token')
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error');

        $this->assertSame($before, $this->counts());
    }

    public function test_totals_sent_by_the_browser_are_ignored(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 2);

        $this->pay('300', ['total' => '1.00', 'total_amount' => '1.00', 'subtotal' => '1.00', 'unit_price' => '0.50']);

        $sale = Sale::latest('sale_id')->first();
        $this->assertSame('300.00', $sale->subtotal);
        $this->assertSame('300.00', $sale->total_amount);
        $this->assertSame('0.00', $sale->change_amount);
    }

    public function test_zero_discount_shows_without_minus_sign(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]); // not near expiry: no discount
        $this->addToCart($makeup, 1);

        // Order panel: no discount line at all when there is no discount.
        $this->get('/pos')->assertOk()->assertDontSee('Near-expiry discount (auto)');

        $this->pay('150');
        $sale = Sale::latest('sale_id')->first();

        // Receipt: "Discount 0.00", never "-0.00".
        $this->get(route('receipts.show', $sale))
            ->assertOk()
            ->assertSeeInOrder(['Discount', '0.00', 'TOTAL'])
            ->assertDontSee('-0.00');
    }

    public function test_empty_order_cannot_be_paid(): void
    {
        $before = $this->counts();

        $this->pay('100')->assertSessionHas('error', 'The order is empty.');

        $this->assertSame($before, $this->counts());
    }

    // ---------- FEFO at payment ----------

    public function test_fefo_split_makes_one_row_per_batch(): void
    {
        // Batch B0: 300 days, 5 pcs. Batch B1: 100 days, 3 pcs (sold first).
        $toner = $this->makeProduct('Test Toner', 100, [[300, 5, 1], [100, 3, 1]]);
        $this->addToCart($toner, 5);

        $this->pay('500');

        $sale = Sale::latest('sale_id')->first();
        $items = $sale->items()->with('batch')->orderBy('sale_item_id')->get();
        $this->assertSame([['B1', 3], ['B0', 2]], $items->map(fn ($i) => [$i->batch->batch_number, $i->quantity])->all());
        $this->assertSame([[3, 'OKAY'], [0, 'OUT OF STOCK']], $this->stockOf($toner)); // B0 then B1 by batch_id
        $this->assertSame(2, InventoryHistory::whereIn('batch_id', $items->pluck('batch_id'))->where('transaction_type', 'SALE')->count());

        // The receipt joins the two rows into one line.
        $this->get(route('receipts.show', $sale))->assertSee('Test Toner x5')->assertSee('500.00');
    }

    // ---------- who may open a receipt ----------

    public function test_receipt_access(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 1);
        $this->pay('150');
        $sale = Sale::latest('sale_id')->first();

        // Another cashier: 403. The owner: allowed.
        $this->actingAs($this->makeUser('CASHIER'))->get(route('receipts.show', $sale))->assertForbidden();
        $this->actingAs($this->makeUser('OWNER'))->get(route('receipts.show', $sale))->assertOk();

        // Not logged in: sent to login.
        auth()->logout();
        $this->get(route('receipts.show', $sale))->assertRedirect('/login');
    }

    public function test_pay_now_opens_the_payment_box(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->addToCart($makeup, 1);

        $this->get('/pos')
            ->assertOk()
            ->assertSee('data-bs-target="#paymentModal"', false)
            ->assertSee('Confirm payment')
            ->assertSee('Cash received *')
            ->assertSee('name="pay_token"', false)
            ->assertSee('inputmode="decimal"', false); // cash has centavos, so the keyboard needs a decimal point
    }
}
