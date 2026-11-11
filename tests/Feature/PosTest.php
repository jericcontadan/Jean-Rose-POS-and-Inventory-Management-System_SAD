<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\UserAccount;
use App\Services\BatchPickerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// T05, T06, T10, T11 from docs/06-testing.md: the POS screen and its session cart.
// Every test makes its own products; DatabaseTransactions rolls them back afterwards.
class PosTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed rules for the tests, whatever .env says.
        config(['pos.near_expiry_days' => 30, 'pos.near_expiry_discount_percent' => 10]);

        $this->actingAs($this->makeUser('CASHIER'));
    }

    // ---------- helpers ----------

    private function makeUser(string $role): UserAccount
    {
        $tag = Str::lower(Str::random(8));

        return UserAccount::create([
            'email' => "test_{$tag}@example.com",
            'username' => "test_{$tag}",
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => 1,
        ]);
    }

    /**
     * Make a product with batches. Each batch: [days until expiry, quantity on hand, reorder level].
     * Example: makeProduct('Serum', 120, [[200, 10, 2]]) = one batch expiring in 200 days with 10 pieces.
     */
    private function makeProduct(string $name, float $price, array $batches): Product
    {
        $tag = Str::upper(Str::random(6));
        $category = Category::create(['category_name' => "Test {$tag}"]);
        $supplier = Supplier::create(['supplier_name' => "Supplier {$tag}"]);

        $product = Product::create([
            'category_id' => $category->category_id,
            'supplier_id' => $supplier->supplier_id,
            'product_name' => $name,
            'product_number' => "T-{$tag}",
            'cost_price' => 1,
            'unit_price' => $price,
            'image_path' => 'images/placeholder.png',
        ]);

        foreach ($batches as $i => [$days, $quantity, $reorder]) {
            $batch = ProductBatch::create([
                'product_id' => $product->product_id,
                'batch_number' => "B{$i}",
                'expiry_date' => Carbon::today()->addDays($days)->toDateString(),
                'quantity_in_batch' => max($quantity, 1),
                'date_received' => Carbon::today()->subDays(30)->toDateString(),
            ]);
            Inventory::create([
                'batch_id' => $batch->batch_id,
                'quantity_on_hand' => $quantity,
                'reorder_level' => $reorder,
            ]);
        }

        return $product;
    }

    private function cart(): array
    {
        return session('pos_cart', []);
    }

    // ---------- page ----------

    public function test_guest_is_sent_to_login(): void
    {
        auth()->logout();

        $this->get('/pos')->assertRedirect('/login');
    }

    public function test_pos_shows_product_cards_with_badges(): void
    {
        $this->makeProduct('Test Serum', 120, [[10, 10, 5]]);   // near expiry
        $this->makeProduct('Test Vitamins', 90, [[300, 2, 5]]); // low stock
        $this->makeProduct('Test Lotion', 80, [[300, 0, 5]]);   // out of stock

        $this->get('/pos?q=Test')
            ->assertOk()
            ->assertSee('Near expiry −10%')
            ->assertSee('Low · 2 left')
            ->assertSee('Out of stock')
            ->assertSee('Unavailable')
            ->assertSee('Pay now');
    }

    // True when the card with this lower-case data-search text is sent with the hidden attribute.
    private function cardIsHidden(string $html, string $searchText): bool
    {
        preg_match('/<article\b[^>]*data-search="'.preg_quote($searchText, '/').'[^"]*"[^>]*>/', $html, $tag);
        $this->assertNotEmpty($tag, "Card '{$searchText}' is not on the page.");

        return (bool) preg_match('/\shidden(\s|>)/', $tag[0]);
    }

    public function test_search_hides_cards_that_do_not_match(): void
    {
        $this->makeProduct('Zebra Shampoo', 110, [[300, 5, 1]]);
        $this->makeProduct('Yellow Conditioner', 115, [[300, 5, 1]]);

        // Upper/lower case is ignored. Non-matching cards stay on the page but hidden,
        // so the live search can show them again when the box is cleared.
        $html = $this->get('/pos?q=zEBRA')->assertOk()->getContent();

        $this->assertFalse($this->cardIsHidden($html, 'zebra shampoo'));
        $this->assertTrue($this->cardIsHidden($html, 'yellow conditioner'));
    }

    public function test_search_matches_product_number(): void
    {
        $toner = $this->makeProduct('Zebra Toner', 100, [[300, 5, 1]]);
        $this->makeProduct('Yellow Conditioner', 115, [[300, 5, 1]]);

        $html = $this->get('/pos?q='.strtolower($toner->product_number))->assertOk()->getContent();

        $this->assertFalse($this->cardIsHidden($html, 'zebra toner'));
        $this->assertTrue($this->cardIsHidden($html, 'yellow conditioner'));
    }

    public function test_empty_search_shows_all_products(): void
    {
        $this->makeProduct('Zebra Shampoo', 110, [[300, 5, 1]]);
        $this->makeProduct('Yellow Conditioner', 115, [[300, 5, 1]]);

        $html = $this->get('/pos?q=')->assertOk()->getContent();

        $this->assertFalse($this->cardIsHidden($html, 'zebra shampoo'));
        $this->assertFalse($this->cardIsHidden($html, 'yellow conditioner'));
    }

    public function test_no_products_found_message_when_nothing_matches(): void
    {
        $this->makeProduct('Zebra Shampoo', 110, [[300, 5, 1]]);

        $noMatch = $this->get('/pos?q=nothing-like-this-xyz')->assertOk()->getContent();
        $match = $this->get('/pos?q=zebra')->assertOk()->getContent();

        // The message is visible when nothing matches, and hidden when something does.
        $this->assertFalse($this->noProductsIsHidden($noMatch));
        $this->assertTrue($this->noProductsIsHidden($match));
    }

    private function noProductsIsHidden(string $html): bool
    {
        preg_match('/<p\b[^>]*id="posNoProducts"[^>]*>/', $html, $tag);
        $this->assertNotEmpty($tag);

        return (bool) preg_match('/\shidden(\s|>)/', $tag[0]);
    }

    public function test_search_works_together_with_category(): void
    {
        $zebra = $this->makeProduct('Zebra Shampoo', 110, [[300, 5, 1]]);
        $this->makeProduct('Zebra Lotion', 80, [[300, 5, 1]]); // other category

        $html = $this->get("/pos?q=zebra&category={$zebra->category_id}")->assertOk()->getContent();

        $this->assertFalse($this->cardIsHidden($html, 'zebra shampoo'));
        $this->assertStringNotContainsString('zebra lotion', $html); // not in this category at all
    }

    public function test_search_does_not_change_the_cart(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);
        $this->post("/pos/cart/{$makeup->product_id}");

        $this->get('/pos?q=zzz')->assertOk()->assertSee('₱150.00');

        $this->assertSame([$makeup->product_id => 1], $this->cart());
    }

    // ---------- T05: add item, change quantity, total updates ----------

    public function test_t05_add_and_change_quantity_updates_total(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 120, [[300, 10, 2]]);

        $this->post("/pos/cart/{$makeup->product_id}")->assertRedirect();
        $this->post("/pos/cart/{$makeup->product_id}/increase");
        $this->post("/pos/cart/{$makeup->product_id}/increase");
        $this->post("/pos/cart/{$makeup->product_id}/decrease");

        $this->assertSame([$makeup->product_id => 2], $this->cart());

        $this->get('/pos')
            ->assertOk()
            ->assertSee('Order Details')
            ->assertSee('x2')
            ->assertSee('₱240.00');

        // Remove takes the line out.
        $this->delete("/pos/cart/{$makeup->product_id}");
        $this->assertSame([], $this->cart());

        // Cancel / Void empties the whole order.
        $this->post("/pos/cart/{$makeup->product_id}");
        $this->delete('/pos/cart')->assertSessionHas('success', 'Order cancelled.');
        $this->assertSame([], $this->cart());
    }

    public function test_t05_minus_at_one_removes_the_line(): void
    {
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]);

        $this->post("/pos/cart/{$makeup->product_id}");
        $this->post("/pos/cart/{$makeup->product_id}/decrease");

        $this->assertSame([], $this->cart());
    }

    public function test_t05_quantity_cannot_go_above_stock(): void
    {
        $vitamins = $this->makeProduct('Test Vitamins', 90, [[300, 2, 5]]);

        $this->post("/pos/cart/{$vitamins->product_id}");
        $this->post("/pos/cart/{$vitamins->product_id}/increase");
        $this->post("/pos/cart/{$vitamins->product_id}/increase")
            ->assertSessionHas('error', 'Only 2 Test Vitamins in stock.');

        $this->assertSame([$vitamins->product_id => 2], $this->cart());
    }

    // ---------- T06: out-of-stock product is refused ----------

    public function test_t06_out_of_stock_product_cannot_be_added(): void
    {
        $lotion = $this->makeProduct('Test Lotion', 80, [[300, 0, 5]]);

        $this->post("/pos/cart/{$lotion->product_id}")
            ->assertSessionHas('error', 'Test Lotion is out of stock.');

        $this->assertSame([], $this->cart());
    }

    public function test_t06_expired_stock_is_not_sold(): void
    {
        // 5 pieces on hand, but the only batch expired yesterday.
        $old = $this->makeProduct('Test Old Cream', 100, [[-1, 5, 1]]);

        $this->post("/pos/cart/{$old->product_id}")
            ->assertSessionHas('error', 'Test Old Cream is out of stock.');

        $this->assertSame([], $this->cart());
    }

    // ---------- T10: near-expiry item gets 10% on that line ----------

    public function test_t10_near_expiry_line_gets_ten_percent_discount(): void
    {
        $serum = $this->makeProduct('Test Serum', 120, [[10, 10, 5]]);   // expires in 10 days
        $makeup = $this->makeProduct('Test Makeup', 150, [[300, 10, 2]]); // not near expiry

        $this->post("/pos/cart/{$serum->product_id}");
        $this->post("/pos/cart/{$makeup->product_id}");
        $this->post("/pos/cart/{$makeup->product_id}/increase");
        $this->post("/pos/cart/{$makeup->product_id}/increase");

        // Same numbers as the mockup: 120 + 3 x 150 = 570.00, minus 12.00 = 558.00.
        $this->get('/pos')
            ->assertOk()
            ->assertSee('570.00')
            ->assertSee('Near-expiry discount (auto)')
            ->assertSee('Test Serum 10%')
            ->assertSee('−12.00')
            ->assertSee('₱558.00');
    }

    // ---------- T11: two batches, earliest expiry first, split rows ----------

    public function test_t11_fefo_picks_earliest_expiry_first_and_splits(): void
    {
        // Later batch is created first, so the order is not just "by id".
        // Batch 0: expires in 300 days (5 pcs). Batch 1: expires in 100 days (3 pcs). Batch 2: expired (9 pcs).
        $product = $this->makeProduct('Test Toner', 100, [[300, 5, 1], [100, 3, 1], [-5, 9, 1]]);
        $product->load('batches.inventory');

        $picker = app(BatchPickerService::class);
        $this->assertSame(8, $picker->available($product)); // expired batch not counted

        $parts = $picker->pick($product, 5);

        $this->assertCount(2, $parts);
        $this->assertSame('B1', $parts[0]['batch']->batch_number); // earliest expiry first
        $this->assertSame(3, $parts[0]['quantity']);
        $this->assertSame('B0', $parts[1]['batch']->batch_number);
        $this->assertSame(2, $parts[1]['quantity']);
    }

    public function test_t11_discount_only_on_the_near_expiry_part(): void
    {
        // 2 pieces expire in 10 days (near), 5 pieces in 300 days. Buy 3: 2 discounted + 1 full price.
        $serum = $this->makeProduct('Test Serum', 100, [[300, 5, 1], [10, 2, 1]]);

        $this->post("/pos/cart/{$serum->product_id}");
        $this->post("/pos/cart/{$serum->product_id}/increase");
        $this->post("/pos/cart/{$serum->product_id}/increase");

        // Subtotal 300.00; discount 2 x 100 x 10% = 20.00; total 280.00.
        $this->get('/pos')
            ->assertOk()
            ->assertSee('300.00')
            ->assertSee('Test Serum 10% (2 of 3)')
            ->assertSee('−20.00')
            ->assertSee('₱280.00');
    }
}
