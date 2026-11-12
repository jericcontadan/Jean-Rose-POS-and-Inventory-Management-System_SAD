<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// The daily 08:00 stock and expiry email to the owner (mockup: docs/design/08-alerts-daily-email.png, right side).
// Sent by AlertService::sendDailyEmail(). The From address and mail server come from .env (config/mail.php).
class DailyStockAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    // $alerts: open alert_notification rows with batch.product and batch.inventory loaded.
    public function __construct(public Collection $alerts)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Jean Rose: stock and expiry alert');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-stock-alert');
    }
}
