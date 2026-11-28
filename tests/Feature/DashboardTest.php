<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Owner Dashboard, and "only the cashier sells" (Team 4's DFD).
// Sales go through the real POS routes as the cashier; voids go through Sales Records as the owner.
// The test database already has rows, so the numbers are compared as "after minus before".
class DashboardTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;
    private UserAccount $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed rules for the tests, whatever .env says. Empty category list = every category qualifies.
        config([
            'pos.near_expiry_days' => 30,
            'pos.near_expiry_discount_percent' => 10,
            'pos.senior_pwd_discount_percent' => 20,
            'pos.senior_pwd_category_ids' => [],
        ]);

        $this->owner = $this->makeUser('OWNER');
        $this->cashier = $this->makeUser('CASHIER');
    }

    // ---------- helpers ----------

    // The cashier sells: put the items in the cart, open the POS (one-time token) and press "Confirm payment".
    // $lines: [[product, quantity], ...]. Returns the saved sale.
    private function sell(array $lines, string $cash, array $discount = []): Sale
    {
        $this->actingAs($this->cashier);
        foreach ($lines as [$product, $quantity]) {
            $this->post("/pos/cart/{$product->product_id}");
            for ($i = 1; $i < $quantity; $i++) {
                $this->post("/pos/cart/{$product->product_id}/increase");
            }
        }
        $this->get('/pos')->assertOk();
        $this->post('/pos/pay', array_merge([
            'payment_method' => 'CASH',
            'cash_received' => $cash,
            'pay_token' => session('pos_pay_token'),
        ], $discount))->assertRedirect();

        return Sale::where('user_id', $this->cashier->user_id)->latest('sale_id')->firstOrFail();
    }

    private function itemOf(Sale $sale, Product $product): SaleItem
    {
        return $sale->items()->whereIn('batch_id', $product->batches->pluck('batch_id'))->firstOrFail();
    }

    // The numbers the owner's Dashboard shows right now.
    private function dashboard(): array
    {
        return $this->actingAs($this->owner)->get('/dashboard')->assertOk()->viewData('dashboard');
    }

    // ---------- tests ----------

    public function test_owner_gets_403_on_pos_cart_and_payment(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $salesBefore = Sale::count();
        $this->actingAs($this->owner);

        $this->get('/pos')->assertForbidden();
        $this->post("/pos/cart/{$makeup->product_id}")->assertForbidden();
        $this->post("/pos/cart/{$makeup->product_id}/increase")->assertForbidden();
        $this->delete('/pos/cart')->assertForbidden();
        $this->post('/pos/pay', ['payment_method' => 'CASH', 'cash_received' => '500', 'pay_token' => 'x'])->assertForbidden();

        // Nothing was put in a cart and nothing was sold.
        $this->assertSame([], session('pos_cart', []));
        $this->assertSame($salesBefore, Sale::count());
    }

    public function test_owner_lands_on_dashboard_after_login(): void
    {
        $this->post('/login', ['username' => $this->owner->username, 'password' => 'secret123'])
            ->assertRedirect('/dashboard');

        // Dashboard tab instead of POS, and "/" also opens the Dashboard.
        $this->get('/dashboard')->assertOk()
            ->assertSee('Dashboard')->assertSee('Sales Records')->assertSee('Inventory')->assertSee('Audit Log')
            ->assertDontSee('>POS</a>', false);
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_cashier_lands_on_pos_and_gets_403_on_dashboard(): void
    {
        $this->post('/login', ['username' => $this->cashier->username, 'password' => 'secret123'])
            ->assertRedirect('/pos');

        $this->get('/pos')->assertOk()->assertDontSee('Dashboard')->assertDontSee('Sales Records');
        $this->get('/')->assertRedirect('/pos');
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_dashboard_numbers_match_sales_records(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 50, 2]]);
        $toner = $this->makeProduct('Test Toner', 200, [[300, 50, 2]]);
        $before = $this->dashboard();

        // A: Senior sale, 3 x 150 = 450, -20% = 360 (cash 400, change 40).
        $a = $this->sell([[$makeup, 3]], '400', ['discount_type' => 'SENIOR', 'discount_id_number' => '12345', 'discount_customer_name' => 'Juan Cruz']);
        // B: 150 + 200 = 350 (cash 500). The owner voids the toner later: refund 200, B stays PAID at 150.
        $b = $this->sell([[$makeup, 1], [$toner, 1]], '500');
        // C: 2 x 150 = 300 (cash 300). The owner voids the whole sale later: refund 300.
        $c = $this->sell([[$makeup, 2]], '300');

        $this->actingAs($this->owner);
        $this->post(route('sales.items.void', $this->itemOf($b, $toner)), ['reason' => 'customer returned it'])->assertSessionHas('success');
        $this->post(route('sales.void', $c), ['reason' => 'customer changed mind'])->assertSessionHas('success');

        $after = $this->dashboard();

        // 1. "Today" on the Dashboard is exactly the Sales Records summary for today.
        $records = $this->get('/sales')->assertOk()->viewData('summary');
        $this->assertEquals($records, $after['today']);

        // 2. Only PAID sales and SOLD items count: A (360) + B after the void (150) = 510.
        $this->assertSame($before['today']['paid_count'] + 2, $after['today']['paid_count']);
        $this->assertEqualsWithDelta($before['today']['total_sales'] + 510, $after['today']['total_sales'], 0.001);
        $this->assertEqualsWithDelta($before['month_total'] + 510, $after['month_total'], 0.001);

        // 3. Voids today: 1 whole sale, 1 item, refunded 200 + 300 = 500.
        $this->assertSame($before['voided_sales'] + 1, $after['voided_sales']);
        $this->assertSame($before['voided_items'] + 1, $after['voided_items']);
        $this->assertEqualsWithDelta($before['refund_total'] + 500, $after['refund_total'], 0.001);

        // 4. Senior/PWD today: 1 sale, 90 off.
        $this->assertSame($before['senior_count'] + 1, $after['senior_count']);
        $this->assertEqualsWithDelta($before['senior_amount'] + 90, $after['senior_amount'], 0.001);

        // 5. Latest sales: newest first, with a link to each receipt.
        $this->assertSame([$c->sale_id, $b->sale_id, $a->sale_id], $after['latest']->take(3)->pluck('sale_id')->all());
        $this->get('/dashboard')
            ->assertSee(route('receipts.show', $c), false)
            ->assertSee($c->receiptNumber());
        $this->get(route('receipts.show', $c))->assertOk()->assertSee('Back to Sales Records')->assertDontSee('New order');
    }

    // "Voided today (any sale day)" counts by the day of the VOID; Sales Records still groups by the sale day.
    public function test_sale_from_yesterday_voided_today_is_in_todays_voids(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 50, 2]]);
        $toner = $this->makeProduct('Test Toner', 200, [[300, 50, 2]]);

        // Yesterday the cashier made two sales.
        $this->travelTo(now()->subDay()->setTime(15, 0));
        // Y: Senior sale, 150 + 200 = 350, -20% = 280. Today the toner is voided: refund 200 - 40 (its Senior share) = 160.
        $y = $this->sell([[$makeup, 1], [$toner, 1]], '300', ['discount_type' => 'SENIOR', 'discount_id_number' => '12345', 'discount_customer_name' => 'Juan Cruz']);
        // Z: 2 x 150 = 300. Today the whole sale is voided: refund 300.
        $z = $this->sell([[$makeup, 2]], '300');
        $this->travelBack();
        $this->assertTrue($y->sale_datetime->isYesterday());

        $before = $this->dashboard();
        $yesterdayBefore = $this->get(route('sales.index', ['date' => $y->sale_datetime->format('Y-m-d')]))->viewData('summary');

        // Today the owner voids them.
        $this->post(route('sales.items.void', $this->itemOf($y, $toner)), ['reason' => 'customer returned it'])->assertSessionHas('success');
        $this->post(route('sales.void', $z), ['reason' => 'customer changed mind'])->assertSessionHas('success');
        $this->assertSame('120.00', $y->fresh()->total_amount); // 280 - 160

        $after = $this->dashboard();

        // Today's voids: 1 whole sale, 1 item, refunded 160 + 300 = 460, although both sales were made yesterday.
        $this->assertSame($before['voided_sales'] + 1, $after['voided_sales']);
        $this->assertSame($before['voided_items'] + 1, $after['voided_items']);
        $this->assertEqualsWithDelta($before['refund_total'] + 460, $after['refund_total'], 0.001);
        $this->get('/dashboard')->assertSee('Voided today (any sale day)');

        // Today's sales did not change: these sales belong to yesterday.
        $this->assertEquals($before['today'], $after['today']);
        $this->assertEquals($this->get('/sales')->viewData('summary'), $after['today']);

        // Sales Records still groups by the sale day: yesterday now has one more cancelled sale and 160 + 300 less in sales.
        $yesterdayAfter = $this->get(route('sales.index', ['date' => $y->sale_datetime->format('Y-m-d')]))->viewData('summary');
        $this->assertSame($yesterdayBefore['cancelled_count'] + 1, $yesterdayAfter['cancelled_count']);
        $this->assertEqualsWithDelta($yesterdayBefore['total_sales'] - 460, $yesterdayAfter['total_sales'], 0.001);
    }

    public function test_dashboard_shows_open_alert_counts_with_links(): void
    {
        // The 4 alert cards are always shown (0 when a type has none) and open the Alerts page.
        $response = $this->actingAs($this->owner)->get('/dashboard')->assertOk();
        $counts = $response->viewData('alertCounts');

        $this->assertSame(['EXPIRED', 'OUT OF STOCK', 'NEAR EXPIRY', 'LOW STOCK'], array_keys($counts));
        foreach ($counts as $count) {
            $this->assertIsInt($count);
        }
        $response->assertSee(route('alerts.index'), false)
            ->assertSee('Expired')->assertSee('Out of stock')->assertSee('Near expiry')->assertSee('Low stock');
    }
}
