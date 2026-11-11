<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Collection;
use RuntimeException;

// Chooses which batches to sell from: FEFO = First Expired, First Out (docs/04-business-rules.md).
// - Only batches with stock and NOT expired can be sold.
// - The batch with the earliest expiry date is used first.
// - If one batch is not enough, the quantity is split over the next batches.
class BatchPickerService
{
    public function __construct(private StockStatusService $status)
    {
    }

    // The batches we can sell, earliest expiry first (ties: oldest batch_id first).
    // Uses $product->batches with their inventory row (load them with ->with('batches.inventory') to avoid extra queries).
    public function sellableBatches(Product $product): Collection
    {
        return $product->batches
            ->filter(fn (ProductBatch $batch) => $batch->inventory !== null
                && $batch->inventory->quantity_on_hand > 0
                && ! $this->status->isExpired($batch->expiry_date))
            ->sortBy([
                fn ($a, $b) => $a->expiry_date <=> $b->expiry_date,
                fn ($a, $b) => $a->batch_id <=> $b->batch_id,
            ])
            ->values();
    }

    // How many pieces of this product can be sold right now (sum of sellable batches).
    public function available(Product $product): int
    {
        return (int) $this->sellableBatches($product)->sum(fn ($batch) => $batch->inventory->quantity_on_hand);
    }

    // One status for a product card, based on the batch that will be sold NEXT (first FEFO batch):
    // statusFor(total sellable pieces, that batch's reorder level, that batch's expiry date).
    // A product with no sellable batch (none left, or all expired) is OUT OF STOCK.
    public function productStatus(Product $product): string
    {
        $next = $this->sellableBatches($product)->first();

        if ($next === null) {
            return StockStatusService::OUT;
        }

        return $this->status->statusFor($this->available($product), $next->inventory->reorder_level, $next->expiry_date);
    }

    /**
     * Take $quantity pieces from the batches in FEFO order.
     * Returns a list like [['batch' => ProductBatch, 'quantity' => 3], ['batch' => ..., 'quantity' => 2]].
     * Throws if there is not enough sellable stock.
     */
    public function pick(Product $product, int $quantity): array
    {
        if ($quantity > $this->available($product)) {
            throw new RuntimeException("Not enough stock for {$product->product_name}.");
        }

        $parts = [];
        $left = $quantity;

        foreach ($this->sellableBatches($product) as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $batch->inventory->quantity_on_hand);
            $parts[] = ['batch' => $batch, 'quantity' => $take];
            $left -= $take;
        }

        return $parts;
    }
}
