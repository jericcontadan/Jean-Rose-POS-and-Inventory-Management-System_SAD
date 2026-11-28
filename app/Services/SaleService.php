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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        private DiscountService $discounts,
    ) {
    }

    /**
     * Save one paid sale.
     *
     * $cart: [product_id => quantity] from the session (CartService::items()).
     * $cash: cash received from the customer.
     * $discount: Senior Citizen / PWD choice from PayRequest::discount(): ['type', 'id_number', 'customer_name'].
     *            Empty (or type NONE) = no Senior/PWD discount.
     */
    public function pay(UserAccount $user, array $cart, float $cash, array $discount = []): Sale
    {
        if (empty($cart)) {
            throw new PaymentException('The order is empty.');
        }

        $type = $discount['type'] ?? 'NONE';
        $isSenior = $type !== 'NONE';

        return DB::transaction(function () use ($user, $cart, $cash, $discount, $type, $isSenior) {
            // 1. Read the products and LOCK their stock rows, so no other sale can change them until we finish.
            $products = $this->loadAndLock(array_keys($cart));

            // 2. Pick the batches (FEFO) and price every part on the server.
            $parts = [];
            $subtotal = 0.0;
            $lines = 0.0;          // sum of line_amount
            $seniorAmount = 0.0;   // sale.discount_amount (Senior / PWD)

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
                $senior = $isSenior && $this->discounts->qualifies($product);

                foreach ($this->picker->pick($product, $quantity) as $part) {
                    // Near-expiry OR Senior/PWD, whichever is higher, never both (DiscountService::pricePart()).
                    $nearPercent = $this->status->discountPercentFor($part['batch']->expiry_date);
                    $price = $this->discounts->pricePart($part['quantity'], $unitPrice, $nearPercent, $senior);

                    $parts[] = $part + ['product' => $product, 'unit_price' => $unitPrice, 'percent' => $price['percent'], 'line_amount' => $price['line_amount']];
                    $subtotal += $part['quantity'] * $unitPrice;
                    $lines += $price['line_amount'];
                    $seniorAmount += $price['senior_amount'];
                }
            }

            $subtotal = round($subtotal, 2);
            $seniorAmount = round($seniorAmount, 2);
            $total = round($lines - $seniorAmount, 2);
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
                'discount_type' => $type,
                'discount_id_number' => $isSenior ? $discount['id_number'] : null,
                'discount_customer_name' => $isSenior ? $discount['customer_name'] : null,
                'discount_amount' => $seniorAmount,
                'sale_status' => 'PAID',
                'cash_received' => $cash,
                'change_amount' => $change,
            ]);

            // 5. Insert one sale_item per batch. unit_price, product_name and product_number are copied,
            //    so a later price change or rename does not alter this sale, its receipt or the reports.
            foreach ($parts as $part) {
                SaleItem::create([
                    'sale_id' => $sale->sale_id,
                    'batch_id' => $part['batch']->batch_id,
                    'quantity' => $part['quantity'],
                    'unit_price' => $part['unit_price'],
                    'line_amount' => $part['line_amount'],
                    'discount_percent' => $part['percent'],
                    'product_name' => $part['product']->product_name,
                    'product_number' => $part['product']->product_number,
                ]);
            }

            // 6. Lower the stock of each batch: status, history (SALE) and alerts are done by InventoryService.
            foreach ($parts as $part) {
                $this->inventory->removeForSale($part['batch']->inventory, $part['quantity'], $user);
            }

            // 7. Audit row. A Senior/PWD sale also names the discount, the ID number and the customer (details holds 255 characters).
            $details = sprintf('Items %d, total %s, cash %s, change %s',
                count($cart), number_format($total, 2, '.', ''), number_format($cash, 2, '.', ''), number_format($change, 2, '.', ''));
            if ($isSenior) {
                $details .= sprintf(', %s discount %s, ID %s, name %s',
                    $this->discounts->label($type), number_format($seniorAmount, 2, '.', ''), $discount['id_number'], $discount['customer_name']);
            }
            $this->audit->log($user, 'SALE', 'SALES', $sale->receiptNumber(), Str::limit($details, 252), $sale->sale_id);

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
            //    Only items still SOLD: an item voided earlier already went back to stock (never return it twice).
            foreach ($items->where('item_status', 'SOLD') as $item) {
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

    /**
     * Void ONE item (one sale_item row) of a PAID sale. OWNER only. Everything runs in ONE DB::transaction().
     * The item is never deleted: it is marked VOIDED, its quantity goes back to the same batch,
     * and the sale's totals are worked out again from the items that are still SOLD.
     * If no SOLD item is left, the whole sale becomes CANCELLED.
     */
    public function voidItem(UserAccount $owner, SaleItem $item, string $reason): Sale
    {
        return DB::transaction(function () use ($owner, $item, $reason) {
            // 1. Lock the sale first (same order as void(), so the two never wait on each other), then the item.
            $sale = Sale::whereKey($item->sale_id)->lockForUpdate()->firstOrFail();
            $item = SaleItem::whereKey($item->sale_item_id)->lockForUpdate()->firstOrFail();

            if ($sale->sale_status !== 'PAID') {
                throw new VoidException("Sale {$sale->receiptNumber()} is already voided.");
            }
            if ($item->item_status !== 'SOLD') {
                throw new VoidException('This item is already voided.');
            }

            $oldTotal = (float) $sale->total_amount;

            // 2. Mark the item VOIDED with the reason, the time and the owner.
            $item->update([
                'item_status' => 'VOIDED',
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => $owner->user_id,
            ]);

            // 3. Lock the batch's stock row and give the quantity back (status, history VOID and alerts by InventoryService).
            $stock = Inventory::with('batch')->where('batch_id', $item->batch_id)->lockForUpdate()->firstOrFail();
            $this->inventory->returnForVoid($stock, (int) $item->quantity, $owner);

            // 4. Work out the totals again from the items that are still SOLD.
            $sold = $sale->items()->with('batch.product')->where('item_status', 'SOLD')->get();
            $this->recalculate($sale, $sold);

            // 5. No SOLD item left: the whole sale is cancelled.
            if ($sold->isEmpty()) {
                $sale->sale_status = 'CANCELLED';
                $sale->void_reason = 'All items voided';
                $sale->voided_at = now();
                $sale->voided_by = $owner->user_id;
            }
            $sale->save();

            // 6. Audit row naming the item. The full reason is on sale_item.void_reason; details holds 255 characters.
            $details = sprintf('Item %s x%d voided, refund %s, new total %s. Reason: %s',
                $item->displayName(),
                $item->quantity,
                number_format($oldTotal - (float) $sale->total_amount, 2, '.', ''),
                number_format((float) $sale->total_amount, 2, '.', ''),
                $reason);
            $this->audit->log($owner, 'VOID', 'SALES', $sale->receiptNumber(), Str::limit($details, 252), $sale->sale_id);

            return $sale;
        });
    }

    // New subtotal, discount_amount and total_amount from the SOLD items (the sale is saved by the caller).
    // discount_amount (Senior / PWD) is worked out again with the same rule as Pay Now, from the items still SOLD;
    // it is 0 when the sale has no Senior / PWD discount.
    private function recalculate(Sale $sale, Collection $sold): void
    {
        $subtotal = round($sold->sum(fn (SaleItem $i) => $i->quantity * (float) $i->unit_price), 2);
        $lines = round($sold->sum(fn (SaleItem $i) => (float) $i->line_amount), 2);
        $discount = $sale->hasSeniorDiscount() ? $this->discounts->seniorAmountFor($sold) : 0.0;

        $sale->subtotal = $subtotal;
        $sale->discount_amount = $discount;
        $sale->total_amount = round($lines - $discount, 2);
    }

    // ---------- Sales Records (screen 7): READ ONLY, the methods below never write ----------

    // The item shown in the "void item" card: only a SOLD item of a PAID sale, anything else gives null (no card).
    public function itemToVoid(?int $itemId): ?SaleItem
    {
        if ($itemId === null) {
            return null;
        }

        return SaleItem::with('sale')
            ->where('item_status', 'SOLD')
            ->whereHas('sale', fn ($q) => $q->where('sale_status', 'PAID'))
            ->find($itemId);
    }

    // The sale shown in the void card: only a PAID sale can be voided, anything else gives null (no card).
    public function saleToVoid(?int $saleId): ?Sale
    {
        if ($saleId === null) {
            return null;
        }

        return Sale::with('items.batch.product')->where('sale_status', 'PAID')->find($saleId);
    }

    // "Serum x1, Makeup x3": a product sold from two batches (FEFO split) is shown once with its total quantity.
    // The name is the one saved on the sale_item at the time of sale.
    // Voided items are left out; a sale with no SOLD item left (cancelled) still lists everything it had.
    public function itemsSummary(Sale $sale): string
    {
        $items = $sale->items->where('item_status', 'SOLD');
        if ($items->isEmpty()) {
            $items = $sale->items;
        }

        return $items
            ->groupBy(fn (SaleItem $item) => $item->batch->product_id)
            ->map(fn ($items) => $items->first()->displayName().' x'.$items->sum('quantity'))
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

    // Excel export (Sales report): every sale from the start of $from to the end of $to, oldest first, NOT paginated.
    // Voided (CANCELLED) sales are included; SalesExport shows them as "Voided" and leaves them out of the totals.
    public function salesForRange(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Sale::with(['cashier', 'items.batch.product'])
            ->whereBetween('sale_datetime', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('sale_datetime')
            ->orderBy('sale_id')
            ->get();
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
            // Money taken off by near-expiry and Senior/PWD discounts: subtotal (full price) minus total paid.
            'total_discount' => round((float) (clone $paid)->sum(DB::raw('subtotal - total_amount')), 2),
        ];
    }

    /**
     * The numbers on the owner's Dashboard. Sales money uses the Sales Records rules: only PAID sales
     * (and their SOLD items), and a sale belongs to the day it was made (sale_datetime).
     * Voids are different on purpose: they count on the day of the VOID (voided_at), whatever day the sale was made,
     * so "Voided today" is the money given back today.
     */
    public function dashboard(CarbonInterface $today): array
    {
        $day = $this->dayRange($today);
        $todaySales = Sale::whereBetween('sale_datetime', $day);

        // Whole sales voided today (any sale day). Their refund is the sale total at the time of the void:
        // items voided earlier were already taken off the total, and a sale cancelled because its last item
        // was voided has a total of 0 (that refund is counted with the item below).
        $voidedSales = Sale::where('sale_status', 'CANCELLED')->whereBetween('voided_at', $day)->get();

        // Single items voided today (any sale day). Refund = the line amount minus the Senior/PWD discount
        // that line had: the same amount the sale total went down by in voidItem() / recalculate().
        $voidedItems = SaleItem::with(['sale', 'batch.product'])
            ->where('item_status', 'VOIDED')
            ->whereBetween('voided_at', $day)
            ->get();
        $itemRefund = $voidedItems->sum(function (SaleItem $item) {
            $senior = $item->sale->hasSeniorDiscount() ? $this->discounts->seniorAmountFor(collect([$item])) : 0.0;

            return (float) $item->line_amount - $senior;
        });

        $refund = $voidedSales->sum(fn (Sale $sale) => (float) $sale->total_amount) + $itemRefund;

        // Senior Citizen / PWD discounts given on today's PAID sales.
        $senior = (clone $todaySales)->where('sale_status', 'PAID')->whereIn('discount_type', ['SENIOR', 'PWD']);

        return [
            // Exactly the Sales Records summary for today, so both pages always show the same numbers.
            'today' => $this->dailySummary($today),
            'month_total' => round((float) Sale::where('sale_status', 'PAID')
                ->whereBetween('sale_datetime', [$today->copy()->startOfMonth(), $day[1]])
                ->sum('total_amount'), 2),
            'voided_sales' => $voidedSales->count(),
            'voided_items' => $voidedItems->count(),
            'refund_total' => round($refund, 2),
            'senior_count' => (clone $senior)->count(),
            'senior_amount' => round((float) (clone $senior)->sum('discount_amount'), 2),
            // The 5 newest sales (paid or voided), each links to its receipt.
            'latest' => Sale::with('cashier')->orderByDesc('sale_datetime')->orderByDesc('sale_id')->limit(5)->get(),
        ];
    }

    // 00:00:00 to 23:59:59 of that day (app timezone Asia/Manila, the same time sale_datetime and voided_at are saved in).
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
