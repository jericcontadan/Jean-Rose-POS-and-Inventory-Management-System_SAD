<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// Step 0 checks that need the test database (jeanrose_pos_test).
// DatabaseTransactions rolls back every insert, so the database stays clean.
class SetupTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role): UserAccount
    {
        $tag = Str::lower(Str::random(6));

        return UserAccount::create([
            'email' => "test_{$tag}@example.com",
            'username' => "test_{$tag}",
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => 1,
        ]);
    }

    public function test_models_save_and_load_rows_with_relationships(): void
    {
        $tag = Str::upper(Str::random(6));

        $category = Category::create(['category_name' => "Test Category {$tag}"]);
        $supplier = Supplier::create(['supplier_name' => "Test Supplier {$tag}"]);
        $product = Product::create([
            'category_id' => $category->category_id,
            'supplier_id' => $supplier->supplier_id,
            'product_name' => 'Test Serum',
            'product_number' => "T-{$tag}",
            'cost_price' => 80,
            'unit_price' => 120,
            'image_path' => 'images/placeholder.png',
        ]);
        $batch = ProductBatch::create([
            'product_id' => $product->product_id,
            'batch_number' => 'B-001',
            'expiry_date' => '2027-12-31',
            'quantity_in_batch' => 10,
            'date_received' => '2026-11-01',
        ]);
        Inventory::create([
            'batch_id' => $batch->batch_id,
            'quantity_on_hand' => 10,
            'reorder_level' => 3,
        ]);

        // Read everything back fresh from MySQL.
        $loaded = Product::with(['category', 'supplier', 'batches.inventory'])->find($product->product_id);

        $this->assertSame("Test Category {$tag}", $loaded->category->category_name);
        $this->assertSame("Test Supplier {$tag}", $loaded->supplier->supplier_name);
        $this->assertSame('120.00', $loaded->unit_price);
        $this->assertCount(1, $loaded->batches);
        $this->assertSame('2027-12-31', $loaded->batches->first()->expiry_date->format('Y-m-d'));
        $this->assertSame(10, $loaded->batches->first()->inventory->quantity_on_hand);
        $this->assertSame('OKAY', $loaded->batches->first()->inventory->fresh()->stock_status); // DB default
    }

    public function test_user_account_password_is_hashed(): void
    {
        $user = UserAccount::find($this->makeUser('CASHIER')->user_id);

        $this->assertNotSame('secret123', $user->password_hash);
        $this->assertTrue(Hash::check('secret123', $user->getAuthPassword()));
    }

    public function test_owner_sees_all_tabs_in_header(): void
    {
        $response = $this->actingAs($this->makeUser('OWNER'))->get('/');

        $response->assertOk();
        $response->assertSee('POS');
        $response->assertSee('Sales Records');
        $response->assertSee('Inventory');
        $response->assertSee('Audit Log');
        $response->assertSee('Alerts');
        $response->assertSee('Owner');
        $response->assertSee('Log out');
    }

    public function test_cashier_sees_no_owner_tabs(): void
    {
        $response = $this->actingAs($this->makeUser('CASHIER'))->get('/');

        $response->assertOk();
        $response->assertSee('Cashier');
        $response->assertSee('Alerts');
        $response->assertSee('Log out');
        $response->assertDontSee('Sales Records');
        $response->assertDontSee('Audit Log');
        $response->assertDontSee('aria-label="Owner menu"', false);
    }
}
