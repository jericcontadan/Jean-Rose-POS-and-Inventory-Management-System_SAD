<?php

namespace App\Services;

use App\Mail\DailyStockAlertMail;
use App\Models\AlertNotification;
use App\Models\Inventory;
use App\Models\UserAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

// The ONLY code that writes alert_notification (docs/08-architecture.md, rule 4).
// An "open" alert is a row with resolved_at = null. Each batch has at most ONE open alert per type,
// so each alert is shown once and emailed once (docs/04-business-rules.md, "Alerts and notifications").
class AlertService
{
    // The order of the groups on the /alerts page and in the email.
    public const TYPES = [StockStatusService::LOW, StockStatusService::OUT, StockStatusService::NEAR];

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

    // Number on the Alerts button and in the "Alerts (N)" title: every open row.
    public function openCount(): int
    {
        return AlertNotification::whereNull('resolved_at')->count();
    }

    // Open alerts for the /alerts page, grouped by type: ['LOW STOCK' => [...], 'OUT OF STOCK' => [...], 'NEAR EXPIRY' => [...]].
    // Empty groups are left out.
    public function openGrouped(): array
    {
        $alerts = $this->openAlerts(AlertNotification::whereNull('resolved_at'));

        $groups = [];
        foreach (self::TYPES as $type) {
            $rows = $alerts->where('alert_type', $type)->values();
            if ($rows->isNotEmpty()) {
                $groups[$type] = $rows;
            }
        }

        return $groups;
    }

    // Daily 08:00 email (pos:send-daily-alerts): send every active OWNER the open alerts that were never emailed,
    // then set emailed_at on them. Nothing new, or no active owner: send nothing and change nothing.
    // Returns how many owners were emailed.
    public function sendDailyEmail(): int
    {
        $alerts = $this->openAlerts(AlertNotification::whereNull('resolved_at')->whereNull('emailed_at'));
        if ($alerts->isEmpty()) {
            return 0;
        }

        $owners = UserAccount::where('role', 'OWNER')->where('is_active', 1)->whereNotNull('email')->get();
        if ($owners->isEmpty()) {
            return 0; // keep emailed_at empty so tomorrow's run tries again
        }

        // Mail settings (log mailer locally, Gmail SMTP on the server) come from .env through config/mail.php.
        foreach ($owners as $owner) {
            Mail::to($owner->email)->send(new DailyStockAlertMail($alerts));
        }

        // Mark only the rows that were in this email (a new alert made meanwhile goes out tomorrow).
        AlertNotification::whereIn('alert_id', $alerts->pluck('alert_id'))->update(['emailed_at' => now()]);

        return $owners->count();
    }

    // Delete a batch (trash on the Inventory page): its alert rows must go first,
    // because alert_notification.batch_id points to the batch (foreign key RESTRICT).
    public function deleteForBatch(int $batchId): void
    {
        AlertNotification::where('batch_id', $batchId)->delete();
    }

    // Load the alerts with their batch, product and stock row, sorted by product name then batch.
    private function openAlerts(Builder $query): Collection
    {
        return $query->with('batch.product', 'batch.inventory')->get()
            ->sortBy(fn (AlertNotification $alert) => $alert->batch->product->product_name.' '.$alert->batch->batch_number, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
