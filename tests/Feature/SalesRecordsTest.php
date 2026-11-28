<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesPosData;
use Tests\TestCase;

// Sales Records (screen 7, Step 5): owner only, sales of one day grouped by receipt, daily summary, read only.
// (The Void action itself is tested in VoidTest.)
// Sales are made directly here with a fixed date, so dates and statuses are exact.
// The dates are in 2030 so they never mix with other sales in the test database.
// DatabaseTransactions rolls everything back after each test.
class SalesRecordsTest extends TestCase
{
    use CreatesPosData;
    use DatabaseTransactions;

    private UserAccount $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeUser('OWNER');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // undo any fixed "now"
        parent::tearDown();
    }

    // ---------- helper ----------

    /**
     * Save one sale like Pay Now would. $lines: [[product, quantity, unit price, discount percent], ...].
     * Example: makeSale('2030-01-15 09:02', [[$serum, 2, 120, 10]]) = Serum x2 at 120 with 10% off = 216.00.
     */
    private function makeSale(string $datetime, array $lines, string $status = 'PAID', ?string $reason = null): Sale
    {
        $subtotal = 0;
        $total = 0;
        foreach ($lines as [$product, $quantity, $price, $discount]) {
            $subtotal += $quantity * $price;
            $total += round($quantity * $price * (1 - $discount / 100), 2);
        }

        $sale = Sale::create([
            'user_id' => $this->owner->user_id,
            'sale_datetime' => $datetime,
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'discount_percent' => 0,
            'sale_status' => $status,
            'cash_received' => $total,
            'change_amount' => 0,
            'void_reason' => $reason,
            'voided_at' => $status === 'CANCELLED' ? $datetime : null,
            'voided_by' => $status === 'CANCELLED' ? $this->owner->user_id : null,
        ]);

        foreach ($lines as [$product, $quantity, $price, $discount]) {
            SaleItem::create([
                'sale_id' => $sale->sale_id,
                'batch_id' => $product->batches()->first()->batch_id,
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_amount' => round($quantity * $price * (1 - $discount / 100), 2),
                'discount_percent' => $discount,
            ]);
        }

        return $sale;
    }

    private function product(string $name, float $price): Product
    {
        return $this->makeProduct($name, $price, [[200, 50, 2]]);
    }

    // ---------- access ----------

    public function test_cashier_gets_403(): void
    {
        $this->actingAs($this->makeUser('CASHIER'));

        $this->get('/sales')->assertForbidden();
    }

    // ---------- T15 ----------

    public function test_t15_sales_records_by_date_show_correct_rows_and_totals(): void
    {
        $serum = $this->product('Serum', 120);
        $makeup = $this->product('Makeup', 150);

        $first = $this->makeSale('2030-01-15 09:02:00', [[$serum, 2, 120, 10]]);   // 216.00
        $second = $this->makeSale('2030-01-15 14:30:00', [[$makeup, 1, 150, 0]]);  // 150.00
        $otherDay = $this->makeSale('2030-01-14 23:59:00', [[$makeup, 3, 150, 0]]); // not on the 15th

        $response = $this->get('/sales?date=2030-01-15')->assertOk();

        // Only the two sales of that day, oldest first
        $response->assertSeeInOrder([$first->receiptNumber(), $second->receiptNumber()]);
        $response->assertDontSee($otherDay->receiptNumber());

        // Sale row: receipt, time, pieces, discount, total, status. Then its item row: product, qty, price, discount, amount.
        $response->assertSeeInOrder([
            $first->receiptNumber(), '09:02 AM', '2', '-₱24.00', '₱216.00', 'PAID',
            'Serum', '2', '₱120.00', '10%', '₱216.00',
        ]);

        // Daily summary (whole day)
        $response->assertViewHas('summary', fn (array $s) => $s['paid_count'] === 2
            && $s['cancelled_count'] === 0
            && $s['total_sales'] === 366.00
            && $s['total_discount'] === 24.00);
        $response->assertSee('₱366.00');
    }

    public function test_default_date_is_today_in_manila(): void
    {
        // 11:30 PM in Manila (still 3:30 PM UTC): "today" must be the Manila date.
        Carbon::setTestNow(Carbon::parse('2030-02-20 23:30:00', 'Asia/Manila'));
        $serum = $this->product('Serum', 120);

        $today = $this->makeSale('2030-02-20 23:00:00', [[$serum, 1, 120, 0]]);
        $tomorrow = $this->makeSale('2030-02-21 00:30:00', [[$serum, 1, 120, 0]]);

        $this->get('/sales')
            ->assertOk()
            ->assertSee('value="2030-02-20"', false)
            ->assertSee($today->receiptNumber())
            ->assertDontSee($tomorrow->receiptNumber());
    }

    public function test_bad_date_is_refused(): void
    {
        $this->get('/sales?date=abc')->assertSessionHasErrors('date');
    }

    // ---------- voided sales ----------

    public function test_voided_sale_is_shown_grey_with_reason_and_not_in_total(): void
    {
        $serum = $this->product('Serum', 120);
        $makeup = $this->product('Makeup', 150);

        $paid = $this->makeSale('2030-01-16 09:00:00', [[$serum, 2, 120, 10]]);                       // 216.00
        $void = $this->makeSale('2030-01-16 10:00:00', [[$makeup, 1, 150, 0]], 'CANCELLED', 'wrong item'); // 150.00

        $response = $this->get('/sales?date=2030-01-16')->assertOk();

        $response->assertSee($void->receiptNumber())
            ->assertSee('jr-sales-voided', false)
            ->assertSee('VOIDED')
            ->assertSee('Sale voided by '.$this->owner->username.' at 10:00 AM · wrong item');

        $response->assertViewHas('summary', fn (array $s) => $s['paid_count'] === 1
            && $s['cancelled_count'] === 1
            && $s['total_sales'] === 216.00);

        // Void is shown once, only for the PAID sale (the voided one has no Void button).
        // Same for "Void item": only the PAID sale's one item has it.
        $this->assertSame(1, substr_count($response->getContent(), '>Void</a>'));
        $this->assertSame(1, substr_count($response->getContent(), '>Void item</a>'));
    }

    // ---------- price at the time of sale ----------

    public function test_price_shown_is_the_price_at_the_time_of_sale(): void
    {
        $serum = $this->product('Serum', 120);
        $this->makeSale('2030-01-17 09:00:00', [[$serum, 2, 120, 0]]);

        // The owner raises the price later
        $serum->update(['unit_price' => 155]);

        $this->get('/sales?date=2030-01-17')
            ->assertOk()
            ->assertSee('₱120.00')
            ->assertSee('₱240.00')
            ->assertDontSee('155.00');
    }

    // ---------- read only ----------

    public function test_opening_the_page_writes_nothing(): void
    {
        $serum = $this->product('Serum', 120);
        $this->makeSale('2030-01-18 09:00:00', [[$serum, 1, 120, 0]]);
        $this->makeSale('2030-01-18 10:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'test');

        $counts = fn () => [Sale::count(), SaleItem::count(), AuditLog::count(), Inventory::sum('quantity_on_hand')];
        $before = $counts();

        // Record every SQL statement the page runs
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->get('/sales?date=2030-01-18')->assertOk();
        $this->get('/sales?date=2030-01-18&page=1')->assertOk();

        $this->assertSame([], $writes, 'The Sales Records page must not write to the database.');
        $this->assertEquals($before, $counts());
    }

    // ---------- reprint, pagination, page buttons ----------

    public function test_each_sale_links_to_its_receipt(): void
    {
        $serum = $this->product('Serum', 120);
        $a = $this->makeSale('2030-01-19 09:00:00', [[$serum, 1, 120, 0]]);
        $b = $this->makeSale('2030-01-19 10:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'test');

        $this->get('/sales?date=2030-01-19')
            ->assertSee(route('receipts.show', $a), false)
            ->assertSee(route('receipts.show', $b), false);
    }

    public function test_more_than_20_sales_are_paginated_but_summary_counts_all(): void
    {
        $serum = $this->product('Serum', 120);
        for ($i = 0; $i < 21; $i++) {
            $this->makeSale('2030-01-20 08:00:00', [[$serum, 1, 120, 0]]);
        }

        $this->get('/sales?date=2030-01-20')
            ->assertOk()
            ->assertViewHas('sales', fn ($sales) => $sales->count() === 20 && $sales->total() === 21)
            ->assertViewHas('summary', fn (array $s) => $s['paid_count'] === 21 && $s['total_sales'] === 2520.00)
            ->assertSee('date=2030-01-20&amp;page=2', false); // page links keep the date

        $this->get('/sales?date=2030-01-20&page=2')
            ->assertViewHas('sales', fn ($sales) => $sales->count() === 1);
    }

    // One aligned table: the 8 column headers, one row per sale with 8 cells, and the items in a
    // collapsed row with their own sub-header. Items, Receipt and Void have the same fixed-width class.
    public function test_table_has_one_row_per_sale_and_collapsed_items_with_sub_header(): void
    {
        $serum = $this->product('Serum', 120);
        $sale = $this->makeSale('2030-01-22 09:00:00', [[$serum, 2, 120, 10]]);

        $response = $this->get('/sales?date=2030-01-22')->assertOk()
            ->assertSee('Tuesday, January 22, 2030')          // the day in words under the title
            ->assertSee('End-of-day summary')                 // the old "Close" button, clearer label
            ->assertSeeInOrder(['Total sales', 'Paid transactions', 'Cancelled', 'Discounts given', 'Refunded']) // cards first
            ->assertSeeInOrder(['Receipt No', 'Time', 'Cashier', 'Qty sold', 'Discount', 'Total', 'Status', 'Actions'])
            ->assertSeeInOrder(['Product', 'Qty', 'Price', 'Discount', 'Amount', 'Status'])
            ->assertSee('-₱24.00')       // 240.00 - 216.00
            ->assertSee('Near-expiry')
            ->assertSee('PAID')
            ->assertSee('SOLD')
            ->assertSee('Original ₱216.00 · Refunded ₱0.00 · Final <strong>₱216.00</strong>', false)
            ->assertDontSee('>Items</button>', false)         // no Items button: the row or the arrow opens the items
            ->assertDontSee('jr-sales-summary', false);       // the old summary bar under the table is gone

        $html = $response->getContent();

        // The summary cards come before the table.
        $this->assertLessThan(strpos($html, 'jr-sales-table'), strpos($html, 'jr-sales-cards'));

        // The sale row has exactly 8 cells, like the header, and says which items panel it opens.
        preg_match('/<tr class="jr-sales-sale-row" data-items="#saleItems'.$sale->sale_id.'">(.*?)<\/tr>/s', $html, $row);
        $this->assertSame(8, substr_count($row[1], '<td'));

        // The items start collapsed (no "show"); only the arrow has the collapse target (the row uses data-items).
        $this->assertStringContainsString('class="collapse " id="saleItems'.$sale->sale_id.'"', $html);
        $this->assertSame(1, substr_count($html, 'data-bs-target="#saleItems'.$sale->sale_id.'"'));

        // The Void button is the small red one.
        $this->assertStringContainsString('jr-btn-void jr-btn-void-sm', $html);

        // ?open= shows that sale's items already expanded, with its row highlighted.
        $this->get('/sales?date=2030-01-22&open='.$sale->sale_id)
            ->assertSee('class="collapse show" id="saleItems'.$sale->sale_id.'"', false)
            ->assertSee('jr-sales-group  is-open', false);
    }

    // A sale with one voided item: badge, original total struck through, who voided it, and the money line.
    public function test_sale_with_a_voided_item_shows_badge_original_total_and_refund(): void
    {
        $serum = $this->product('Serum', 120);
        $makeup = $this->product('Makeup', 150);
        $sale = $this->makeSale('2030-01-23 09:00:00', [[$serum, 2, 120, 0], [$makeup, 1, 150, 0]]); // paid 390.00

        // Void the makeup like SaleService::voidItem() leaves it: item VOIDED, sale total down to 240.00.
        $sale->items()->where('unit_price', 150)->update([
            'item_status' => 'VOIDED',
            'void_reason' => 'customer changed mind',
            'voided_at' => '2030-01-23 09:30:00',
            'voided_by' => $this->owner->user_id,
        ]);
        $sale->update(['subtotal' => 240, 'total_amount' => 240]);

        $this->get('/sales?date=2030-01-23')->assertOk()
            ->assertSee('1 item voided')
            ->assertSee('<s>₱390.00</s>', false)              // original total, struck through under the new one
            ->assertSee('₱240.00')
            ->assertSee('Voided by '.$this->owner->username.' at 09:30 AM · customer changed mind')
            ->assertSee('Original ₱390.00 · Refunded ₱150.00 · Final <strong>₱240.00</strong>', false)
            ->assertSee('SOLD')
            ->assertSee('VOIDED')
            ->assertSee('<td class="text-end" data-label="Disc.">—</td>', false)  // 0% discount shows a dash
            ->assertDontSee('>0%<', false)
            ->assertViewHas('summary', fn (array $s) => $s['refunded'] === 150.00 && $s['total_sales'] === 240.00)
            ->assertSee('id="summaryRefunded">₱150.00', false);
    }

    // ---------- search and filters ----------

    public function test_filters_by_receipt_status_discount_and_cashier_and_keep_the_date(): void
    {
        $serum = $this->product('Serum', 120);
        $cashier = $this->makeUser('CASHIER');

        $plain = $this->makeSale('2030-02-01 09:00:00', [[$serum, 1, 120, 0]]);                   // no discount
        $near = $this->makeSale('2030-02-01 10:00:00', [[$serum, 1, 120, 10]]);                   // near-expiry
        $senior = $this->makeSale('2030-02-01 11:00:00', [[$serum, 1, 120, 0]]);
        $senior->update(['discount_type' => 'SENIOR', 'discount_id_number' => '123', 'discount_customer_name' => 'Ana']);
        $cancelled = $this->makeSale('2030-02-01 12:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'wrong item');
        $byCashier = $this->makeSale('2030-02-01 13:00:00', [[$serum, 2, 120, 0]]);
        $byCashier->update(['user_id' => $cashier->user_id]);
        $withVoid = $this->makeSale('2030-02-01 14:00:00', [[$serum, 1, 120, 0], [$serum, 1, 120, 0]]);
        $withVoid->items()->first()->update(['item_status' => 'VOIDED', 'void_reason' => 'twice']);
        $otherDay = $this->makeSale('2030-02-02 09:00:00', [[$serum, 1, 120, 0]]);

        $ids = fn (string $query) => $this->get('/sales?date=2030-02-01&'.$query)->assertOk()
            ->viewData('sales')->pluck('sale_id')->sort()->values()->all();
        $sorted = fn (...$sales) => collect($sales)->pluck('sale_id')->sort()->values()->all();

        // Receipt number: "S-0012", "s12" and "12" all find sale 12. Another day's sale is never found.
        $this->assertSame([$near->sale_id], $ids('q='.urlencode($near->receiptNumber())));
        $this->assertSame([$near->sale_id], $ids('q='.$near->sale_id));
        $this->assertSame([], $ids('q='.$otherDay->sale_id));

        $this->assertSame([$cancelled->sale_id], $ids('status=CANCELLED'));
        $this->assertSame([$withVoid->sale_id], $ids('status=VOIDED_ITEMS'));
        $this->assertNotContains($cancelled->sale_id, $ids('status=PAID'));

        $this->assertSame([$senior->sale_id], $ids('discount=SENIOR'));
        $this->assertSame([], $ids('discount=PWD'));
        $this->assertSame([$near->sale_id], $ids('discount=NEAR_EXPIRY'));
        $this->assertSame($sorted($plain, $cancelled, $byCashier, $withVoid), $ids('discount=NONE'));

        $this->assertSame([$byCashier->sale_id], $ids('cashier='.$cashier->user_id));
        $this->assertSame([$cancelled->sale_id], $ids('status=CANCELLED&discount=NONE')); // filters combine

        // The filter form keeps the date, the cashier list has who sold that day, and the choice stays selected.
        $this->get('/sales?date=2030-02-01&status=CANCELLED')
            ->assertSee('<input type="hidden" name="date" value="2030-02-01">', false)
            ->assertSee('<option value="'.$cashier->user_id.'" >'.$cashier->username.'</option>', false)
            ->assertSee('<option value="CANCELLED" selected>Cancelled</option>', false)
            ->assertSee('Clear');

        // Wrong values are refused (only the listed choices).
        $this->get('/sales?date=2030-02-01&status=DELETED')->assertSessionHasErrors('status');
        $this->get('/sales?date=2030-02-01&discount=FREE')->assertSessionHasErrors('discount');
    }

    public function test_filters_work_with_pagination_and_void_links_keep_them(): void
    {
        $serum = $this->product('Serum', 120);
        for ($i = 0; $i < 21; $i++) {
            $this->makeSale('2030-02-03 08:00:00', [[$serum, 1, 120, 0]]);
        }
        $this->makeSale('2030-02-03 09:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'test');

        $response = $this->get('/sales?date=2030-02-03&status=PAID')->assertOk()
            ->assertViewHas('sales', fn ($sales) => $sales->count() === 20 && $sales->total() === 21)
            ->assertSee('date=2030-02-03&amp;status=PAID&amp;page=2', false);   // page 2 keeps date + filter

        $first = $response->viewData('sales')->first();
        $response->assertSee(route('sales.index', ['date' => '2030-02-03', 'status' => 'PAID', 'void' => $first->sale_id]));

        $this->get('/sales?date=2030-02-03&status=PAID&page=2')
            ->assertViewHas('sales', fn ($sales) => $sales->count() === 1);
    }

    // ---------- comparison with the day before ----------

    public function test_cards_compare_with_the_day_before(): void
    {
        $serum = $this->product('Serum', 100);
        $this->makeSale('2030-02-05 09:00:00', [[$serum, 2, 100, 0]]);                  // yesterday: 1 sale, 200.00
        $this->makeSale('2030-02-06 09:00:00', [[$serum, 2, 100, 0]]);                  // today: 2 sales, 300.00
        $this->makeSale('2030-02-06 10:00:00', [[$serum, 1, 100, 0]]);

        $this->get('/sales?date=2030-02-06')->assertOk()
            ->assertSee('<span class="jr-sales-change is-up">▲ 50% vs yesterday</span>', false)   // total 300 vs 200
            ->assertSee('<span class="jr-sales-change is-up">▲ 100% vs yesterday</span>', false)  // paid 2 vs 1
            ->assertSee('<span class="jr-sales-change is-none">— vs yesterday</span>', false);     // cancelled 0 vs 0

        // The next day sells less: red, down.
        $this->makeSale('2030-02-07 09:00:00', [[$serum, 1, 100, 0]]);                  // 100.00 vs 300.00
        $this->get('/sales?date=2030-02-07')
            ->assertSee('<span class="jr-sales-change is-down">▼ 67% vs yesterday</span>', false)
            ->assertSee('<span class="jr-sales-change is-down">▼ 50% vs yesterday</span>', false);
    }

    // ---------- empty state ----------

    public function test_empty_day_and_no_match_show_an_empty_state_instead_of_the_table(): void
    {
        $this->get('/sales?date=2030-02-10')->assertOk()
            ->assertSee('No sales for this day')
            ->assertSee('Nothing was sold on 02/10/2030')
            ->assertDontSee('<table class="table jr-sales-table">', false);

        $serum = $this->product('Serum', 120);
        $this->makeSale('2030-02-11 09:00:00', [[$serum, 1, 120, 0]]);

        $this->get('/sales?date=2030-02-11&status=CANCELLED')->assertOk()
            ->assertSee('No sales for this day')
            ->assertSee('matches these filters')
            ->assertSee('Clear filters')
            ->assertDontSee('<table class="table jr-sales-table">', false);
    }

    public function test_export_links_to_that_day_and_void_is_a_link_for_paid_sales_only(): void
    {
        $serum = $this->product('Serum', 120);
        $paid = $this->makeSale('2030-01-21 09:00:00', [[$serum, 1, 120, 0]]);
        $this->makeSale('2030-01-21 10:00:00', [[$serum, 1, 120, 0]], 'CANCELLED', 'test');

        $response = $this->get('/sales?date=2030-01-21')
            // Export to Excel downloads the Sales report of this day (ExcelExportTest checks the file).
            ->assertSee(route('exports.download', ['report' => 'sales', 'from' => '2030-01-21', 'to' => '2030-01-21']))
            ->assertSee('Close Sales'); // the Close summary box is on the page

        // Void (Step 7) is now a working link that opens the void card, shown once, only for the PAID sale.
        $response->assertSee(route('sales.index', ['date' => '2030-01-21', 'void' => $paid->sale_id])); // escaped: &amp;
        $this->assertSame(1, substr_count($response->getContent(), '>Void</a>'));
        $this->assertTrue(Route::has('sales.void'));
    }
}
