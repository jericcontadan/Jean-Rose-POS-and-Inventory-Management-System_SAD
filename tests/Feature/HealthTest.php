<?php

namespace Tests\Feature;

use Tests\TestCase;

// Step 0 checks that do NOT need the database.
class HealthTest extends TestCase
{
    public function test_health_returns_ok(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
    }

    public function test_layout_loads_bootstrap_cdn_and_our_css_without_vite(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('name="viewport" content="width=device-width, initial-scale=1"', false);
        $response->assertSee('cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', false);
        $response->assertSee('css/jeanrose.css', false);
        $response->assertDontSee('@vite', false);
        $response->assertDontSee('/build/', false);
    }

    public function test_guest_sees_wordmark_but_no_menu_or_logout(): void
    {
        $response = $this->get('/');

        $response->assertSee('Jean Rose');
        $response->assertSee('BEAUTY PRODUCTS TRADING');
        $response->assertDontSee('Log out');
        $response->assertDontSee('Sales Records');
    }

    public function test_pos_settings_have_defaults(): void
    {
        $this->assertSame(30, config('pos.near_expiry_days'));
        $this->assertEquals(10, config('pos.near_expiry_discount_percent'));
    }
}
