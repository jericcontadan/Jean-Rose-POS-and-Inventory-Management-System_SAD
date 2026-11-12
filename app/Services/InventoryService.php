<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\UserAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// The ONLY place that changes inventory.quantity_on_hand (docs/08-architecture.md, rule 2),
// so stock_status, inventory_history and alert_notification always stay in step with the stock.
// Sale and void: call it inside the caller's DB::transaction() with the inventory row already locked (lockForUpdate).
// Restock and price change (screen 10): restock() and changePrice() open their own DB::transaction().
class InventoryService
{
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
            //    product_number holds up to 60 characters, transaction_reference only 50.
            $this->audit->log(
                $owner,
                'INVENTORY_UPDATE',
                'INVENTORY',
                Str::limit($product->product_number, 50, ''),
                "Price {$product->product_name}: {$old} to {$new}",
            );

            return true;
        });
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
