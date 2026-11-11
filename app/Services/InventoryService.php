<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\UserAccount;
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

        // 1. Lower the stock and refresh its status (one UPDATE of the inventory row).
        $inventory->quantity_on_hand -= $quantity;
        $this->status->refresh($inventory);

        // 2. Write the movement: negative quantity, type SALE.
        InventoryHistory::create([
            'inventory_id' => $inventory->inventory_id,
            'batch_id' => $inventory->batch_id,
            'user_id' => $user->user_id,
            'quantity_change' => -$quantity,
            'transaction_type' => 'SALE',
            'transaction_datetime' => now(),
        ]);

        // 3. Open or close alerts for this batch to match the new status.
        $this->alerts->sync($inventory);
    }
}
