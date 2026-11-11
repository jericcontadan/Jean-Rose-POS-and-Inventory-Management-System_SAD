<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

// Receipt (screen 4). Shows a saved sale; it never changes anything.
class ReceiptController extends Controller
{
    // GET /receipts/{sale}
    public function show(Sale $sale): View
    {
        // Owner: any receipt. Cashier: only their own sales (Gate in AppServiceProvider). Others get 403.
        Gate::authorize('view-receipt', $sale);

        $sale->load(['cashier', 'items.batch.product']);

        // A product sold from two batches is saved as two sale_item rows (FEFO split).
        // On paper it is one line, so join rows with the same product and the same discount.
        $lines = $sale->items
            ->groupBy(fn (SaleItem $item) => $item->batch->product_id.'|'.$item->discount_percent)
            ->map(fn ($items) => [
                // "Serum x1 (-10%)" or "Makeup x3"
                'label' => $items->first()->batch->product->product_name.' x'.$items->sum('quantity')
                    .$this->discountText((float) $items->first()->discount_percent),
                'amount' => round($items->sum(fn (SaleItem $item) => (float) $item->line_amount), 2),
            ])
            ->values();

        return view('receipts.show', [
            'sale' => $sale,
            'lines' => $lines,
            'discount' => round((float) $sale->subtotal - (float) $sale->total_amount, 2),
        ]);
    }

    // 10.00 -> " (-10%)", 0 -> "" (no discount on that line)
    private function discountText(float $percent): string
    {
        return $percent > 0 ? ' (-'.rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%)' : '';
    }
}
