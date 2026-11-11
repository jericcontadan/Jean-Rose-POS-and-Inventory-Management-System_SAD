<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

// The ONLY place with the stock status rule (docs/04-business-rules.md, "Stock status").
// Check in this order and use the first match: OUT OF STOCK > NEAR EXPIRY > LOW STOCK > OKAY.
// Step 2 only reads the status. Saving it to inventory.stock_status (refresh) comes in Step 3.
class StockStatusService
{
    public const OUT = 'OUT OF STOCK';
    public const NEAR = 'NEAR EXPIRY';
    public const LOW = 'LOW STOCK';
    public const OKAY = 'OKAY';

    // Work out the status of one batch (or of the batch a product will sell next).
    public function statusFor(int $quantity, int $reorderLevel, ?CarbonInterface $expiry): string
    {
        if ($quantity <= 0) {
            return self::OUT;
        }

        if ($expiry !== null && $this->isNearExpiry($expiry)) {
            return self::NEAR;
        }

        if ($quantity <= $reorderLevel) {
            return self::LOW;
        }

        return self::OKAY;
    }

    // Expired means the expiry date is before today. Expired batches are never sold.
    public function isExpired(CarbonInterface $expiry): bool
    {
        return $expiry->copy()->startOfDay()->lt(Carbon::today());
    }

    // Near expiry: not expired yet, and expires within NEAR_EXPIRY_DAYS from today.
    public function isNearExpiry(CarbonInterface $expiry): bool
    {
        $lastNearDay = Carbon::today()->addDays((int) config('pos.near_expiry_days'));

        return ! $this->isExpired($expiry) && $expiry->copy()->startOfDay()->lte($lastNearDay);
    }

    // Discount percent for a batch: NEAR_EXPIRY_DISCOUNT_PERCENT if near expiry, otherwise 0.
    public function discountPercentFor(CarbonInterface $expiry): float
    {
        return $this->isNearExpiry($expiry) ? (float) config('pos.near_expiry_discount_percent') : 0.0;
    }
}
