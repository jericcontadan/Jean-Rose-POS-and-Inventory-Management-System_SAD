<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Command;

// php artisan pos:send-daily-alerts  (scheduled daily at 08:00 Asia/Manila in routes/console.php)
// Emails the open alerts that were never emailed to every active owner. The rules are in AlertService::sendDailyEmail().
class SendDailyAlertEmail extends Command
{
    protected $signature = 'pos:send-daily-alerts';

    protected $description = 'Email new stock and expiry alerts to the active owners';

    public function handle(AlertService $alerts): int
    {
        $owners = $alerts->sendDailyEmail();

        $this->info($owners > 0
            ? "Alert email sent to {$owners} owner(s)."
            : 'No new alerts (or no active owner), nothing sent.');

        return self::SUCCESS;
    }
}
