<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

// Pay Now (docs/04-business-rules.md, "Pay Now"). Everything runs in ONE DB::transaction():
// if any step throws, MySQL rolls back every row written so far and nothing is saved.
// Prices and totals are always worked out here from the database; nothing from the browser is trusted.
class SaleService
{
    public function __construct(
        private BatchPickerService $picker,
        private StockStatusService $status,
        private InventoryService $inventory,
        private AuditService $audit,
    ) {
    }

    /**
     * Save one paid sale.
     *
     * $cart: [product_id => quantity] from the session (CartService::items()).
     * $cash: cash received from the customer.
     */
    public function pay(UserAccount $user, array $cart, float $cash): Sale
    {
        if (empty($cart)) {
            throw new PaymentException('The order is empty.');
        }

        return DB::transaction(function () use ($user, $cart, $cash) {
            // 1. Read the products and LOCK their stock rows, so no other sale can change them until we finish.
            $products = $this->loadAndLock(array_keys($cart));

            // 2. Pick the batches (FEFO) and price every part on the server.
            $parts = [];
            $subtotal = 0.0;
            $total = 0.0;

            foreach ($cart as $productId => $quantity) {
                $product = $products->get($productId);
                $quantity = (int) $quantity;

                if ($product === null || $quantity <= 0) {
                    throw new PaymentException('The order has an item that cannot be sold. Please check the order.');
                }

                $available = $this->picker->available($product);
                if ($quantity > $available) {
                    throw new PaymentException(
                        "Stock changed: only {$available} {$product->product_name} left. Please check the order."
                    );
                }

                $unitPrice = (float) $product->unit_price;

                foreach ($this->picker->pick($product, $quantity) as $part) {
                    $percent = $this->status->discountPercentFor($part['batch']->expiry_date);
                    $lineAmount = round($part['quantity'] * $unitPrice * (1 - $percent / 100), 2);

                    $parts[] = $part + ['unit_price' => $unitPrice, 'percent' => $percent, 'line_amount' => $lineAmount];
                    $subtotal += $part['quantity'] * $unitPrice;
                    $total += $lineAmount;
                }
            }

            $subtotal = round($subtotal, 2);
            $total = round($total, 2);
            $cash = round($cash, 2);

            // 3. Cash must cover the total (compare in centavos so decimals do not cause surprises).
            if ((int) round($cash * 100) < (int) round($total * 100)) {
                throw new PaymentException('Cash received is less than the total of ₱'.number_format($total, 2).'.');
            }
            $change = round($cash - $total, 2);

            // 4. Insert the sale.
            $sale = Sale::create([
                'user_id' => $user->user_id,
                'sale_datetime' => now(),
                'subtotal' => $subtotal,
                'total_amount' => $total,
                'discount_percent' => 0, // the sale-level discount is not used
                'sale_status' => 'PAID',
                'cash_received' => $cash,
                'change_amount' => $change,
            ]);

            // 5. Insert one sale_item per batch (unit_price is copied, so later price changes do not alter this sale).
            foreach ($parts as $part) {
                SaleItem::create([
                    'sale_id' => $sale->sale_id,
                    'batch_id' => $part['batch']->batch_id,
                    'quantity' => $part['quantity'],
                    'unit_price' => $part['unit_price'],
                    'line_amount' => $part['line_amount'],
                    'discount_percent' => $part['percent'],
                ]);
            }

            // 6. Lower the stock of each batch: status, history (SALE) and alerts are done by InventoryService.
            foreach ($parts as $part) {
                $this->inventory->removeForSale($part['batch']->inventory, $part['quantity'], $user);
            }

            // 7. Audit row.
            $this->audit->log(
                $user,
                'SALE',
                'SALES',
                $sale->receiptNumber(),
                sprintf('Items %d, total %s, cash %s, change %s',
                    count($cart), number_format($total, 2, '.', ''), number_format($cash, 2, '.', ''), number_format($change, 2, '.', '')),
                $sale->sale_id,
            );

            return $sale;
        });
    }

    // ---------- Sales Records (screen 7): READ ONLY, these two methods never write ----------

    // The sales of one day (by sale_datetime), oldest first, 50 sales per page.
    // Paginated by SALE, not by item row, so one receipt is never split over two pages.
    public function salesForDate(CarbonInterface $date, int $perPage = 50): LengthAwarePaginator
    {
        return Sale::with(['cashier', 'items.batch.product.category'])
            ->whereBetween('sale_datetime', $this->dayRange($date))
            ->orderBy('sale_datetime')
            ->orderBy('sale_id')
            ->paginate($perPage)
            ->withQueryString(); // keep ?date= on the page links
    }

    // Daily summary for the WHOLE day (not only the page shown). CANCELLED (voided) sales are counted
    // but never added to the money totals.
    public function dailySummary(CarbonInterface $date): array
    {
        $day = Sale::whereBetween('sale_datetime', $this->dayRange($date));
        $paid = (clone $day)->where('sale_status', 'PAID');

        return [
            'paid_count' => (clone $paid)->count(),
            'cancelled_count' => (clone $day)->where('sale_status', 'CANCELLED')->count(),
            'total_sales' => round((float) (clone $paid)->sum('total_amount'), 2),
            // Money taken off by near-expiry discounts: subtotal (full price) minus total paid.
            'total_discount' => round((float) (clone $paid)->sum(DB::raw('subtotal - total_amount')), 2),
        ];
    }

    // 00:00:00 to 23:59:59 of that day (app timezone Asia/Manila, the same time sale_datetime is saved in).
    private function dayRange(CarbonInterface $date): array
    {
        return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
    }

    // Load the products with their batches, then lock their inventory rows (SELECT ... FOR UPDATE)
    // in a fixed order (by inventory_id) and put the fresh, locked rows back on the batches.
    private function loadAndLock(array $productIds)
    {
        $products = Product::with('batches')->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $batches = $products->flatMap(fn (Product $product) => $product->batches)->keyBy('batch_id');

        $locked = Inventory::whereIn('batch_id', $batches->keys())
            ->orderBy('inventory_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('batch_id');

        foreach ($batches as $batchId => $batch) {
            $inventory = $locked->get($batchId);
            $inventory?->setRelation('batch', $batch); // the status rule needs the batch's expiry date
            $batch->setRelation('inventory', $inventory);
        }

        return $products;
    }
}
