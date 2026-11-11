<?php

namespace Tests\Unit;

use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\UserAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Every model must point at the exact table and primary key from docs/sql/jeanrose_pos.sql,
// and must not use Laravel's created_at/updated_at timestamps. No database needed.
class ModelsTest extends TestCase
{
    public static function models(): array
    {
        return [
            [UserAccount::class, 'user_account', 'user_id'],
            [Category::class, 'category', 'category_id'],
            [Supplier::class, 'supplier', 'supplier_id'],
            [Product::class, 'product', 'product_id'],
            [ProductBatch::class, 'product_batch', 'batch_id'],
            [Inventory::class, 'inventory', 'inventory_id'],
            [InventoryHistory::class, 'inventory_history', 'transaction_id'],
            [Sale::class, 'sale', 'sale_id'],
            [SaleItem::class, 'sale_item', 'sale_item_id'],
            [AuditLog::class, 'audit_log', 'audit_log_id'],
            [AlertNotification::class, 'alert_notification', 'alert_id'],
        ];
    }

    #[DataProvider('models')]
    public function test_model_maps_table_and_primary_key(string $class, string $table, string $key): void
    {
        $model = new $class;

        $this->assertSame($table, $model->getTable());
        $this->assertSame($key, $model->getKeyName());
        $this->assertFalse($model->timestamps);
    }

    public function test_user_account_uses_password_hash_and_no_remember_token(): void
    {
        $user = new UserAccount(['password_hash' => 'hashed-value']);

        $this->assertSame('hashed-value', $user->getAuthPassword());
        $this->assertSame('', $user->getRememberTokenName());
        $this->assertContains('password_hash', $user->getHidden());
    }
}
