<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordResetController;
use App\Mail\DailyStockAlertMail;
use App\Mail\PasswordResetMail;
use App\Models\AlertNotification;
use App\Services\BrevoMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\Feature\Concerns\FakesMailFailure;
use Tests\TestCase;

// Brevo Transactional Email API with Gmail SMTP as backup (docs/api/brevo-integration.md).
// Http::fake() answers instead of the real Brevo server, Mail::fake() catches the SMTP backup email.
// preventStrayRequests() makes a test fail if it ever tries to reach the real internet.
class BrevoMailTest extends TestCase
{
    use CreatesPosData, DatabaseTransactions, FakesMailFailure;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.brevo.key' => 'test-key',
            'pos.mail_via' => 'brevo',
            'mail.from.address' => 'jeanrosepos@gmail.com',
        ]);
        Http::preventStrayRequests();
        Mail::fake();
    }

    // Brevo answers every request with this status and JSON body.
    private function brevoAnswers(int $status, array $body): void
    {
        Http::fake([BrevoMailService::URL => Http::response($body, $status)]);
    }

    private function sendResetMail(string $to = 'owner@example.com'): void
    {
        app(BrevoMailService::class)->send($to, new PasswordResetMail('http://localhost/reset-password/abc'));
    }

    public function test_201_sends_by_brevo_logs_message_id_and_sends_no_smtp_mail(): void
    {
        $this->brevoAnswers(201, ['messageId' => '<202611180800.123@smtp-relay.mailin.fr>']);
        Log::spy();

        $this->sendResetMail();

        Http::assertSentCount(1);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Email sent through Brevo API.'
                && $context['messageId'] === '<202611180800.123@smtp-relay.mailin.fr>'
        );
    }

    public function test_request_has_url_api_key_header_and_json_body(): void
    {
        $this->brevoAnswers(201, ['messageId' => '<id@brevo>']);

        $this->sendResetMail('cashier@example.com');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->method() === 'POST'
                && $request->hasHeader('api-key', 'test-key')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request['sender'] === ['name' => 'Jean Rose POS', 'email' => 'jeanrosepos@gmail.com']
                && $request['to'] === [['email' => 'cashier@example.com']]
                && $request['subject'] === 'Jean Rose POS: reset your password'
                && str_contains($request['htmlContent'], 'http://localhost/reset-password/abc');
        });
    }

    public function test_401_bad_key_falls_back_to_smtp_and_logs_brevo_error_without_the_key(): void
    {
        $this->brevoAnswers(401, ['code' => 'unauthorized', 'message' => 'Key not found']);
        Log::spy();

        $this->sendResetMail();

        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['status'] === 401
                && $context['message'] === 'Key not found'
                && ! str_contains(json_encode($context), 'test-key')
        );
    }

    public function test_500_server_error_falls_back_to_smtp(): void
    {
        $this->brevoAnswers(500, ['code' => 'internal_error', 'message' => 'Internal server error']);

        $this->sendResetMail();

        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_timeout_falls_back_to_smtp(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds'));
        Log::spy();

        $this->sendResetMail();

        Mail::assertSent(PasswordResetMail::class, 1);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => $message === 'Brevo API unreachable, falling back to SMTP.'
        );
    }

    public function test_smtp_setting_or_missing_key_skips_brevo(): void
    {
        Http::fake();

        config(['pos.mail_via' => 'smtp']);
        $this->sendResetMail();

        config(['pos.mail_via' => 'brevo', 'services.brevo.key' => null]);
        $this->sendResetMail();

        Http::assertNothingSent();
        Mail::assertSent(PasswordResetMail::class, 2);
    }

    public function test_forgot_password_goes_through_brevo(): void
    {
        $this->brevoAnswers(201, ['messageId' => '<id@brevo>']);
        $user = $this->makeUser('CASHIER');

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        Http::assertSent(fn (Request $request) => $request['to'] === [['email' => $user->email]]
            && $request['subject'] === 'Jean Rose POS: reset your password');
        Mail::assertNothingSent();
    }

    public function test_brevo_and_smtp_both_fail_on_forgot_password_shows_same_message_and_deletes_token(): void
    {
        $this->brevoAnswers(500, ['message' => 'Internal server error']);
        $user = $this->makeUser('CASHIER');
        $this->makeMailFail(); // SMTP fails too

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        $this->assertNull(DB::table('password_reset_tokens')->where('email', $user->email)->first());
    }

    public function test_daily_alert_goes_through_brevo_and_sets_emailed_at(): void
    {
        $this->brevoAnswers(201, ['messageId' => '<id@brevo>']);
        $owner = $this->makeUser('OWNER');
        $alert = $this->makeOpenAlert();

        $this->artisan('pos:send-daily-alerts')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request['to'] === [['email' => $owner->email]]
            && $request['subject'] === 'Jean Rose: stock and expiry alert');
        Mail::assertNotSent(DailyStockAlertMail::class);
        $this->assertNotNull($alert->fresh()->emailed_at);
    }

    public function test_daily_alert_brevo_and_smtp_both_fail_keeps_alert_not_emailed(): void
    {
        $this->brevoAnswers(429, ['code' => 'too_many_requests', 'message' => 'Rate limit exceeded']);
        $this->makeUser('OWNER');
        $alert = $this->makeOpenAlert();
        $this->makeMailFail();

        $this->artisan('pos:send-daily-alerts')->assertFailed();

        $this->assertNull($alert->fresh()->emailed_at);
    }

    // A product with one batch below its reorder level; the refresh command makes its open LOW STOCK alert.
    private function makeOpenAlert(): AlertNotification
    {
        $product = $this->makeProduct('Brevo Test Toner', 150, [[200, 1, 5]]);
        $this->artisan('pos:refresh-stock-status')->assertSuccessful();

        return AlertNotification::where('batch_id', $product->batches()->first()->batch_id)
            ->whereNull('resolved_at')->firstOrFail();
    }
}
