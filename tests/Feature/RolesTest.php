<?php

namespace Tests\Feature;

use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// T04 from docs/06-testing.md: owner-only pages are blocked on the SERVER for the cashier.
class RolesTest extends TestCase
{
    use DatabaseTransactions;

    private const OWNER_PAGES = ['/sales', '/inventory', '/audit-log'];

    private function makeUser(string $role): UserAccount
    {
        $tag = Str::lower(Str::random(8));

        return UserAccount::create([
            'email' => "test_{$tag}@example.com",
            'username' => "test_{$tag}",
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => 1,
        ]);
    }

    // T04: cashier gets 403 on every owner page, but can use the POS.
    public function test_cashier_gets_403_on_owner_pages(): void
    {
        $this->actingAs($this->makeUser('CASHIER'));

        foreach (self::OWNER_PAGES as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->get('/pos')->assertOk();
        $this->get('/alerts')->assertOk();
    }

    public function test_owner_can_open_all_pages(): void
    {
        $this->actingAs($this->makeUser('OWNER'));

        foreach ([...self::OWNER_PAGES, '/pos', '/alerts'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
