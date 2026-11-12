<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\UserAccount;
use Illuminate\Support\Collection;
use RuntimeException;

// The ONLY place that changes inventory.quantity_on_hand (docs/08-architecture.md, rule 2),
// so stock_status, inventory_history and alert_notification always stay in step with the stock.
// Call it inside the caller's DB::transaction() with the inventory row already locked (lockForUpdate).
class InventoryService
{
    public function __construct(
        private StockStatusService $status,
        private AlertService $alerts,
    ) {
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

    // The three steps of every stock change. $change is negative for a sale, positive for a void.
    private function move(Inventory $inventory, int $change, string $type, UserAccount $user): void
    {
        // 1. Change the stock and refresh its status (one UPDATE of the inventory row).
        $inventory->quantity_on_hand += $change;
        $this->status->refresh($inventory);

        // 2. Write the movement (SALE: negative, VOID: positive).
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
