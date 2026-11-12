<?php

namespace Tests\Feature;

use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\UserAccount;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Services\StockStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Add or Edit Product (screen 12, extra 10): T17 from docs/06-testing.md, plus: add product writes product, batch,
// inventory and one audit row and no history; duplicate product number refused; no image = placeholder path;
// edit with a price change logs old and new; add new batch; delete refused with history and allowed when unused;
// cashier 403; a forced error saves nothing. DatabaseTransactions rolls everything back after each test,
// and Storage::fake('public') keeps uploaded test images out of the real storage folder.
class ProductTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;
    private UserAccount $cashier;
    private Category $category;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pos.near_expiry_days' => 30, 'pos.near_expiry_discount_percent' => 10]);
        Storage::fake('public');

        $this->owner = $this->makeUser('OWNER');
        $this->cashier = $this->makeUser('CASHIER');
        $tag = Str::upper(Str::random(6));
        $this->category = Category::create(['category_name' => "Toners {$tag}"]);
        $this->supplier = Supplier::create(['supplier_name' => "SY Trading {$tag}"]);
        $this->actingAs($this->owner);
    }

    // ---------- helpers ----------

    // A valid Add product form. $changes replaces some fields.
    private function form(array $changes = []): array
    {
        return array_merge([
            'product_number' => 'TST-'.Str::upper(Str::random(6)),
            'product_name' => 'Toner',
            'category_id' => $this->category->category_id,
            'supplier_id' => $this->supplier->supplier_id,
            'cost_price' => '80.00',
            'unit_price' => '120.00',
            'batch_number' => 'B-TONER-01',
            'expiry_date' => Carbon::today()->addDays(200)->toDateString(),
            'quantity' => '10',
            'reorder_level' => '5',
        ], $changes);
    }

    // The Edit product form for an existing product (same values, then $changes).
    private function editForm(Product $product, array $changes = []): array
    {
        return array_merge([
            'product_number' => $product->product_number,
            'product_name' => $product->product_name,
            'category_id' => $product->category_id,
            'supplier_id' => $product->supplier_id,
            'cost_price' => $product->cost_price,
            'unit_price' => $product->unit_price,
        ], $changes);
    }

    private function batchForm(array $changes = []): array
    {
        return array_merge([
            'batch_number' => 'B-NEW-02',
            'expiry_date' => Carbon::today()->addDays(300)->toDateString(),
            'quantity' => '20',
            'reorder_level' => '5',
        ], $changes);
    }

    private function stockRow(Product $product): Inventory
    {
        return $product->batches()->first()->inventory()->with('batch')->first();
    }

    private function lastAudit(): AuditLog
    {
        return AuditLog::where('user_id', $this->owner->user_id)->latest('audit_log_id')->first();
    }

    // Row counts of every table these screens can write, to prove "nothing changed".
    private function counts(): array
    {
        return [
            'product' => Product::count(),
            'product_batch' => ProductBatch::count(),
            'inventory' => Inventory::count(),
            'inventory_history' => InventoryHistory::count(),
            'audit_log' => AuditLog::count(),
            'alert_notification' => AlertNotification::count(),
        ];
    }

    private function forceAuditError(): void
    {
        $this->mock(AuditService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new RuntimeException('Forced error')));
    }

    // ---------- T17: a batch that has sales cannot be deleted ----------

    public function test_t17_delete_batch_that_has_sales_is_refused_with_message(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        app(SaleService::class)->pay($this->cashier, [$serum->product_id => 1], 1000);
        $inventory = $this->stockRow($serum);
        $before = $this->counts();

        $this->delete(route('inventory.destroy', $inventory))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('error', 'This batch has sales or history and cannot be deleted.');

        $this->assertSame($before, $this->counts());
        $this->assertNotNull(Inventory::find($inventory->inventory_id));
        $this->assertNotNull(ProductBatch::find($inventory->batch_id));
        $this->assertNotNull(Product::find($serum->product_id));
    }

    // ---------- Add product ----------

    public function test_add_product_writes_product_batch_inventory_and_one_audit_row_and_no_history(): void
    {
        $before = $this->counts();
        $form = $this->form(['product_number' => 'TST-P0007']);

        $this->post(route('products.store'), $form)
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success', 'Product Toner (TST-P0007) was added.');

        $product = Product::where('product_number', 'TST-P0007')->firstOrFail();
        $this->assertSame('Toner', $product->product_name);
        $this->assertSame('80.00', $product->cost_price);
        $this->assertSame('120.00', $product->unit_price);

        $batch = $product->batches()->firstOrFail();
        $this->assertSame('B-TONER-01', $batch->batch_number);
        $this->assertSame(10, $batch->quantity_in_batch);
        $this->assertSame($form['expiry_date'], $batch->expiry_date->toDateString());
        $this->assertSame(Carbon::today()->toDateString(), $batch->date_received->toDateString());

        $inventory = $batch->inventory;
        $this->assertSame(10, $inventory->quantity_on_hand);
        $this->assertSame(5, $inventory->reorder_level);
        $this->assertSame('OKAY', $inventory->stock_status);

        // Exactly one new row in product, product_batch, inventory and audit_log. No history, no alert (OKAY).
        $after = $this->counts();
        $this->assertSame($before['product'] + 1, $after['product']);
        $this->assertSame($before['product_batch'] + 1, $after['product_batch']);
        $this->assertSame($before['inventory'] + 1, $after['inventory']);
        $this->assertSame($before['audit_log'] + 1, $after['audit_log']);
        $this->assertSame($before['inventory_history'], $after['inventory_history']);
        $this->assertSame($before['alert_notification'], $after['alert_notification']);

        $audit = $this->lastAudit();
        $this->assertSame('INVENTORY_UPDATE', $audit->action_type);
        $this->assertSame('INVENTORY', $audit->module);
        $this->assertSame('TST-P0007', $audit->transaction_reference);
        $this->assertSame('Added product Toner TST-P0007, batch B-TONER-01, qty 10', $audit->details);
    }

    public function test_add_product_with_low_stock_gets_its_status_and_one_open_alert(): void
    {
        $this->post(route('products.store'), $this->form(['product_number' => 'TST-LOW1', 'quantity' => '3', 'reorder_level' => '5']));

        $inventory = $this->stockRow(Product::where('product_number', 'TST-LOW1')->firstOrFail());
        $this->assertSame('LOW STOCK', $inventory->stock_status);
        $this->assertSame(1, AlertNotification::where('batch_id', $inventory->batch_id)->whereNull('resolved_at')
            ->where('alert_type', 'LOW STOCK')->count());
    }

    public function test_duplicate_product_number_is_refused_and_nothing_is_saved(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();

        $this->from(route('products.create'))
            ->post(route('products.store'), $this->form(['product_number' => $serum->product_number]))
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors(['product_number' => 'This product number already exists.']);

        $this->assertSame($before, $this->counts());
    }

    public function test_invalid_add_product_input_is_refused(): void
    {
        $before = $this->counts();
        $bad = [
            'unit_price' => ['0', '-1', 'abc', '12.345'],
            'cost_price' => ['-1', 'abc', '1.234'],
            'expiry_date' => [Carbon::yesterday()->toDateString(), 'not a date'],
            'quantity' => ['0', '1.5', 'abc'],
            'reorder_level' => ['-1', 'abc'],
            'category_id' => ['999999999'],
            'product_name' => [''],
            'batch_number' => [''],
        ];

        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                $this->from(route('products.create'))
                    ->post(route('products.store'), $this->form([$field => $value]))
                    ->assertSessionHasErrors($field);
            }
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_no_image_stores_the_placeholder_path(): void
    {
        $this->post(route('products.store'), $this->form(['product_number' => 'TST-NOIMG']));

        $this->assertSame('images/placeholder.png', Product::where('product_number', 'TST-NOIMG')->value('image_path'));
        $this->assertFileExists(public_path('images/placeholder.png'));
    }

    public function test_uploaded_image_is_stored_under_products(): void
    {
        $image = UploadedFile::fake()->create('toner.jpg', 100, 'image/jpeg');

        $this->post(route('products.store'), $this->form(['product_number' => 'TST-IMG', 'image' => $image]));

        $path = Product::where('product_number', 'TST-IMG')->value('image_path');
        $this->assertStringStartsWith('storage/products/', $path);
        Storage::disk('public')->assertExists(Str::after($path, 'storage/'));
    }

    public function test_wrong_or_too_big_image_is_refused(): void
    {
        foreach ([UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                  UploadedFile::fake()->create('big.png', 3000, 'image/png')] as $file) {
            $this->from(route('products.create'))
                ->post(route('products.store'), $this->form(['image' => $file]))
                ->assertSessionHasErrors('image');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---------- Edit product ----------

    public function test_edit_with_a_price_change_logs_old_and_new_price(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);

        $this->put(route('products.update', $serum), $this->editForm($serum, ['unit_price' => '125.00']))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success', 'Product Serum was saved.');

        $this->assertSame('125.00', $serum->fresh()->unit_price);

        // Only the price changed: one audit row, the same text as Update price.
        $rows = AuditLog::where('user_id', $this->owner->user_id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Price Serum: 120.00 to 125.00', $rows[0]->details);
        $this->assertSame($serum->product_number, $rows[0]->transaction_reference);
    }

    public function test_edit_other_fields_writes_edited_product_row(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);

        $this->put(route('products.update', $serum), $this->editForm($serum, ['product_name' => 'Rose Serum', 'cost_price' => '90']));

        $fresh = $serum->fresh();
        $this->assertSame('Rose Serum', $fresh->product_name);
        $this->assertSame('90.00', $fresh->cost_price);
        $this->assertSame("Edited product Rose Serum {$serum->product_number}: name, cost price", $this->lastAudit()->details);
    }

    public function test_edit_with_price_and_name_writes_two_rows(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);

        $this->put(route('products.update', $serum), $this->editForm($serum, ['product_name' => 'Rose Serum', 'unit_price' => '125']));

        $details = AuditLog::where('user_id', $this->owner->user_id)->orderBy('audit_log_id')->pluck('details')->all();
        $this->assertSame(['Price Rose Serum: 120.00 to 125.00', "Edited product Rose Serum {$serum->product_number}: name"], $details);
    }

    public function test_edit_with_no_change_saves_nothing(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();

        $this->put(route('products.update', $serum), $this->editForm($serum, ['unit_price' => '120', 'cost_price' => '1.0']))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success', 'Product Serum is unchanged. Nothing was saved.');

        $this->assertSame($before, $this->counts());
    }

    public function test_edit_can_keep_its_own_product_number_but_not_take_another(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $toner = $this->makeProduct('Toner', 90, [[300, 10, 2]]);

        $this->put(route('products.update', $serum), $this->editForm($serum, ['product_name' => 'Serum 2']))
            ->assertSessionHasNoErrors();

        $this->from(route('products.edit', $serum))
            ->put(route('products.update', $serum), $this->editForm($serum, ['product_number' => $toner->product_number]))
            ->assertSessionHasErrors(['product_number' => 'This product number already exists.']);
    }

    public function test_edit_with_a_new_image_replaces_the_old_file(): void
    {
        $this->post(route('products.store'), $this->form([
            'product_number' => 'TST-REIMG', 'image' => UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'),
        ]));
        $product = Product::where('product_number', 'TST-REIMG')->firstOrFail();
        $oldPath = $product->image_path;

        $this->put(route('products.update', $product), $this->editForm($product, [
            'image' => UploadedFile::fake()->create('b.png', 50, 'image/png'),
        ]));

        $newPath = $product->fresh()->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing(Str::after($oldPath, 'storage/'));
        Storage::disk('public')->assertExists(Str::after($newPath, 'storage/'));
        $this->assertSame("Edited product Toner TST-REIMG: image", $this->lastAudit()->details);
    }

    // ---------- Add new batch ----------

    public function test_add_new_batch_inserts_batch_and_inventory_and_audit_row_and_no_history(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();

        $this->post(route('products.batches.store', $serum), $this->batchForm())
            ->assertRedirect(route('products.edit', $serum))
            ->assertSessionHas('success', 'Batch B-NEW-02 (20 pcs) was added to Serum.');

        $batch = ProductBatch::where('product_id', $serum->product_id)->where('batch_number', 'B-NEW-02')->firstOrFail();
        $this->assertSame(20, $batch->quantity_in_batch);
        $this->assertSame(20, $batch->inventory->quantity_on_hand);
        $this->assertSame('OKAY', $batch->inventory->stock_status);

        $after = $this->counts();
        $this->assertSame($before['product_batch'] + 1, $after['product_batch']);
        $this->assertSame($before['inventory'] + 1, $after['inventory']);
        $this->assertSame($before['audit_log'] + 1, $after['audit_log']);
        $this->assertSame($before['inventory_history'], $after['inventory_history']);
        $this->assertSame("Added batch B-NEW-02 to Serum {$serum->product_number}, qty 20", $this->lastAudit()->details);
    }

    public function test_near_expiry_new_batch_gets_near_expiry_status_and_alert(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);

        $this->post(route('products.batches.store', $serum), $this->batchForm([
            'expiry_date' => Carbon::today()->addDays(10)->toDateString(),
        ]));

        $batch = ProductBatch::where('product_id', $serum->product_id)->where('batch_number', 'B-NEW-02')->firstOrFail();
        $this->assertSame('NEAR EXPIRY', $batch->inventory->stock_status);
        $this->assertSame(1, AlertNotification::where('batch_id', $batch->batch_id)->whereNull('resolved_at')->count());
    }

    public function test_duplicate_batch_number_for_the_same_product_is_refused(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]); // has batch B0
        $before = $this->counts();

        $this->from(route('products.edit', $serum))
            ->post(route('products.batches.store', $serum), $this->batchForm(['batch_number' => 'B0']))
            ->assertRedirect(route('products.edit', $serum))
            ->assertSessionHasErrorsIn('batch', ['batch_number' => 'This batch number already exists for this product.']);

        $this->assertSame($before, $this->counts());

        // The same batch number on ANOTHER product is fine.
        $toner = $this->makeProduct('Toner', 90, [[300, 10, 2]]);
        $this->post(route('products.batches.store', $toner), $this->batchForm(['batch_number' => 'B1']))
            ->assertSessionHasNoErrors();
    }

    // ---------- Delete batch (trash) ----------

    public function test_delete_is_refused_when_the_batch_has_history(): void
    {
        $vitamins = $this->makeProduct('Vitamins', 80, [[300, 2, 5]]);
        $inventory = $this->stockRow($vitamins);
        app(InventoryService::class)->restock($this->owner, $inventory, 5); // writes a RESTOCK history row
        $before = $this->counts();

        $this->delete(route('inventory.destroy', $inventory))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('error', 'This batch has sales or history and cannot be deleted.');

        $this->assertSame($before, $this->counts());
    }

    public function test_delete_unused_batch_removes_alerts_inventory_and_batch_and_keeps_the_product(): void
    {
        $vitamins = $this->makeProduct('Vitamins', 80, [[300, 20, 5], [200, 2, 5]]);
        $low = ProductBatch::where('product_id', $vitamins->product_id)->where('batch_number', 'B1')->firstOrFail()
            ->inventory()->with('batch')->first();
        app(StockStatusService::class)->refresh($low);
        app(AlertService::class)->sync($low); // B1 is LOW STOCK with one open alert
        $this->assertSame(1, AlertNotification::where('batch_id', $low->batch_id)->count());

        $this->delete(route('inventory.destroy', $low))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success', "Deleted batch B1 of Vitamins {$vitamins->product_number} (qty 2).");

        $this->assertSame(0, AlertNotification::where('batch_id', $low->batch_id)->count());
        $this->assertNull(Inventory::find($low->inventory_id));
        $this->assertNull(ProductBatch::find($low->batch_id));
        $this->assertNotNull(Product::find($vitamins->product_id)); // batch B0 is still there
        $this->assertSame("Deleted batch B1 of Vitamins {$vitamins->product_number} (qty 2)", $this->lastAudit()->details);
    }

    public function test_deleting_the_last_batch_also_removes_the_product_and_its_image(): void
    {
        $this->post(route('products.store'), $this->form([
            'product_number' => 'TST-DEL', 'image' => UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'),
        ]));
        $product = Product::where('product_number', 'TST-DEL')->firstOrFail();
        $imagePath = $product->image_path;

        $this->delete(route('inventory.destroy', $this->stockRow($product)))
            ->assertSessionHas('success', 'Deleted batch B-TONER-01 of Toner TST-DEL (qty 10); product removed.');

        $this->assertNull(Product::find($product->product_id));
        Storage::disk('public')->assertMissing(Str::after($imagePath, 'storage/'));

        $audit = $this->lastAudit();
        $this->assertSame('INVENTORY_UPDATE', $audit->action_type);
        $this->assertSame('TST-DEL', $audit->transaction_reference);
    }

    // ---------- owner only ----------

    public function test_cashier_gets_403_on_every_product_route_and_nothing_changes(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $inventory = $this->stockRow($serum);
        $before = $this->counts();
        $this->actingAs($this->cashier);

        $this->get(route('products.create'))->assertForbidden();
        $this->post(route('products.store'), $this->form())->assertForbidden();
        $this->get(route('products.edit', $serum))->assertForbidden();
        $this->put(route('products.update', $serum), $this->editForm($serum, ['unit_price' => '1']))->assertForbidden();
        $this->post(route('products.batches.store', $serum), $this->batchForm())->assertForbidden();
        $this->delete(route('inventory.destroy', $inventory))->assertForbidden();

        $this->assertSame($before, $this->counts());
        $this->assertSame('120.00', $serum->fresh()->unit_price);
    }

    // ---------- a forced error in the middle saves nothing ----------

    public function test_forced_error_in_add_product_saves_nothing_and_leaves_no_image(): void
    {
        $before = $this->counts();
        $this->forceAuditError(); // the audit row is the LAST step: product, batch, inventory are already written

        $this->post(route('products.store'), $this->form(['image' => UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg')]))
            ->assertRedirect(route('products.create'))
            ->assertSessionHas('error', 'Save failed. Nothing was changed. Please try again.');

        $this->assertSame($before, $this->counts());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_forced_error_in_edit_saves_nothing(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();
        $this->forceAuditError();

        $this->put(route('products.update', $serum), $this->editForm($serum, [
            'unit_price' => '125', 'image' => UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'),
        ]))
            ->assertRedirect(route('products.edit', $serum))
            ->assertSessionHas('error', 'Save failed. Nothing was changed. Please try again.');

        $this->assertSame($before, $this->counts());
        $this->assertSame('120.00', $serum->fresh()->unit_price);
        $this->assertSame('images/placeholder.png', $serum->fresh()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_forced_error_in_add_batch_saves_nothing(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $before = $this->counts();
        $this->forceAuditError();

        $this->post(route('products.batches.store', $serum), $this->batchForm(['quantity' => '1'])) // LOW STOCK: alert too
            ->assertSessionHas('error', 'Save failed. Nothing was changed. Please try again.');

        $this->assertSame($before, $this->counts());
    }

    public function test_forced_error_in_delete_saves_nothing(): void
    {
        $vitamins = $this->makeProduct('Vitamins', 80, [[300, 2, 5]]);
        $inventory = $this->stockRow($vitamins);
        app(StockStatusService::class)->refresh($inventory);
        app(AlertService::class)->sync($inventory);
        $before = $this->counts();
        $this->forceAuditError(); // alerts, inventory, batch and product are already deleted when it fails

        $this->delete(route('inventory.destroy', $inventory))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('error', 'Delete failed. Nothing was changed. Please try again.');

        $this->assertSame($before, $this->counts());
        $this->assertNotNull(Product::find($vitamins->product_id));
    }

    // ---------- the pages ----------

    public function test_inventory_links_and_product_pages_show(): void
    {
        $serum = $this->makeProduct('Serum', 120, [[300, 10, 2]]);
        $inventory = $this->stockRow($serum);

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertSee(route('products.create'), false)
            ->assertSee(route('products.edit', $serum), false)
            ->assertSee(route('inventory.destroy', $inventory), false)
            ->assertSee('data-confirm="Delete batch B0 of Serum? This cannot be undone."', false)
            ->assertDontSee('disabled>+ Add product', false)
            ->assertSee('disabled>Import Excel</button>', false)
            ->assertSee('disabled>Export Excel</button>', false);

        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('Add product')
            ->assertSee('Product number *')
            ->assertSee('First batch')
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee($this->category->category_name)
            ->assertSee($this->supplier->supplier_name)
            ->assertSee('Save product');

        $this->get(route('products.edit', $serum))
            ->assertOk()
            ->assertSee('Edit product')
            ->assertSee('value="'.$serum->product_number.'"', false)
            ->assertSee('Add new batch')
            ->assertSee('B0')
            ->assertSee('Save changes')
            ->assertSee('Add batch');
    }
}
