<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\DiscountService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

// Receipt (screen 4). Shows a saved sale; it never changes anything.
class ReceiptController extends Controller
{
    // GET /receipts/{sale}
    public function show(Sale $sale, DiscountService $discounts): View
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
                // "Serum x1 (-10%)" or "Makeup x3" (the product name saved at the time of sale)
                'label' => $items->first()->displayName().' x'.$items->sum('quantity')
                    .$this->discountText((float) $items->first()->discount_percent)
                    .($items->first()->isVoided() ? ' (VOIDED)' : ''),
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
            'refunded' => $this->refunded($sale),
        ]);
    }

    // Money given back for voided items, or null when no item was voided (no "Refunded" line on the receipt).
    // What the customer first paid is cash_received - change_amount; the sale total is now lower by the refund.
    private function refunded(Sale $sale): ?float
    {
        $voided = $sale->items->filter(fn (SaleItem $item) => $item->isVoided());
        if ($voided->isEmpty()) {
            return null;
        }

        if ($sale->cash_received === null) {
            // Very old sale without cash saved: add up the voided lines instead.
            return round($voided->sum(fn (SaleItem $item) => (float) $item->line_amount), 2);
        }

        return round((float) $sale->cash_received - (float) $sale->change_amount - (float) $sale->total_amount, 2);
    }

    // 10.00 -> " (-10%)", 0 -> "" (no discount on that line)
    private function discountText(float $percent): string
    {
        return $percent > 0 ? ' (-'.rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%)' : '';
    }
}
