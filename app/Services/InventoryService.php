<?php

namespace App\Services;

use App\Exceptions\BatchInUseException;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SaleItem;
use App\Models\UserAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

// The ONLY place that changes inventory.quantity_on_hand (docs/08-architecture.md, rule 2),
// so stock_status, inventory_history and alert_notification always stay in step with the stock.
// Sale and void: call it inside the caller's DB::transaction() with the inventory row already locked (lockForUpdate).
// Restock and price change (screen 10): restock() and changePrice() open their own DB::transaction().
// Add or Edit Product (screen 12): addProduct(), editProduct(), addBatch() and deleteBatch() do the same.
class InventoryService
{
    // image_path of a product without its own picture (the file is in public/images).
    public const PLACEHOLDER = 'images/placeholder.png';

    public function __construct(
        private StockStatusService $status,
        private AlertService $alerts,
        private AuditService $audit,
    ) {
    }

    /**
     * Update stock (screen 10, OWNER only, docs/04-business-rules.md "Restock and price change").
     * Adds $quantity pieces to ONE batch. Everything runs in ONE DB::transaction(): if any step throws,
     * nothing is saved. Returns the inventory row with its new quantity and status.
     */
    public function restock(UserAccount $owner, Inventory $inventory, int $quantity): Inventory
    {
        if ($quantity <= 0) {
            throw new RuntimeException("Cannot restock {$quantity} pieces.");
        }

        return DB::transaction(function () use ($owner, $inventory, $quantity) {
            // 1. Read the stock row and its batch again and LOCK them, so a sale or another restock
            //    at the same moment waits here and never works with an old quantity.
            $inventory = Inventory::whereKey($inventory->inventory_id)->lockForUpdate()->firstOrFail();
            $batch = ProductBatch::whereKey($inventory->batch_id)->lockForUpdate()->firstOrFail();
            $inventory->setRelation('batch', $batch); // the status rule needs the batch's expiry date
            $before = (int) $inventory->quantity_on_hand;

            // 2. The batch also keeps how many pieces it has received in total.
            $batch->quantity_in_batch += $quantity;
            $batch->save();

            // 3. Add the stock, refresh its status, write history (RESTOCK, positive) and sync alerts.
            $this->move($inventory, $quantity, 'RESTOCK', $owner);

            // 4. Audit row, for example "Restock B-VITA-01 +20 (2 to 22)".
            $this->audit->log(
                $owner,
                'INVENTORY_UPDATE',
                'INVENTORY',
                $batch->batch_number,
                "Restock {$batch->batch_number} +{$quantity} ({$before} to {$inventory->quantity_on_hand})",
            );

            return $inventory;
        });
    }

    /**
     * Update price (screen 10, OWNER only). Changes product.unit_price ONLY: sale_item rows keep the price
     * they were sold at. Returns false (and saves nothing) when the new price is the same as the old one.
     * $newPrice is the validated text from the form, like "125" or "125.50".
     */
    public function changePrice(UserAccount $owner, Product $product, string $newPrice): bool
    {
        return DB::transaction(function () use ($owner, $product, $newPrice) {
            // 1. Read the product again and LOCK it (two owners saving at once run one after the other).
            $product = Product::whereKey($product->product_id)->lockForUpdate()->firstOrFail();

            // 2. Compare in centavos (whole numbers), so 120 and 120.00 count as the same price.
            $oldCents = (int) round((float) $product->unit_price * 100);
            $newCents = (int) round((float) $newPrice * 100);

            if ($oldCents === $newCents) {
                return false; // unchanged: no update, no audit row
            }

            $old = number_format($oldCents / 100, 2, '.', '');
            $new = number_format($newCents / 100, 2, '.', '');

            // 3. Save the new price on the product only.
            $product->unit_price = $new;
            $product->save();

            // 4. Audit row with the old and new price, for example "Price Serum: 120.00 to 125.00".
            $this->logProduct($owner, $product, $this->priceText($product, $old, $new));

            return true;
        });
    }

    // ===================== Add or Edit Product (screen 12, OWNER only) =====================

    /**
     * Add product: the product, its first batch and that batch's stock row, in ONE DB::transaction().
     * $data is the validated ProductRequest. No inventory_history row: a new product is not a restock.
     */
    public function addProduct(UserAccount $owner, array $data, ?UploadedFile $image): Product
    {
        // The image file is saved BEFORE the transaction (files are not part of a database transaction).
        $imagePath = $image ? $this->storeImage($image) : self::PLACEHOLDER;

        try {
            return DB::transaction(function () use ($owner, $data, $imagePath) {
                // 1. The product.
                $product = Product::create([
                    'product_number' => $data['product_number'],
                    'product_name' => $data['product_name'],
                    'category_id' => $data['category_id'],
                    'supplier_id' => $data['supplier_id'],
                    'cost_price' => $data['cost_price'],
                    'unit_price' => $data['unit_price'],
                    'image_path' => $imagePath,
                ]);

                // 2-4. The first batch, its stock row with the right status, and its alert (if any).
                $batch = $this->createBatchWithStock($product, $data);

                // 5. Audit row, for example "Added product Toner P-0007, batch B-TONER-01, qty 10".
                $this->logProduct($owner, $product,
                    "Added product {$product->product_name} {$product->product_number}, batch {$batch->batch_number}, qty {$batch->quantity_in_batch}");

                return $product;
            });
        } catch (Throwable $e) {
            $this->deleteImage($imagePath); // nothing was saved, so the new file is not needed either
            throw $e;
        }
    }

    /**
     * Edit product: the product fields only (batches are not changed here).
     * A price change writes the same audit row as Update price; any other change writes "Edited product ...".
     * Returns false (and saves nothing) when nothing changed.
     */
    public function editProduct(UserAccount $owner, Product $product, array $data, ?UploadedFile $image): bool
    {
        $newImage = $image ? $this->storeImage($image) : null;

        try {
            $oldImage = DB::transaction(function () use ($owner, $product, $data, $newImage) {
                // 1. Read the product again and LOCK it (two owners saving at once run one after the other).
                $product = Product::whereKey($product->product_id)->lockForUpdate()->firstOrFail();
                $oldImage = $product->image_path;
                $oldPrice = number_format((float) $product->unit_price, 2, '.', '');

                // 2. Put the new values on the model. Money is saved with 2 decimals, ids as numbers.
                $product->fill([
                    'product_number' => $data['product_number'],
                    'product_name' => $data['product_name'],
                    'category_id' => (int) $data['category_id'],
                    'supplier_id' => (int) $data['supplier_id'],
                    'cost_price' => number_format((float) $data['cost_price'], 2, '.', ''),
                    'unit_price' => number_format((float) $data['unit_price'], 2, '.', ''),
                ]);
                if ($newImage) {
                    $product->image_path = $newImage;
                }

                // 3. Which fields really changed? (Eloquent compares old and new values for us.)
                $labels = [
                    'product_number' => 'product number', 'product_name' => 'name', 'category_id' => 'category',
                    'supplier_id' => 'supplier', 'cost_price' => 'cost price', 'image_path' => 'image',
                ];
                $priceChanged = $product->isDirty('unit_price');
                $otherChanges = array_values(array_intersect_key($labels, $product->getDirty()));

                if (! $priceChanged && $otherChanges === []) {
                    return null; // unchanged: no update, no audit row
                }

                // 4. Save the product (one UPDATE with only the changed columns).
                $product->save();

                // 5. Audit rows: the price like Update price, the rest as "Edited product ...".
                if ($priceChanged) {
                    $this->logProduct($owner, $product, $this->priceText($product, $oldPrice, $product->unit_price));
                }
                if ($otherChanges !== []) {
                    $this->logProduct($owner, $product,
                        "Edited product {$product->product_name} {$product->product_number}: ".implode(', ', $otherChanges));
                }

                return $oldImage; // saved: hand back the old picture path (image_path is never empty)
            });
        } catch (Throwable $e) {
            $this->deleteImage($newImage);
            throw $e;
        }

        // The old uploaded picture was replaced: remove its file (only AFTER the save worked).
        if ($newImage && $oldImage !== null) {
            $this->deleteImage($oldImage);
        }

        return $oldImage !== null;
    }

    /**
     * Add new batch (Edit product page): a new delivery with its own batch number and expiry date.
     * Inserts the batch and its stock row in ONE DB::transaction(). No inventory_history row (not a restock).
     */
    public function addBatch(UserAccount $owner, Product $product, array $data): ProductBatch
    {
        return DB::transaction(function () use ($owner, $product, $data) {
            // 1. Lock the product, so it cannot be deleted while its new batch is being saved.
            $product = Product::whereKey($product->product_id)->lockForUpdate()->firstOrFail();

            // 2-4. Batch, stock row with status, alert (if any).
            $batch = $this->createBatchWithStock($product, $data);

            // 5. Audit row, for example "Added batch B-TONER-02 to Toner P-0007, qty 20".
            $this->logProduct($owner, $product,
                "Added batch {$batch->batch_number} to {$product->product_name} {$product->product_number}, qty {$batch->quantity_in_batch}");

            return $batch;
        });
    }

    /**
     * Trash on an Inventory row: delete ONE batch, only if it was never sold and has no stock movements.
     * Also deletes the product when this was its last batch. Throws BatchInUseException when refused.
     * Returns the audit text (also used as the success message).
     */
    public function deleteBatch(UserAccount $owner, Inventory $inventory): string
    {
        $removedImage = null;

        $text = DB::transaction(function () use ($owner, $inventory, &$removedImage) {
            // 1. Lock the stock row and its batch, then check that nothing points to the batch.
            $inventory = Inventory::whereKey($inventory->inventory_id)->lockForUpdate()->firstOrFail();
            $batch = ProductBatch::whereKey($inventory->batch_id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($batch->product_id)->lockForUpdate()->firstOrFail();

            $used = SaleItem::where('batch_id', $batch->batch_id)->exists()
                || InventoryHistory::where('batch_id', $batch->batch_id)->exists();
            if ($used) {
                throw new BatchInUseException('This batch has sales or history and cannot be deleted.');
            }

            $text = "Deleted batch {$batch->batch_number} of {$product->product_name} {$product->product_number} (qty {$inventory->quantity_on_hand})";

            // 2-4. Child rows first (foreign keys): alerts, then the stock row, then the batch.
            $this->alerts->deleteForBatch($batch->batch_id);
            $inventory->delete();
            $batch->delete();

            // 5. No batches left: the product goes too.
            if (! ProductBatch::where('product_id', $product->product_id)->exists()) {
                $removedImage = $product->image_path;
                $product->delete();
                $text .= '; product removed';
            }

            // 6. Audit row.
            $this->logProduct($owner, $product, $text);

            return $text;
        });

        // The product is gone: remove its uploaded picture (only AFTER the delete worked).
        $this->deleteImage($removedImage);

        return $text;
    }

    // Steps 2-4 of Add product and Add new batch: insert the batch and its stock row, work out the status, sync alerts.
    private function createBatchWithStock(Product $product, array $data): ProductBatch
    {
        $batch = ProductBatch::create([
            'product_id' => $product->product_id,
            'batch_number' => $data['batch_number'],
            'expiry_date' => $data['expiry_date'],
            'quantity_in_batch' => (int) $data['quantity'],
            'date_received' => now()->toDateString(),
        ]);

        $inventory = Inventory::create([
            'batch_id' => $batch->batch_id,
            'quantity_on_hand' => (int) $data['quantity'],
            'reorder_level' => (int) $data['reorder_level'],
        ]);
        $inventory->setRelation('batch', $batch); // the status rule needs the expiry date
        $this->status->refresh($inventory);
        $this->alerts->sync($inventory);

        return $batch;
    }

    // Same text for Update price and for a price change on Edit product: "Price Serum: 120.00 to 125.00".
    private function priceText(Product $product, string $old, string $new): string
    {
        return "Price {$product->product_name}: {$old} to {$new}";
    }

    // One INVENTORY_UPDATE audit row about a product. The reference is the product number
    // (60 characters max, transaction_reference holds 50) and details holds 255 characters.
    private function logProduct(UserAccount $owner, Product $product, string $details): void
    {
        $this->audit->log(
            $owner,
            'INVENTORY_UPDATE',
            'INVENTORY',
            Str::limit($product->product_number, 50, ''),
            Str::limit($details, 255, ''),
        );
    }

    // Save an uploaded picture in storage/app/public/products. The path saved in image_path is
    // "storage/products/<name>.jpg", which the browser opens through the public/storage link (storage:link).
    private function storeImage(UploadedFile $image): string
    {
        return 'storage/'.$image->store('products', 'public');
    }

    // Remove an uploaded picture file. Only our own uploads: never the placeholder or other files.
    private function deleteImage(?string $path): void
    {
        if ($path && Str::startsWith($path, 'storage/products/')) {
            Storage::disk('public')->delete(Str::after($path, 'storage/'));
        }
    }

    // Pay Now, step 5 (docs/04-business-rules.md): take $quantity pieces out of one batch.
    public function removeForSale(Inventory $inventory, int $quantity, UserAccount $user): void
    {
        if ($quantity <= 0 || $quantity > $inventory->quantity_on_hand) {
            throw new RuntimeException("Cannot remove {$quantity} pieces from inventory row {$inventory->inventory_id}.");
        }

        $this->move($inventory, -$quantity, 'SALE', $user);
    }

    // Void a Sale (docs/04-business-rules.md): put $quantity pieces back into the SAME batch they were sold from.
    public function returnForVoid(Inventory $inventory, int $quantity, UserAccount $user): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException("Cannot return {$quantity} pieces to inventory row {$inventory->inventory_id}.");
        }

        $this->move($inventory, $quantity, 'VOID', $user);
    }

    // The three steps of every stock change. $change is negative for a sale, positive for a void or restock.
    private function move(Inventory $inventory, int $change, string $type, UserAccount $user): void
    {
        // 1. Change the stock and refresh its status (one UPDATE of the inventory row).
        $inventory->quantity_on_hand += $change;
        $this->status->refresh($inventory);

        // 2. Write the movement (SALE: negative, VOID and RESTOCK: positive).
        InventoryHistory::create([
            'inventory_id' => $inventory->inventory_id,
            'batch_id' => $inventory->batch_id,
            'user_id' => $user->user_id,
            'quantity_change' => $change,
            'transaction_type' => $type,
            'transaction_datetime' => now(),
        ]);

        // 3. Open or close alerts for this batch to match the new status.
        $this->alerts->sync($inventory);
    }

    /**
     * Inventory screen (screen 9): one row per batch, because stock and expiry are kept per batch.
     * READ ONLY: nothing is saved here. The status is the value stored in inventory.stock_status.
     * Sorted by product name, then earliest expiry first.
     */
    public function batchRows(): Collection
    {
        return Inventory::with('batch.product.supplier')
            ->get()
            ->map(fn (Inventory $inventory) => [
                'inventory_id' => $inventory->inventory_id,
                'product_id' => $inventory->batch->product->product_id,
                'product_name' => $inventory->batch->product->product_name,
                'product_number' => $inventory->batch->product->product_number,
                'batch_number' => $inventory->batch->batch_number,
                'unit_price' => (float) $inventory->batch->product->unit_price,
                'quantity' => (int) $inventory->quantity_on_hand,
                'reorder_level' => (int) $inventory->reorder_level,
                'supplier' => $inventory->batch->product->supplier?->supplier_name,
                'status' => $inventory->stock_status,
                'expiry_date' => $inventory->batch->expiry_date,
                'expired' => $this->status->isExpired($inventory->batch->expiry_date),
            ])
            ->sortBy([
                ['product_name', 'asc'],
                fn ($a, $b) => $a['expiry_date'] <=> $b['expiry_date'],
            ])
            ->values();
    }

    /**
     * The three summary cards: how many batches are LOW STOCK, OUT OF STOCK and NEAR EXPIRY,
     * plus the first one of each (in the table order) as a short example line.
     */
    public function summary(Collection $rows): array
    {
        $cards = [];

        foreach ([StockStatusService::LOW, StockStatusService::OUT, StockStatusService::NEAR] as $status) {
            $matching = $rows->where('status', $status);
            $first = $matching->first();

            $cards[$status] = [
                'count' => $matching->count(),
                'first' => $first ? $this->summaryLine($status, $first) : 'None',
            ];
        }

        return $cards;
    }

    // Example line under the count, like the mockup: "Vitamins · 2 left (reorder at 5)".
    private function summaryLine(string $status, array $row): string
    {
        return match ($status) {
            StockStatusService::LOW => "{$row['product_name']} · {$row['quantity']} left (reorder at {$row['reorder_level']})",
            StockStatusService::OUT => "{$row['product_name']} · 0 left",
            default => "{$row['product_name']} · expires {$row['expiry_date']->format('m/d/Y')}",
        };
    }
}
