<?php

namespace Tests\Feature;

use App\Http\Controllers\AuditLogController;
use App\Models\AuditLog;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Audit Log (screen 13, Step 6): owner only, read only, filters by date, user and action, newest first.
// Rows are made directly here with a fixed created_at, so dates and order are exact.
// The dates are in 2030 so they never mix with other rows in the test database.
// DatabaseTransactions rolls everything back after each test.
class AuditLogTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;
    private UserAccount $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeUser('OWNER');
        $this->cashier = $this->makeUser('CASHIER');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // undo any fixed "now"
        parent::tearDown();
    }

    // ---------- helper ----------

    // One audit row at a fixed time. The reference is used to find the row on the page.
    private function row(UserAccount $user, string $createdAt, string $action, ?string $ref, ?string $details = 'test'): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user->user_id,
            'action_type' => $action,
            'module' => $action === 'INVENTORY_UPDATE' ? 'INVENTORY' : 'SALES',
            'transaction_reference' => $ref,
            'details' => $details,
            'created_at' => $createdAt,
        ]);
    }

    // ---------- T18: read only ----------

    public function test_t18_no_route_can_edit_or_delete_the_audit_log(): void
    {
        // Every route about the audit log is GET (HEAD comes with GET) only.
        $auditRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'audit'));

        $this->assertNotEmpty($auditRoutes);
        foreach ($auditRoutes as $route) {
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods(), "Route {$route->uri()} must be read only.");
        }

        // Trying to change or delete a row does nothing.
        $row = $this->row($this->owner, '2030-01-10 09:00:00', 'SALE', 'S-9001', 'original');
        $id = $row->audit_log_id;

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->$method('/audit-log', ['details' => 'changed'])->assertStatus(405);
            $this->assertContains($this->$method("/audit-log/{$id}", ['details' => 'changed'])->status(), [404, 405]);
        }

        $this->assertSame('original', AuditLog::find($id)?->details);

        // The controller has only index(): no store, update or destroy.
        $methods = collect((new ReflectionClass(AuditLogController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn ($method) => $method->class === AuditLogController::class && $method->name !== '__construct')
            ->pluck('name')
            ->all();
        $this->assertSame(['index'], $methods);
    }

    public function test_page_has_no_edit_or_delete_buttons(): void
    {
        $this->row($this->owner, '2030-01-10 09:00:00', 'SALE', 'S-9002');

        $content = $this->get('/audit-log?from=2030-01-10')->assertOk()->getContent();

        // The only POST form on the page is Log out (in the header).
        $this->assertSame(1, substr_count($content, 'method="POST"'));
        $this->assertStringNotContainsString('name="_method"', $content);
        $this->assertStringNotContainsString('>Edit<', $content);
        $this->assertStringNotContainsString('>Delete<', $content);
    }

    // ---------- access ----------

    public function test_cashier_gets_403(): void
    {
        $this->actingAs($this->cashier);

        $this->get('/audit-log')->assertForbidden();
    }

    // ---------- filters ----------

    public function test_default_date_is_today_in_manila(): void
    {
        // 11:30 PM in Manila (still 3:30 PM UTC): "today" must be the Manila date.
        Carbon::setTestNow(Carbon::parse('2030-02-20 23:30:00', 'Asia/Manila'));

        $this->row($this->owner, '2030-02-19 23:59:00', 'SALE', 'R-YESTERDAY');
        $this->row($this->owner, '2030-02-20 23:00:00', 'SALE', 'R-TODAY');
        $this->row($this->owner, '2030-02-21 00:30:00', 'SALE', 'R-TOMORROW');

        $this->get('/audit-log')
            ->assertOk()
            ->assertSee('value="2030-02-20"', false)
            ->assertSee('R-TODAY')
            ->assertDontSee('R-YESTERDAY')
            ->assertDontSee('R-TOMORROW');
    }

    public function test_from_and_to_filter_a_date_range(): void
    {
        $this->row($this->owner, '2030-01-04 23:59:59', 'SALE', 'R-BEFORE');
        $this->row($this->owner, '2030-01-05 00:00:00', 'SALE', 'R-FIRST-DAY');
        $this->row($this->owner, '2030-01-07 23:59:59', 'SALE', 'R-LAST-DAY');
        $this->row($this->owner, '2030-01-08 00:00:00', 'SALE', 'R-AFTER');

        $this->get('/audit-log?from=2030-01-05&to=2030-01-07')
            ->assertOk()
            ->assertSee(['R-FIRST-DAY', 'R-LAST-DAY'])
            ->assertDontSee('R-BEFORE')
            ->assertDontSee('R-AFTER');

        // Only one date filled: that one day.
        $this->get('/audit-log?to=2030-01-05')
            ->assertOk()
            ->assertSee('R-FIRST-DAY')
            ->assertDontSee('R-LAST-DAY');
    }

    public function test_user_filter(): void
    {
        $this->row($this->owner, '2030-01-11 09:00:00', 'SALE', 'R-OWNER');
        $this->row($this->cashier, '2030-01-11 10:00:00', 'SALE', 'R-CASHIER');

        $this->get("/audit-log?from=2030-01-11&user={$this->cashier->user_id}")
            ->assertOk()
            ->assertSee('R-CASHIER')
            ->assertDontSee('R-OWNER');
    }

    public function test_action_filter(): void
    {
        $this->row($this->owner, '2030-01-12 09:00:00', 'SALE', 'R-SALE');
        $this->row($this->owner, '2030-01-12 10:00:00', 'VOID', 'R-VOID');
        $this->row($this->owner, '2030-01-12 11:00:00', 'INVENTORY_UPDATE', 'R-INV');

        $this->get('/audit-log?from=2030-01-12&action=VOID')
            ->assertOk()
            ->assertSee('R-VOID')
            ->assertDontSee('R-SALE')
            ->assertDontSee('R-INV');
    }

    public function test_date_user_and_action_filters_work_together(): void
    {
        $this->row($this->cashier, '2030-01-13 09:00:00', 'SALE', 'R-MATCH');          // date + user + action
        $this->row($this->cashier, '2030-01-14 09:00:00', 'SALE', 'R-OTHER-DAY');      // wrong date
        $this->row($this->owner, '2030-01-13 09:00:00', 'SALE', 'R-OTHER-USER');       // wrong user
        $this->row($this->cashier, '2030-01-13 09:00:00', 'LOGIN', 'R-OTHER-ACTION');  // wrong action

        $this->get("/audit-log?from=2030-01-13&to=2030-01-13&user={$this->cashier->user_id}&action=SALE")
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
            ->assertSee('R-MATCH')
            ->assertDontSee('R-OTHER-DAY')
            ->assertDontSee('R-OTHER-USER')
            ->assertDontSee('R-OTHER-ACTION');
    }

    public function test_bad_filters_are_refused(): void
    {
        $this->get('/audit-log?from=abc')->assertSessionHasErrors('from');
        $this->get('/audit-log?from=2030-01-10&to=2030-01-09')->assertSessionHasErrors('to');
        $this->get('/audit-log?action=DELETE')->assertSessionHasErrors('action');
        $this->get('/audit-log?user=999999999')->assertSessionHasErrors('user');
    }

    // ---------- table ----------

    public function test_newest_records_first(): void
    {
        $this->row($this->owner, '2030-01-15 08:00:00', 'SALE', 'R-0800');
        $this->row($this->owner, '2030-01-15 09:00:00', 'SALE', 'R-0900-FIRST');
        $this->row($this->owner, '2030-01-15 09:00:00', 'SALE', 'R-0900-SECOND'); // same time, saved later

        $this->get('/audit-log?from=2030-01-15')
            ->assertOk()
            ->assertSeeInOrder(['R-0900-SECOND', 'R-0900-FIRST', 'R-0800'])
            ->assertSee('01/15/2030 09:00')
            ->assertSee('Read-only list. Newest records first.');
    }

    public function test_empty_reference_shows_a_dash(): void
    {
        $this->row($this->cashier, '2030-01-16 08:55:00', 'LOGIN', null, 'Signed in');

        $this->get('/audit-log?from=2030-01-16')
            ->assertOk()
            ->assertSeeInOrder(['LOGIN', 'SALES', '<td class="text-nowrap">-</td>', 'Signed in'], false);
    }

    public function test_more_than_50_rows_are_paginated_and_links_keep_the_filters(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->row($this->owner, '2030-01-20 08:00:00', 'SALE', 'R-PAGE');
        }

        $this->get('/audit-log?from=2030-01-20&to=2030-01-20&action=SALE')
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 50 && $rows->total() === 51)
            ->assertSee('from=2030-01-20&amp;to=2030-01-20&amp;action=SALE&amp;page=2', false);

        $this->get('/audit-log?from=2030-01-20&to=2030-01-20&action=SALE&page=2')
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
    }

    // ---------- read only: no database writes ----------

    public function test_opening_the_page_writes_nothing(): void
    {
        $this->row($this->owner, '2030-01-18 09:00:00', 'SALE', 'S-9003');
        $this->row($this->cashier, '2030-01-18 10:00:00', 'LOGIN', null);
        $before = AuditLog::count();

        // Record every SQL statement that changes data
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->get('/audit-log')->assertOk();
        $this->get("/audit-log?from=2030-01-18&to=2030-01-18&user={$this->owner->user_id}&action=SALE&page=1")->assertOk();

        $this->assertSame([], $writes, 'The Audit Log page must not write to the database.');
        $this->assertSame($before, AuditLog::count());
    }

    // ---------- export (later step) ----------

    public function test_export_is_shown_but_disabled(): void
    {
        $this->get('/audit-log')
            ->assertOk()
            ->assertSee('disabled>Export Excel</button>', false)
            ->assertSee('disabled>Download .xlsx</button>', false)
            ->assertSee('Export to Excel');

        // Excel export is built later: no export route yet.
        $exportRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'export'));
        $this->assertCount(0, $exportRoutes);
    }
}
