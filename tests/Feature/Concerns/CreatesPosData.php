<?php

namespace Tests\Feature\Concerns;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\UserAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Test helpers shared by PosTest and PayNowTest: make users and products with batches.
// The tests use DatabaseTransactions, so everything made here is rolled back after each test.
trait CreatesPosData
{
    protected function makeUser(string $role): UserAccount
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
    protected function makeProduct(string $name, float $price, array $batches): Product
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
}
