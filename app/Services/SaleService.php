<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Exceptions\VoidException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UserAccount;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

// Pay Now and Void (docs/04-business-rules.md). Each one runs in ONE DB::transaction():
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

    /**
     * Void a recorded sale (docs/04-business-rules.md, "Void a recorded sale"). OWNER only.
     * Everything runs in ONE DB::transaction(): if any step throws, every row is rolled back.
     * The sale is never deleted: it is marked CANCELLED and its stock goes back to the same batches.
     */
    public function void(UserAccount $owner, Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($owner, $sale, $reason) {
            // 1. Read the sale again and LOCK it. A second void of the same sale waits here,
            //    then sees CANCELLED and is refused, so it is never voided twice.
            $sale = Sale::whereKey($sale->sale_id)->lockForUpdate()->firstOrFail();

            if ($sale->sale_status !== 'PAID') {
                throw new VoidException("Sale {$sale->receiptNumber()} is already voided.");
            }

            // 2. Mark the sale CANCELLED with the reason, the time and the owner.
            $sale->update([
                'sale_status' => 'CANCELLED',
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => $owner->user_id,
            ]);

            // 3. Lock the stock rows of the sold batches (ordered by inventory_id, like Pay Now).
            $items = $sale->items()->orderBy('sale_item_id')->get();
            $stock = Inventory::with('batch') // the status rule needs the batch's expiry date
                ->whereIn('batch_id', $items->pluck('batch_id'))
                ->orderBy('inventory_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('batch_id');

            // 4. Give each item's quantity back to the same batch: status, history (VOID) and alerts by InventoryService.
            foreach ($items as $item) {
                $this->inventory->returnForVoid($stock->get($item->batch_id), (int) $item->quantity, $owner);
            }

            // 5. Audit row. VoidRequest caps the reason at 247, so "Reason: " + reason always fits in details (255).
            $this->audit->log(
                $owner,
                'VOID',
                'SALES',
                $sale->receiptNumber(),
                'Reason: '.$reason,
                $sale->sale_id,
            );

            return $sale;
        });
    }

    // ---------- Sales Records (screen 7): READ ONLY, the methods below never write ----------

    // The sale shown in the void card: only a PAID sale can be voided, anything else gives null (no card).
    public function saleToVoid(?int $saleId): ?Sale
    {
        if ($saleId === null) {
            return null;
        }

        return Sale::with('items.batch.product')->where('sale_status', 'PAID')->find($saleId);
    }

    // "Serum x1, Makeup x3": a product sold from two batches (FEFO split) is shown once with its total quantity.
    public function itemsSummary(Sale $sale): string
    {
        return $sale->items
            ->groupBy(fn (SaleItem $item) => $item->batch->product_id)
            ->map(fn ($items) => $items->first()->batch->product->product_name.' x'.$items->sum('quantity'))
            ->implode(', ');
    }

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
