<?php

namespace App\Services;

use App\Models\AlertNotification;
use App\Models\Inventory;

// The ONLY code that writes alert_notification (docs/08-architecture.md, rule 4).
// An "open" alert is a row with resolved_at = null. Each batch has at most ONE open alert per type,
// so each alert is shown once and emailed once (docs/04-business-rules.md, "Alerts and notifications").
// The /alerts page and the daily email come in a later step; this step only keeps the rows correct.
class AlertService
{
    // Run right after an inventory row's stock_status was refreshed.
    public function sync(Inventory $inventory): void
    {
        $status = $inventory->stock_status;
        $openRows = AlertNotification::where('batch_id', $inventory->batch_id)->whereNull('resolved_at');

        // OKAY: nothing to warn about, close every open alert of this batch.
        if ($status === StockStatusService::OKAY) {
            $openRows->update(['resolved_at' => now()]);

            return;
        }

        // LOW STOCK, OUT OF STOCK or NEAR EXPIRY: first close open alerts of another type...
        (clone $openRows)->where('alert_type', '!=', $status)->update(['resolved_at' => now()]);

        // ...then make sure exactly one open alert of this type exists (never a duplicate).
        if (! (clone $openRows)->where('alert_type', $status)->exists()) {
            AlertNotification::create([
                'batch_id' => $inventory->batch_id,
                'alert_type' => $status,
                'created_at' => now(),
            ]);
        }
    }

    // Delete a batch (trash on the Inventory page): its alert rows must go first,
    // because alert_notification.batch_id points to the batch (foreign key RESTRICT).
    public function deleteForBatch(int $batchId): void
    {
        AlertNotification::where('batch_id', $batchId)->delete();
    }
}
