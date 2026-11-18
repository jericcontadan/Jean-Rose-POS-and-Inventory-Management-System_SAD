<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordResetController;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\Feature\Concerns\FakesMailFailure;
use Tests\TestCase;

// T19 from docs/06-testing.md: Forgot and Reset Password by Gmail link.
// Mail::fake() means no real email is sent. DatabaseTransactions rolls back every row after each test.
class PasswordResetTest extends TestCase
{
    use CreatesPosData, DatabaseTransactions, FakesMailFailure;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // Ask for a reset link for $email (step 1).
    private function requestLink(string $email)
    {
        return $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $email]);
    }

    // The plain token inside the newest reset email sent to $user (it is the last part of the link path).
    private function tokenFromLastMail(UserAccount $user): string
    {
        $mail = Mail::sent(PasswordResetMail::class, fn ($m) => $m->hasTo($user->email))->last();
        $this->assertNotNull($mail, 'No reset email was sent.');

        return basename(parse_url($mail->url, PHP_URL_PATH));
    }

    // Send the new password (step 2).
    private function resetWith(UserAccount $user, string $token, string $password, ?string $confirm = null)
    {
        return $this->from(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => $password,
                'password_confirmation' => $confirm ?? $password,
            ]);
    }

    private function tokenRowFor(string $email): ?object
    {
        return DB::table('password_reset_tokens')->where('email', $email)->first();
    }

    public function test_login_page_has_forgot_password_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Forgot password?')
            ->assertSee(route('password.request'), false);
    }

    public function test_forgot_and_reset_pages_open_for_guests(): void
    {
        $this->get(route('password.request'))->assertOk()->assertSee('Forgot password');
        $this->get(route('password.reset', ['token' => 'abc', 'email' => 'a@example.com']))
            ->assertOk()->assertSee('Reset password');
    }

    public function test_forgot_page_shows_gmail_selected_and_sms_disabled(): void
    {
        $html = $this->get(route('password.request'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="by-email"[^>]*value="email"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input id="by-sms"[^>]*value="sms"[^>]*disabled/', $html);
        $this->assertStringContainsString('SMS reset coming soon', $html);
    }

    public function test_gmail_choice_sends_the_link(): void
    {
        $user = $this->makeUser('OWNER');

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email, 'by' => 'email'])
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_sms_choice_is_rejected_and_sends_nothing(): void
    {
        $user = $this->makeUser('OWNER');

        // The SMS radio is disabled, but someone could still post by=sms by hand.
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email, 'by' => 'sms'])
            ->assertSessionHasErrors('by')
            ->assertSessionMissing('status');

        Mail::assertNothingSent();
        $this->assertNull($this->tokenRowFor($user->email));
    }

    public function test_logged_in_user_is_sent_away_from_reset_pages(): void
    {
        $this->actingAs($this->makeUser('CASHIER'))
            ->get(route('password.request'))
            ->assertRedirect();
    }

    public function test_known_email_sends_one_email_and_stores_only_a_hashed_token(): void
    {
        $user = $this->makeUser('OWNER');

        $this->requestLink($user->email)
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        Mail::assertSent(PasswordResetMail::class, 1);
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->hasFrom(config('mail.from.address'), 'Jean Rose POS');
        });

        // The database keeps a hash, never the plain token from the link.
        $token = $this->tokenFromLastMail($user);
        $row = $this->tokenRowFor($user->email);
        $this->assertNotNull($row);
        $this->assertNotSame($token, $row->token);
        $this->assertTrue(Hash::check($token, $row->token));
    }

    public function test_reset_email_has_the_button_and_the_link(): void
    {
        $mail = new PasswordResetMail('http://localhost/reset-password/abc?email=a%40example.com');

        $mail->assertHasSubject('Jean Rose POS: reset your password');
        $mail->assertSeeInHtml('Reset password');
        $mail->assertSeeInHtml('http://localhost/reset-password/abc?email=a%40example.com', false);
        $mail->assertSeeInHtml('expires in 60 minutes');
    }

    public function test_gmail_failure_shows_the_same_message_logs_it_and_leaves_no_token(): void
    {
        $user = $this->makeUser('OWNER');
        $this->makeMailFail();
        Log::spy();

        // No 500 page: the normal message, as if the email was sent.
        $this->requestLink($user->email)
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE)
            ->assertSessionHasNoErrors();

        // The token saved before the failed send is gone, so no usable link exists.
        $this->assertNull($this->tokenRowFor($user->email));

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Password reset email could not be sent.'
                && str_contains($context['error'], 'UnexpectedResponseException')
        );
    }

    public function test_gmail_failure_also_removes_an_older_token(): void
    {
        $user = $this->makeUser('OWNER');
        $this->requestLink($user->email); // this one is "sent" (Mail::fake)
        $oldToken = $this->tokenFromLastMail($user);

        // A new request replaces the old token, but this time Gmail fails.
        $this->travel(61)->seconds();
        $this->makeMailFail();
        $this->requestLink($user->email)->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        $this->assertNull($this->tokenRowFor($user->email));
        $this->resetWith($user, $oldToken, 'newpass123')
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID_LINK]);
    }

    public function test_unknown_email_shows_the_same_message_and_sends_nothing(): void
    {
        $this->requestLink('nobody_here@example.com')
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE)
            ->assertSessionHasNoErrors();

        Mail::assertNothingSent();
        $this->assertNull($this->tokenRowFor('nobody_here@example.com'));
    }

    public function test_inactive_account_gets_the_same_message_and_no_email(): void
    {
        $user = $this->makeUser('CASHIER');
        $user->update(['is_active' => 0]);

        $this->requestLink($user->email)->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        Mail::assertNothingSent();
    }

    public function test_invalid_email_format_shows_an_error(): void
    {
        $this->requestLink('not-an-email')->assertSessionHasErrors('email');
        Mail::assertNothingSent();
    }

    public function test_expired_token_fails(): void
    {
        $user = $this->makeUser('CASHIER');
        $this->requestLink($user->email);
        $token = $this->tokenFromLastMail($user);

        $this->travel(61)->minutes();

        $this->resetWith($user, $token, 'newpass123')
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID_LINK]);
        $this->assertTrue(Hash::check('secret123', $user->fresh()->password_hash));
    }

    public function test_used_token_fails_the_second_time(): void
    {
        $user = $this->makeUser('CASHIER');
        $this->requestLink($user->email);
        $token = $this->tokenFromLastMail($user);

        $this->resetWith($user, $token, 'newpass123')->assertRedirect(route('login'));
        $this->assertNull($this->tokenRowFor($user->email)); // deleted after use

        $this->resetWith($user, $token, 'another123')
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID_LINK]);
        $this->assertTrue(Hash::check('newpass123', $user->fresh()->password_hash));
    }

    public function test_new_request_replaces_the_old_token(): void
    {
        $user = $this->makeUser('OWNER');
        $this->requestLink($user->email);
        $oldToken = $this->tokenFromLastMail($user);

        // The broker blocks a second link inside 60 seconds (double send), so wait a bit first.
        $this->travel(61)->seconds();
        $this->requestLink($user->email);
        $newToken = $this->tokenFromLastMail($user);

        Mail::assertSent(PasswordResetMail::class, 2);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());

        $this->resetWith($user, $oldToken, 'newpass123')->assertSessionHasErrors('email');
        $this->resetWith($user, $newToken, 'newpass123')->assertRedirect(route('login'));
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = $this->makeUser('CASHIER');
        $this->requestLink($user->email);
        $token = $this->tokenFromLastMail($user);

        $this->resetWith($user, $token, 'newpass123', 'different123')
            ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);
        $this->assertTrue(Hash::check('secret123', $user->fresh()->password_hash));
        $this->assertNotNull($this->tokenRowFor($user->email)); // the link still works
    }

    public function test_password_must_be_at_least_8_characters(): void
    {
        $user = $this->makeUser('CASHIER');
        $this->requestLink($user->email);
        $token = $this->tokenFromLastMail($user);

        $this->resetWith($user, $token, 'short1')
            ->assertSessionHasErrors(['password' => 'The password must be at least 8 characters.']);
        $this->assertTrue(Hash::check('secret123', $user->fresh()->password_hash));
    }

    public function test_successful_reset_changes_password_audits_and_allows_login(): void
    {
        $user = $this->makeUser('CASHIER');
        $this->requestLink($user->email);
        $token = $this->tokenFromLastMail($user);

        $this->resetWith($user, $token, 'newpass123')
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'Your password was changed. Please log in.');

        // Saved as a hash in password_hash, never as plain text.
        $hash = $user->fresh()->password_hash;
        $this->assertNotSame('newpass123', $hash);
        $this->assertTrue(Hash::check('newpass123', $hash));

        $this->assertDatabaseHas('audit_log', [
            'user_id' => $user->user_id,
            'action_type' => 'PASSWORD_RESET',
            'module' => 'SALES',
        ]);

        // The login page shows the green message.
        $this->get(route('login'))->assertSee('Your password was changed. Please log in.');

        // Old password no longer works.
        $this->post(route('login.store'), ['username' => $user->username, 'password' => 'secret123'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();

        // New password works.
        $this->post(route('login.store'), ['username' => $user->username, 'password' => 'newpass123'])
            ->assertRedirect(route('pos.index'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_at_most_3_requests_per_email_per_15_minutes(): void
    {
        $user = $this->makeUser('OWNER');

        // Requests 1-3 are accepted (61 s apart so the broker sends each one).
        for ($i = 0; $i < 3; $i++) {
            $this->requestLink($user->email)->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
            $this->travel(61)->seconds();
        }
        Mail::assertSent(PasswordResetMail::class, 3);

        // Request 4 is blocked and sends nothing.
        $this->requestLink($user->email)
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('status');
        Mail::assertSent(PasswordResetMail::class, 3);

        // An unknown email is limited the same way (so the limit gives nothing away).
        for ($i = 0; $i < 3; $i++) {
            $this->requestLink('nobody_here@example.com');
        }
        $this->requestLink('nobody_here@example.com')->assertSessionHasErrors('email');

        // After 15 minutes it works again.
        $this->travel(15)->minutes();
        $this->requestLink($user->email)->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        Mail::assertSent(PasswordResetMail::class, 4);
    }
}
