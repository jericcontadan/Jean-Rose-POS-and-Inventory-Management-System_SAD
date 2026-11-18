<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// The reset password email (screen 2). Sent by UserAccount::sendPasswordResetNotification().
// $url holds the PLAIN token; it exists only in this email, the database keeps only its hash.
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // Same Gmail address as the daily alert (from .env), shown as "Jean Rose POS".
            from: new Address(config('mail.from.address'), 'Jean Rose POS'),
            subject: 'Jean Rose POS: reset your password',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset');
    }
}
