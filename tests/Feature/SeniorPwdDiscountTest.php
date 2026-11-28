<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Senior Citizen / PWD discount at payment (20%, never stacked with the near-expiry 10%).
// Payments go through the real POS routes (cart -> POST /pos/pay). DatabaseTransactions rolls everything back.
class SeniorPwdDiscountTest extends TestCase
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

    // Open the POS (makes the one-time token), then press "Confirm payment" with the discount fields.
    private function pay(string $cash, array $discount = [])
    {
        $this->get('/pos')->assertOk();

        return $this->post('/pos/pay', array_merge([
            'payment_method' => 'CASH',
            'cash_received' => $cash,
            'pay_token' => session('pos_pay_token'),
        ], $discount));
    }

    private function senior(string $type = 'SENIOR'): array
    {
        return ['discount_type' => $type, 'discount_id_number' => '12345', 'discount_customer_name' => 'Juan Cruz'];
    }

    private function lastSale(): Sale
    {
        return Sale::latest('sale_id')->firstOrFail();
    }

    private function itemOf(Sale $sale, Product $product): SaleItem
    {
        return $sale->items()->whereIn('batch_id', $product->batches->pluck('batch_id'))->firstOrFail();
    }

    private function makeup(): Product
    {
        return $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]); // not near expiry
    }

    private function nearSerum(): Product
    {
        return $this->makeProduct('Test Serum', 120, [[10, 10, 5]]); // expires in 10 days -> near expiry 10%
    }

    // ---------- tests ----------

    public function test_senior_totals(): void
    {
        $makeup = $this->makeup();
        $this->addToCart($makeup, 3);

        // The payment box shows the total with the Senior / PWD discount ready for the JavaScript.
        $this->get('/pos')->assertOk()->assertSee('data-total="450.00"', false)->assertSee('data-total-senior="360.00"', false);

        $this->pay('400', $this->senior())->assertRedirect();
        $sale = $this->lastSale();

        // 3 x 150 = 450, Senior 20% = 90, total 360, change 40.
        $this->assertSame('450.00', $sale->subtotal);
        $this->assertSame('90.00', $sale->discount_amount);
        $this->assertSame('360.00', $sale->total_amount);
        $this->assertSame('40.00', $sale->change_amount);
        $this->assertSame(['SENIOR', '12345', 'Juan Cruz'], [$sale->discount_type, $sale->discount_id_number, $sale->discount_customer_name]);

        // The item is saved at full price; the Senior discount is on the sale.
        $item = $this->itemOf($sale, $makeup);
        $this->assertSame(['450.00', '0.00'], [$item->line_amount, $item->discount_percent]);

        // Receipt line.
        $this->get(route('receipts.show', $sale))->assertOk()
            ->assertSeeInOrder(['Subtotal', '450.00', 'Senior Citizen discount (ID 12345)', '-90.00', 'Customer', 'Juan Cruz', 'TOTAL', '360.00'])
            ->assertDontSee('Near-expiry discount'); // 0.00 near-expiry: the line is hidden

        // Audit SALE row names the discount.
        $audit = AuditLog::where('sale_id', $sale->sale_id)->where('action_type', 'SALE')->firstOrFail();
        $this->assertSame('Items 1, total 360.00, cash 400.00, change 40.00, Senior Citizen discount 90.00, ID 12345, name Juan Cruz', $audit->details);

        // Sales Records (owner).
        $this->actingAs($this->owner)
            ->get(route('sales.index', ['date' => $sale->sale_datetime->format('Y-m-d')]))
            ->assertOk()
            ->assertSee('Senior Citizen · ID 12345 · Juan Cruz · -₱90.00');
    }

    public function test_pwd_requires_id_and_name(): void
    {
        $makeup = $this->makeup();
        $this->addToCart($makeup, 1);

        $this->pay('500', ['discount_type' => 'PWD'])
            ->assertSessionHasErrors([
                'discount_id_number' => 'Enter the Senior Citizen / PWD ID number.',
                'discount_customer_name' => 'Enter the customer name on the ID.',
            ]);
        $this->pay('500', ['discount_type' => 'PWD', 'discount_id_number' => '   ', 'discount_customer_name' => 'Ana'])
            ->assertSessionHasErrors(['discount_id_number']);

        // Nothing saved, stock unchanged, cart kept.
        $this->assertSame(0, Sale::where('user_id', $this->cashier->user_id)->count());
        $this->assertSame(10, $makeup->batches()->first()->inventory->quantity_on_hand);

        // With both filled in, PWD works like Senior.
        $this->pay('500', $this->senior('PWD'))->assertRedirect();
        $sale = $this->lastSale();
        $this->assertSame(['PWD', '30.00', '120.00'], [$sale->discount_type, $sale->discount_amount, $sale->total_amount]);
        $this->get(route('receipts.show', $sale))->assertSee('PWD discount (ID 12345)');
    }

    public function test_no_stacking_with_near_expiry(): void
    {
        $serum = $this->nearSerum();
        $this->addToCart($serum, 1);

        $this->pay('500', $this->senior())->assertRedirect();
        $sale = $this->lastSale();

        // Senior 20% wins over near-expiry 10%: 120 - 24 = 96.00 (NOT 120 x 0.9 x 0.8 = 86.40).
        $item = $this->itemOf($sale, $serum);
        $this->assertSame(['120.00', '0.00'], [$item->line_amount, $item->discount_percent]);
        $this->assertSame('24.00', $sale->discount_amount);
        $this->assertSame('96.00', $sale->total_amount);
    }

    public function test_near_expiry_wins_when_it_is_higher(): void
    {
        config(['pos.near_expiry_discount_percent' => 25]);
        $serum = $this->nearSerum();
        $this->addToCart($serum, 1);

        $this->pay('500', $this->senior())->assertRedirect();
        $sale = $this->lastSale();

        // Near-expiry 25% is higher than 20%: only the near-expiry discount, no Senior amount.
        $item = $this->itemOf($sale, $serum);
        $this->assertSame(['90.00', '25.00'], [$item->line_amount, $item->discount_percent]);
        $this->assertSame('0.00', $sale->discount_amount);
        $this->assertSame('90.00', $sale->total_amount);
    }

    public function test_none_gives_no_discount(): void
    {
        $serum = $this->nearSerum();
        $makeup = $this->makeup();
        $this->addToCart($serum, 1);
        $this->addToCart($makeup, 3);

        // None, with leftover ID / name typed: they are ignored.
        $this->pay('600', ['discount_type' => 'NONE', 'discount_id_number' => '999', 'discount_customer_name' => 'X'])->assertRedirect();
        $sale = $this->lastSale();

        // Same as before this feature: 108 + 450 = 558.00.
        $this->assertSame('558.00', $sale->total_amount);
        $this->assertSame('0.00', $sale->discount_amount);
        $this->assertSame(['NONE', null, null], [$sale->discount_type, $sale->discount_id_number, $sale->discount_customer_name]);
        $this->get(route('receipts.show', $sale))->assertOk()->assertDontSee('Senior Citizen discount');
    }

    public function test_category_not_in_the_list_gets_no_senior_discount(): void
    {
        $serum = $this->nearSerum();
        $makeup = $this->makeup();
        config(['pos.senior_pwd_category_ids' => [$makeup->category_id]]); // only the makeup's category qualifies
        $this->addToCart($serum, 1);
        $this->addToCart($makeup, 3);

        $this->pay('600', $this->senior())->assertRedirect();
        $sale = $this->lastSale();

        // Serum keeps its near-expiry 10% (108.00); makeup gets Senior 20% (90.00). Total 108 + 450 - 90 = 468.00.
        $this->assertSame('10.00', $this->itemOf($sale, $serum)->discount_percent);
        $this->assertSame('90.00', $sale->discount_amount);
        $this->assertSame('468.00', $sale->total_amount);
    }

    public function test_item_void_on_a_senior_sale_recalculates_the_discount(): void
    {
        $serum = $this->nearSerum();
        $makeup = $this->makeup();
        $this->addToCart($serum, 1);
        $this->addToCart($makeup, 3);

        $this->pay('1000', $this->senior())->assertRedirect();
        $sale = $this->lastSale();

        // Senior on both: 24 + 90 = 114. Total 570 - 114 = 456.00.
        $this->assertSame('114.00', $sale->discount_amount);
        $this->assertSame('456.00', $sale->total_amount);

        // Owner voids the makeup.
        $this->actingAs($this->owner)
            ->post(route('sales.items.void', $this->itemOf($sale, $makeup)), ['reason' => 'customer returned it'])
            ->assertSessionHas('success');

        // Only the serum is left: 120 - 24 = 96.00. The refund is 456 - 96 = 360.00.
        $sale->refresh();
        $this->assertSame('PAID', $sale->sale_status);
        $this->assertSame('120.00', $sale->subtotal);
        $this->assertSame('24.00', $sale->discount_amount);
        $this->assertSame('96.00', $sale->total_amount);

        $audit = AuditLog::where('sale_id', $sale->sale_id)->where('action_type', 'VOID')->firstOrFail();
        $this->assertStringContainsString('refund 360.00, new total 96.00', $audit->details);

        $this->get(route('receipts.show', $sale))->assertOk()
            ->assertSeeInOrder(['Senior Citizen discount (ID 12345)', '-24.00', 'NEW TOTAL', '96.00', 'Refunded (voided items)', '360.00']);
    }
}
