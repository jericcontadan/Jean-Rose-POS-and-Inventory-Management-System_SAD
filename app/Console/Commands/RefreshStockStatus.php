<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Services\AlertService;
use App\Services\StockStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// php artisan pos:refresh-stock-status  (scheduled daily at 06:00 Asia/Manila in routes/console.php)
// Works out stock_status again for EVERY inventory row and syncs its alerts. Needed because NEAR EXPIRY
// changes with the date, and it also fixes missing or old alert rows (for example OUT OF STOCK with no alert).
class RefreshStockStatus extends Command
{
    protected $signature = 'pos:refresh-stock-status';

    protected $description = 'Recalculate stock_status for every inventory row and sync alert_notification';

    public function handle(StockStatusService $status, AlertService $alerts): int
    {
        $checked = 0;
        $changed = 0;

        // lazyById(): reads the rows in small chunks, so a big inventory does not fill the memory.
        foreach (Inventory::with('batch')->lazyById(100, 'inventory_id') as $inventory) {
            $before = $inventory->stock_status;

            // Status and alerts of one row together: both saved or neither.
            DB::transaction(function () use ($status, $alerts, $inventory) {
                $status->refresh($inventory);
                $alerts->sync($inventory);
            });

            $checked++;
            if ($inventory->stock_status !== $before) {
                $changed++;
            }
        }

        $this->info("Checked {$checked} rows, {$changed} status changes.");

        return self::SUCCESS;
    }
}
