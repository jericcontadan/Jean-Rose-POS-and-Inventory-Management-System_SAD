<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Collection;

// Senior Citizen / PWD discount (sale.discount_type SENIOR or PWD). The rate and the qualifying categories are in config/pos.php.
// Rule: it never stacks with the near-expiry discount. For each batch part, the higher discount wins:
//   - Senior/PWD wins: the item is saved at FULL price (discount_percent 0) and its Senior/PWD amount goes to sale.discount_amount.
//   - near-expiry wins (only if its percent is not lower): the normal near-expiry line, nothing added to discount_amount.
// So total_amount = sum(line_amount) - discount_amount.
class DiscountService
{
    public const TYPES = ['NONE', 'SENIOR', 'PWD'];

    // 20.0 by default (SENIOR_PWD_DISCOUNT_PERCENT).
    public function rate(): float
    {
        return (float) config('pos.senior_pwd_discount_percent');
    }

    // Does this product's category get the Senior/PWD discount? An empty list in the config means every category.
    public function qualifies(Product $product): bool
    {
        $ids = (array) config('pos.senior_pwd_category_ids', []);

        return empty($ids) || in_array((int) $product->category_id, array_map('intval', $ids), true);
    }

    /**
     * Price one batch part.
     * $nearPercent: the near-expiry percent of the batch (0 if not near expiry).
     * $senior: true when the sale is Senior/PWD AND the product qualifies.
     * Returns the percent saved on sale_item, its line_amount, and the amount for sale.discount_amount.
     */
    public function pricePart(int $quantity, float $unitPrice, float $nearPercent, bool $senior): array
    {
        $base = $quantity * $unitPrice;

        if ($senior && $this->rate() > $nearPercent) {
            return ['percent' => 0.0, 'line_amount' => round($base, 2), 'senior_amount' => round($base * $this->rate() / 100, 2)];
        }

        return ['percent' => $nearPercent, 'line_amount' => round($base * (1 - $nearPercent / 100), 2), 'senior_amount' => 0.0];
    }

    // Senior/PWD amount of the items still SOLD (used after an item void). An item had the Senior/PWD discount
    // when its category qualifies and its near-expiry percent is lower than the rate (it was saved at 0%).
    // Needs the items with batch.product loaded.
    public function seniorAmountFor(Collection $items): float
    {
        return round($items
            ->filter(fn (SaleItem $item) => $this->qualifies($item->batch->product) && (float) $item->discount_percent < $this->rate())
            ->sum(fn (SaleItem $item) => round($item->quantity * (float) $item->unit_price * $this->rate() / 100, 2)), 2);
    }

    // 'SENIOR' -> "Senior Citizen", 'PWD' -> "PWD"
    public function label(?string $type): string
    {
        return match ($type) {
            'SENIOR' => 'Senior Citizen',
            'PWD' => 'PWD',
            default => 'None',
        };
    }
}
