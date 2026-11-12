<?php

namespace Tests\Feature;

use App\Services\StockStatusService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// T14 from docs/06-testing.md: stock status rules, checked in this order (first match wins):
// OUT OF STOCK > NEAR EXPIRY > LOW STOCK > OKAY. No database needed.
class StockStatusTest extends TestCase
{
    private StockStatusService $status;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pos.near_expiry_days' => 30]); // fixed rule for the tests, whatever .env says
        $this->status = app(StockStatusService::class);
    }

    // Expiry date $days from today.
    private function inDays(int $days): Carbon
    {
        return Carbon::today()->addDays($days);
    }

    public function test_zero_quantity_is_out_of_stock_even_if_near_expiry_and_low(): void
    {
        $this->assertSame('OUT OF STOCK', $this->status->statusFor(0, 5, $this->inDays(10)));
        $this->assertSame('OUT OF STOCK', $this->status->statusFor(0, 0, $this->inDays(300)));
    }

    public function test_near_expiry_wins_over_low_stock(): void
    {
        $this->assertSame('NEAR EXPIRY', $this->status->statusFor(2, 5, $this->inDays(10)));
        $this->assertSame('NEAR EXPIRY', $this->status->statusFor(50, 5, $this->inDays(0))); // expires today
    }

    public function test_low_stock_when_at_or_below_reorder_level(): void
    {
        $this->assertSame('LOW STOCK', $this->status->statusFor(2, 5, $this->inDays(300)));
        $this->assertSame('LOW STOCK', $this->status->statusFor(5, 5, $this->inDays(300))); // equal counts as low
    }

    public function test_okay_otherwise(): void
    {
        $this->assertSame('OKAY', $this->status->statusFor(6, 5, $this->inDays(300)));
    }

    public function test_near_expiry_boundary_is_30_days(): void
    {
        $this->assertSame('NEAR EXPIRY', $this->status->statusFor(10, 2, $this->inDays(30)));
        $this->assertSame('OKAY', $this->status->statusFor(10, 2, $this->inDays(31)));
    }

    public function test_expired_batch_is_not_near_expiry(): void
    {
        $yesterday = $this->inDays(-1);

        $this->assertTrue($this->status->isExpired($yesterday));
        $this->assertFalse($this->status->isNearExpiry($yesterday));
        $this->assertSame('OKAY', $this->status->statusFor(10, 2, $yesterday));
        $this->assertSame('LOW STOCK', $this->status->statusFor(1, 2, $yesterday));
    }
}
