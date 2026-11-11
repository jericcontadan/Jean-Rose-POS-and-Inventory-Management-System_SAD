<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\AuditLog;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

// T01-T03 from docs/06-testing.md, plus rate limit and logout.
// Test users live only inside each test: DatabaseTransactions rolls them back.
class LoginTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role, bool $active = true): UserAccount
    {
        $tag = Str::lower(Str::random(8));

        return UserAccount::create([
            'email' => "test_{$tag}@example.com",
            'username' => "test_{$tag}",
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function loginRowsFor(UserAccount $user): int
    {
        return AuditLog::where('user_id', $user->user_id)->where('action_type', 'LOGIN')->count();
    }

    // T01: valid owner -> redirect to POS and one LOGIN audit row.
    public function test_owner_can_log_in_and_login_is_audited(): void
    {
        $owner = $this->makeUser('OWNER');

        $response = $this->post('/login', ['username' => $owner->username, 'password' => 'secret123']);

        $response->assertRedirect('/pos');
        $this->assertAuthenticatedAs($owner);

        $row = AuditLog::where('user_id', $owner->user_id)->where('action_type', 'LOGIN')->sole();
        $this->assertSame('SALES', $row->module);
    }

    // T02: wrong password -> generic error, not logged in, no audit row.
    public function test_wrong_password_shows_generic_error(): void
    {
        $owner = $this->makeUser('OWNER');

        $response = $this->from('/login')
            ->post('/login', ['username' => $owner->username, 'password' => 'wrong-pass']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['username' => AuthController::GENERIC_ERROR]);
        $response->assertSessionMissing('_old_input.password'); // password is never sent back
        $this->assertGuest();
        $this->assertSame(0, $this->loginRowsFor($owner));
    }

    // Unknown username gets the SAME message (no hint that the user does not exist).
    public function test_unknown_username_shows_same_generic_error(): void
    {
        $response = $this->from('/login')
            ->post('/login', ['username' => 'nobody_'.Str::random(8), 'password' => 'secret123']);

        $response->assertSessionHasErrors(['username' => AuthController::GENERIC_ERROR]);
        $this->assertGuest();
    }

    // T03: inactive account with the right password -> blocked with the same message.
    public function test_inactive_account_is_blocked(): void
    {
        $cashier = $this->makeUser('CASHIER', active: false);

        $response = $this->from('/login')
            ->post('/login', ['username' => $cashier->username, 'password' => 'secret123']);

        $response->assertSessionHasErrors(['username' => AuthController::GENERIC_ERROR]);
        $this->assertGuest();
        $this->assertSame(0, $this->loginRowsFor($cashier));
    }

    // 5 tries per minute: after 5 wrong tries even the right password is refused.
    public function test_login_is_locked_after_five_wrong_tries(): void
    {
        $owner = $this->makeUser('OWNER');
        RateLimiter::clear(Str::lower($owner->username).'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $owner->username, 'password' => 'wrong-pass']);
        }

        $response = $this->from('/login')
            ->post('/login', ['username' => $owner->username, 'password' => 'secret123']);

        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs($this->makeUser('CASHIER'));

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/pos')->assertRedirect('/login');
        $this->get('/alerts')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_shows_the_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('name="_token"', false); // @csrf is on the form
    }
}
