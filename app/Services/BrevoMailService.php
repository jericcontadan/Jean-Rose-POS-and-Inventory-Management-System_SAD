<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Sends every POS email (password reset, daily stock alert) through the Brevo Transactional Email REST API.
// If Brevo fails (bad key, bad request, rate limit, server error or timeout), the SAME email goes out through
// our normal mailer (Gmail SMTP on the server) so the owner still gets it. Details: docs/api/brevo-integration.md.
class BrevoMailService
{
    public const URL = 'https://api.brevo.com/v3/smtp/email';

    public const SENDER_NAME = 'Jean Rose POS';

    // Send one Mailable to one address.
    // Throws TransportExceptionInterface only when Brevo AND SMTP both failed; the callers already catch it
    // (reset: delete the unsent token, same message; daily alert: emailed_at stays empty, retried tomorrow).
    public function send(string $to, Mailable $mail): void
    {
        $subject = $mail->envelope()->subject;

        // Switched to smtp in .env, or no API key: use Gmail SMTP only.
        if (! $this->brevoEnabled()) {
            $this->sendBySmtp($to, $mail);

            return;
        }

        if ($this->sendByBrevo($to, $mail, $subject)) {
            return;
        }

        // Brevo failed: the backup.
        $this->sendBySmtp($to, $mail);
    }

    public function brevoEnabled(): bool
    {
        return config('pos.mail_via') === 'brevo' && filled(config('services.brevo.key'));
    }

    // POST the email to Brevo. Returns true when Brevo accepted it (HTTP 201).
    private function sendByBrevo(string $to, Mailable $mail, string $subject): bool
    {
        try {
            $response = Http::withHeaders(['api-key' => config('services.brevo.key')])
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post(self::URL, [
                    'sender' => ['name' => self::SENDER_NAME, 'email' => config('mail.from.address')],
                    'to' => [['email' => $to]],
                    'subject' => $subject,
                    'htmlContent' => $mail->render(), // the same Blade view the SMTP email uses
                ]);
        } catch (ConnectionException $e) {
            // Timeout after 10 seconds, or no internet / DNS problem.
            Log::warning('Brevo API unreachable, falling back to SMTP.', [
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->status() === 201) {
            Log::info('Email sent through Brevo API.', [
                'subject' => $subject,
                'messageId' => $response->json('messageId'),
            ]);

            return true;
        }

        // 400 bad request, 401 bad key, 429 rate limit, 5xx Brevo down. Log Brevo's own error text
        // (never the API key, never the email body, because the reset email holds the plain token).
        Log::warning('Brevo API rejected the email, falling back to SMTP.', [
            'subject' => $subject,
            'status' => $response->status(),
            'code' => $response->json('code'),
            'message' => $response->json('message'),
        ]);

        return false;
    }

    // Send through the default mailer (Gmail SMTP on the server, log/Mailpit locally, array in tests).
    // If SMTP fails it throws TransportExceptionInterface; the caller catches it, logs it once and decides
    // what happens (see the comment on send()).
    private function sendBySmtp(string $to, Mailable $mail): void
    {
        Mail::to($to)->send($mail);
    }
}
