<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\DiscountService;
use App\Services\SaleService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

// Receipt (screen 4). Shows a saved sale; it never changes anything.
class ReceiptController extends Controller
{
    // GET /receipts/{sale}
    public function show(Sale $sale, DiscountService $discounts, SaleService $sales): View
    {
        // Owner: any receipt. Cashier: only their own sales (Gate in AppServiceProvider). Others get 403.
        Gate::authorize('view-receipt', $sale);

        $sale->load(['cashier', 'items.batch.product']);

        // A product sold from two batches is saved as two sale_item rows (FEFO split).
        // On paper it is one line, so join rows with the same product, the same discount and the same status.
        // A voided item gets its own line marked "(VOIDED)"; it is not in the totals (the sale totals leave it out).
        $lines = $sale->items
            ->groupBy(fn (SaleItem $item) => $item->batch->product_id.'|'.$item->discount_percent.'|'.$item->item_status)
            ->map(fn ($items) => [
                // "Serum (-10%)" or "Makeup (VOIDED)" (the product name saved at the time of sale)
                'name' => $items->first()->displayName()
                    .$this->discountText((float) $items->first()->discount_percent)
                    .($items->first()->isVoided() ? ' (VOIDED)' : ''),
                // Printed as "2 x 150.00": the price when it was sold (same for every batch of the product)
                'quantity' => (int) $items->sum('quantity'),
                'unit_price' => (float) $items->first()->unit_price,
                'amount' => round($items->sum(fn (SaleItem $item) => (float) $item->line_amount), 2),
                'voided' => $items->first()->isVoided(),
            ])
            ->values();

        return view('receipts.show', [
            'sale' => $sale,
            'lines' => $lines,
            // Near-expiry discount only. The Senior / PWD discount (sale.discount_amount) is its own line on the receipt.
            'discount' => round((float) $sale->subtotal - (float) $sale->total_amount - (float) $sale->discount_amount, 2),
            'seniorLabel' => $discounts->label($sale->discount_type),
            // Money given back for voided items, or null when no item was voided (no "Refunded" line).
            'refunded' => $sales->itemRefund($sale),
        ]);
    }

    // 10.00 -> " (-10%)", 0 -> "" (no discount on that line)
    private function discountText(float $percent): string
    {
        return $percent > 0 ? ' (-'.rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%)' : '';
    }
}
