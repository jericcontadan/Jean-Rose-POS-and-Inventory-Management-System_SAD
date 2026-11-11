<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Session;
use RuntimeException;

// The current unpaid order, kept in the server session as [product_id => quantity].
// Prices, discounts and totals are NEVER taken from the browser: summary() works them out
// from the database every time, using FEFO batches (BatchPickerService) and the near-expiry rule (StockStatusService).
// Nothing here changes stock. Stock only goes down when the order is paid (Step 3).
class CartService
{
    private const KEY = 'pos_cart';

    public function __construct(
        private BatchPickerService $picker,
        private StockStatusService $status,
    ) {
    }

    // [product_id => quantity]
    public function items(): array
    {
        return Session::get(self::KEY, []);
    }

    // "Add to order": put 1 piece in the cart, or 1 more if it is already there.
    public function add(Product $product): void
    {
        $this->increase($product);
    }

    // "+" button: one more piece, but never more than the sellable stock.
    public function increase(Product $product): void
    {
        $product->loadMissing('batches.inventory');
        $available = $this->picker->available($product);
        $current = $this->items()[$product->product_id] ?? 0;

        if ($available <= 0) {
            throw new RuntimeException("{$product->product_name} is out of stock.");
        }

        if ($current + 1 > $available) {
            throw new RuntimeException("Only {$available} {$product->product_name} in stock.");
        }

        $this->save($product->product_id, $current + 1);
    }

    // "−" button: one less piece. At 1 the line is removed.
    public function decrease(Product $product): void
    {
        $current = $this->items()[$product->product_id] ?? 0;
        $this->save($product->product_id, $current - 1);
    }

    // "Remove" button: take the whole line out.
    public function remove(Product $product): void
    {
        $this->save($product->product_id, 0);
    }

    // "Cancel / Void" button: empty the unpaid order. Nothing was saved yet, so nothing else changes.
    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    /**
     * Price the whole order from the database.
     *
     * Each cart line is split over FEFO batches. For each batch part (docs/04-business-rules.md, Pay Now step 2):
     *   discount_percent = NEAR_EXPIRY_DISCOUNT_PERCENT if that batch is near expiry, else 0
     *   line_amount      = quantity * unit_price * (1 - discount_percent / 100), rounded to 2 decimals
     * subtotal = sum(quantity * unit_price), total = sum(line_amount).
     */
    public function summary(): array
    {
        $cart = $this->items();
        $products = Product::with('batches.inventory')->whereIn('product_id', array_keys($cart))->get()->keyBy('product_id');

        $lines = [];
        $discounts = [];
        $notices = [];
        $subtotal = 0.0;
        $total = 0.0;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);
            $available = $product ? $this->picker->available($product) : 0;

            // Stock may have changed since the item was added: fix the cart and tell the cashier.
            if ($available <= 0) {
                $this->save($productId, 0);
                $notices[] = ($product?->product_name ?? 'A product').' is no longer in stock and was removed.';
                continue;
            }
            if ($quantity > $available) {
                $quantity = $available;
                $this->save($productId, $quantity);
                $notices[] = "Only {$available} {$product->product_name} left, quantity was lowered.";
            }

            $unitPrice = (float) $product->unit_price;
            $lineTotal = 0.0;
            $nearQuantity = 0;
            $percent = 0.0;

            foreach ($this->picker->pick($product, $quantity) as $part) {
                $partPercent = $this->status->discountPercentFor($part['batch']->expiry_date);
                $lineTotal += round($part['quantity'] * $unitPrice * (1 - $partPercent / 100), 2);

                if ($partPercent > 0) {
                    $nearQuantity += $part['quantity'];
                    $percent = $partPercent;
                }
            }

            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineTotal = round($lineTotal, 2);

            if ($nearQuantity > 0) {
                $discounts[] = [
                    'label' => $product->product_name.' '.$this->percentText($percent)
                        .($nearQuantity < $quantity ? " ({$nearQuantity} of {$quantity})" : ''),
                    'amount' => round($lineSubtotal - $lineTotal, 2),
                ];
            }

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'available' => $available,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'near_expiry' => $nearQuantity > 0,
                'discount_text' => $this->percentText($percent),
            ];

            $subtotal += $lineSubtotal;
            $total += $lineTotal;
        }

        return [
            'lines' => $lines,
            'discounts' => $discounts,
            'notices' => $notices,
            'item_count' => array_sum(array_column($lines, 'quantity')),
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($subtotal - $total, 2),
            'total' => round($total, 2),
        ];
    }

    // 10.0 -> "10%", 7.5 -> "7.5%"
    public function percentText(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }

    // Store a quantity; 0 or less removes the line.
    private function save(int $productId, int $quantity): void
    {
        $cart = $this->items();

        if ($quantity > 0) {
            $cart[$productId] = $quantity;
        } else {
            unset($cart[$productId]);
        }

        Session::put(self::KEY, $cart);
    }
}
