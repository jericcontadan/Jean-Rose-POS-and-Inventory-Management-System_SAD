<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

// php artisan pos:send-daily-alerts  (scheduled daily at 08:00 Asia/Manila in routes/console.php)
// Emails the open alerts that were never emailed to every active owner. The rules are in AlertService::sendDailyEmail().
class SendDailyAlertEmail extends Command
{
    protected $signature = 'pos:send-daily-alerts';

    protected $description = 'Email new stock and expiry alerts to the active owners';

    public function handle(AlertService $alerts): int
    {
        try {
            $owners = $alerts->sendDailyEmail();
        } catch (TransportExceptionInterface $e) {
            // Gmail SMTP failed. emailed_at is only set AFTER sending, so these alerts stay "not emailed"
            // and the next run tries again. Log it and exit with an error code instead of crashing.
            Log::error('Daily alert email could not be sent.', [
                'error' => get_class($e).': '.$e->getMessage(),
            ]);
            $this->error('Alert email could not be sent: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($owners > 0
            ? "Alert email sent to {$owners} owner(s)."
            : 'No new alerts (or no active owner), nothing sent.');

        return self::SUCCESS;
    }
}
