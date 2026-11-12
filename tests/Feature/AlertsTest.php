<?php

namespace Tests\Feature;

use App\Mail\DailyStockAlertMail;
use App\Models\AlertNotification;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\UserAccount;
use App\Services\AlertService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Alerts + daily email (extra 8): T21 and T23 from docs/06-testing.md, plus: /alerts count matches the header,
// cashier View links never point to owner pages, the 06:00 refresh fixes missing and old alerts, the email is sent
// once, and inactive owners get no email. DatabaseTransactions rolls everything back after each test.
// The test database may already hold alerts, so counts are compared with AlertService::openCount(), never fixed numbers.
class AlertsTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;
    private UserAccount $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed rules for the tests, whatever .env says.
        config(['pos.near_expiry_days' => 30, 'pos.near_expiry_discount_percent' => 10]);

        $this->owner = $this->makeUser('OWNER');
        $this->cashier = $this->makeUser('CASHIER');
    }

    // ---------- helpers ----------

    // A product name no other row in the test database has.
    private function uniqueName(string $name): string
    {
        return $name.' '.Str::upper(Str::random(5));
    }

    // The only inventory row of a product made with one batch.
    private function stockRow(Product $product): Inventory
    {
        return $product->batches()->first()->inventory()->with('batch')->first();
    }

    // Open alerts of a product's first batch.
    private function openAlerts(Product $product)
    {
        return AlertNotification::where('batch_id', $product->batches()->first()->batch_id)->whereNull('resolved_at')->get();
    }

    // Three products like the mockup (low, out, near expiry), with their alerts made by the refresh command.
    private function makeMockupAlerts(): array
    {
        $products = [
            'low' => $this->makeProduct($this->uniqueName('Test Vitamins'), 90, [[300, 2, 5]]),
            'out' => $this->makeProduct($this->uniqueName('Test Lotion'), 150, [[300, 0, 5]]),
            'near' => $this->makeProduct($this->uniqueName('Test Serum'), 120, [[10, 10, 2]]),
        ];
        $this->artisan('pos:refresh-stock-status')->assertSuccessful();

        return $products;
    }

    private function refresh(): void
    {
        $this->artisan('pos:refresh-stock-status')->assertSuccessful();
    }

    private function sendDaily(): void
    {
        $this->artisan('pos:send-daily-alerts')->assertSuccessful();
    }

    // ---------- /alerts page ----------

    public function test_alerts_page_lists_open_alerts_grouped_with_product_batch_quantity_and_expiry(): void
    {
        $p = $this->makeMockupAlerts();
        $serumExpiry = $p['near']->batches()->first()->expiry_date->format('m/d/Y');

        $response = $this->actingAs($this->owner)->get('/alerts')->assertOk();

        // Groups in the fixed order LOW STOCK, OUT OF STOCK, NEAR EXPIRY.
        $response->assertSeeInOrder(['LOW STOCK (', 'OUT OF STOCK (', 'NEAR EXPIRY ('], false);
        $response->assertSee($p['low']->product_name.' · 2 left');
        $response->assertSee($p['out']->product_name.' · 0 left');
        $response->assertSee($p['near']->product_name.' · 10 left');
        $response->assertSee('Batch B0 · expires '.$serumExpiry);
        $response->assertSee('Who gets what');

        $this->actingAs($this->cashier)->get('/alerts')->assertOk()->assertSee($p['out']->product_name);
    }

    public function test_alerts_title_count_matches_the_header_count(): void
    {
        $this->makeMockupAlerts();
        $open = app(AlertService::class)->openCount();
        $this->assertSame($open, AlertNotification::whereNull('resolved_at')->count());

        $this->actingAs($this->owner)->get('/alerts')
            ->assertSee('Alerts ('.$open.')')
            ->assertSee('<span class="jr-alerts-count">'.$open.'</span>', false);

        // Every page shows the same number in the header.
        foreach (['/pos', '/inventory', '/sales'] as $url) {
            $this->actingAs($this->owner)->get($url)->assertSee('<span class="jr-alerts-count">'.$open.'</span>', false);
        }
        $this->actingAs($this->cashier)->get('/pos')->assertSee('<span class="jr-alerts-count">'.$open.'</span>', false);
    }

    public function test_cashier_view_links_go_to_pos_and_never_to_owner_pages(): void
    {
        $p = $this->makeMockupAlerts();

        $html = $this->actingAs($this->cashier)->get('/alerts')->assertOk()->getContent();

        // Every link on the cashier's page: none points to an owner page.
        preg_match_all('/href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            foreach (['/inventory', '/sales', '/audit-log', '/products'] as $ownerPage) {
                $this->assertStringNotContainsString($ownerPage, $href, "Cashier page links to owner page: {$href}");
            }
        }

        // The View button searches the POS for the product.
        $this->assertStringContainsString(e(route('pos.index', ['q' => $p['out']->product_name])), $html);
    }

    public function test_owner_view_links_go_to_inventory_search(): void
    {
        $p = $this->makeMockupAlerts();

        $this->actingAs($this->owner)->get('/alerts')
            ->assertSee(e(route('inventory.index', ['q' => $p['out']->product_name])), false)
            ->assertDontSee(e(route('pos.index', ['q' => $p['out']->product_name])), false);
    }

    // ---------- pos:refresh-stock-status ----------

    public function test_refresh_creates_the_missing_alert_and_resolves_it_when_stock_is_back(): void
    {
        // Like Lotion today: 0 on hand but stock_status still OKAY and no alert row.
        $lotion = $this->makeProduct($this->uniqueName('Test Lotion'), 150, [[300, 0, 5]]);
        $this->assertSame('OKAY', $this->stockRow($lotion)->stock_status);
        $this->assertCount(0, $this->openAlerts($lotion));

        $this->refresh();

        $this->assertSame('OUT OF STOCK', $this->stockRow($lotion)->stock_status);
        $this->assertSame(['OUT OF STOCK'], $this->openAlerts($lotion)->pluck('alert_type')->all());

        // Stock back to OKAY (set here as if fixed outside the app): the next run resolves the alert.
        Inventory::where('inventory_id', $this->stockRow($lotion)->inventory_id)->update(['quantity_on_hand' => 20]);
        $this->refresh();

        $this->assertSame('OKAY', $this->stockRow($lotion)->stock_status);
        $this->assertCount(0, $this->openAlerts($lotion));
        $this->assertNotNull(AlertNotification::where('batch_id', $lotion->batches()->first()->batch_id)->first()->resolved_at);
    }

    public function test_refresh_creates_near_expiry_alert(): void
    {
        $serum = $this->makeProduct($this->uniqueName('Test Serum'), 120, [[10, 50, 5]]);

        $this->refresh();

        $this->assertSame('NEAR EXPIRY', $this->stockRow($serum)->stock_status);
        $this->assertSame(['NEAR EXPIRY'], $this->openAlerts($serum)->pluck('alert_type')->all());
    }

    // ---------- T23: alert tracking ----------

    public function test_t23_low_stock_one_open_row_no_duplicate_and_restock_resolves(): void
    {
        // 3 pieces, reorder level 5, expiry far away -> LOW STOCK.
        $toner = $this->makeProduct($this->uniqueName('Test Toner'), 100, [[300, 3, 5]]);
        $batchId = $toner->batches()->first()->batch_id;

        $this->refresh();
        $this->assertSame(['LOW STOCK'], $this->openAlerts($toner)->pluck('alert_type')->all());

        // Running again: still one row, no duplicate.
        $this->refresh();
        $this->assertSame(1, AlertNotification::where('batch_id', $batchId)->count());

        // Restock to OKAY on the Update stock screen's service: the alert gets resolved_at.
        app(InventoryService::class)->restock($this->owner, $this->stockRow($toner), 20);

        $this->assertSame('OKAY', $this->stockRow($toner)->stock_status);
        $this->assertCount(0, $this->openAlerts($toner));
        $this->assertSame(1, AlertNotification::where('batch_id', $batchId)->whereNotNull('resolved_at')->count());
    }

    // ---------- T21: daily alert email ----------

    public function test_t21_email_sent_once_to_active_owner_and_emailed_at_set(): void
    {
        Mail::fake();
        $p = $this->makeMockupAlerts();
        $lotionAlert = $this->openAlerts($p['out'])->first();
        $toOwner = fn (DailyStockAlertMail $mail) => $mail->hasTo($this->owner->email);

        $this->sendDaily();

        Mail::assertSent(DailyStockAlertMail::class, fn (DailyStockAlertMail $mail) => $toOwner($mail)
            && $mail->alerts->contains('alert_id', $lotionAlert->alert_id));
        $this->assertNotNull($lotionAlert->fresh()->emailed_at);
        $this->assertSame(0, AlertNotification::whereNull('resolved_at')->whereNull('emailed_at')->count());

        // Second run: nothing new, so nothing more is sent.
        $this->sendDaily();
        $this->assertCount(1, Mail::sent(DailyStockAlertMail::class, $toOwner));
    }

    public function test_no_new_alerts_sends_nothing(): void
    {
        Mail::fake();
        // Every open alert already emailed (test setup only; rolled back after the test).
        AlertNotification::whereNull('resolved_at')->whereNull('emailed_at')->update(['emailed_at' => now()]);

        $this->sendDaily();

        Mail::assertNothingSent();
    }

    public function test_inactive_owner_and_cashier_get_no_email(): void
    {
        Mail::fake();
        $inactiveOwner = $this->makeUser('OWNER');
        $inactiveOwner->update(['is_active' => 0]);
        $this->makeMockupAlerts();

        $this->sendDaily();

        Mail::assertSent(DailyStockAlertMail::class, fn (DailyStockAlertMail $mail) => $mail->hasTo($this->owner->email));
        Mail::assertNotSent(DailyStockAlertMail::class, fn (DailyStockAlertMail $mail) => $mail->hasTo($inactiveOwner->email));
        Mail::assertNotSent(DailyStockAlertMail::class, fn (DailyStockAlertMail $mail) => $mail->hasTo($this->cashier->email));
    }

    public function test_email_has_subject_and_one_line_per_alert(): void
    {
        $p = $this->makeMockupAlerts();
        $alerts = AlertNotification::whereIn('batch_id', collect($p)->map(fn ($product) => $product->batches()->first()->batch_id))
            ->with('batch.product', 'batch.inventory')->get();

        $mail = new DailyStockAlertMail($alerts);

        $mail->assertHasSubject('Jean Rose: stock and expiry alert');
        $mail->assertSeeInHtml($p['low']->product_name.':', false);
        $mail->assertSeeInHtml('LOW STOCK, qty 2', false);
        $mail->assertSeeInHtml('OUT OF STOCK, qty 0', false);
        $mail->assertSeeInHtml('NEAR EXPIRY, qty 10', false);
    }
}
