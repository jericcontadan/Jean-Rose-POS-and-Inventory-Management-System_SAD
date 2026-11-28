<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Session expired (419) goes to the login page with a message, and the friendly 403 page
// has a button back to the user's home (Dashboard for the owner, POS for the cashier).
class ErrorPagesTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    // Laravel skips the CSRF check while tests run. Turn it back on, so a form sent
    // without a valid token really gets 419, like a page left open after the session expired.
    private function checkCsrfInTests(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    // ---------- 419: session expired ----------

    public function test_expired_session_goes_to_login_with_a_message(): void
    {
        $this->checkCsrfInTests();

        // A guest (the session ran out) taps a button on an old POS page: no valid token.
        $this->post('/pos/cart/1')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your session expired. Please log in again.');

        $this->get(route('login'))->assertOk()->assertSee('Your session expired. Please log in again.');
    }

    public function test_log_out_with_an_expired_token_just_logs_out(): void
    {
        $this->checkCsrfInTests();
        $this->actingAs($this->makeUser('CASHIER'));

        $this->post(route('logout'))   // no _token: 419 inside, handled in bootstrap/app.php
            ->assertRedirect(route('login'))
            ->assertSessionMissing('error');

        $this->assertGuest();
    }

    public function test_logged_in_user_with_an_old_page_goes_back_and_stays_logged_in(): void
    {
        $this->checkCsrfInTests();
        $cashier = $this->makeUser('CASHIER');
        $this->actingAs($cashier);

        $this->from(route('pos.index'))->post('/pos/cart/1')
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error', 'This page expired. Please try again.');

        $this->assertAuthenticatedAs($cashier);
    }

    // ---------- 403: friendly page ----------

    public function test_cashier_403_page_links_to_the_pos(): void
    {
        $this->actingAs($this->makeUser('CASHIER'));

        $this->get('/sales')
            ->assertForbidden()
            ->assertSee("You can't open this page", false)
            ->assertSee('Go to POS')
            ->assertSee('href="'.route('pos.index').'"', false);
    }

    public function test_owner_403_page_links_to_the_dashboard(): void
    {
        $this->actingAs($this->makeUser('OWNER'));

        $this->get('/pos')
            ->assertForbidden()
            ->assertSee('Go to Dashboard')
            ->assertSee('href="'.route('dashboard').'"', false);
    }
}
