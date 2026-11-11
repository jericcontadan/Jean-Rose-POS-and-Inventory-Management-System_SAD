<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\BatchPickerService;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

// Point of Sale screen (screen 3, FR-04 / FR-07). Cashier and owner.
// Thin controller: the cart rules live in CartService, FEFO and stock in BatchPickerService.
class PosController extends Controller
{
    public function __construct(
        private CartService $cart,
        private BatchPickerService $picker,
    ) {
    }

    // GET /pos?q=serum&category=1 : product cards + Order Details.
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:category,category_id'],
        ]);
        $search = trim($filters['q'] ?? '');
        $categoryId = $filters['category'] ?? null;

        // All products of the chosen category (query builder with bindings, never string-joined SQL).
        // The search does NOT remove cards from the page: cards that do not match are sent hidden,
        // so the search box can show them again as you type or clear it (no page reload).
        $products = Product::with('batches.inventory')
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('product_name')
            ->get();

        // What each card needs: the product, how many can be sold, one status badge, and if it matches the search.
        $cards = $products->map(fn (Product $product) => [
            'product' => $product,
            'available' => $this->picker->available($product),
            'status' => $this->picker->productStatus($product),
            'matches' => $this->matchesSearch($product, $search),
        ]);

        return view('pos.index', [
            'categories' => Category::orderBy('category_name')->get(),
            'cards' => $cards,
            'order' => $this->cart->summary(),
            'search' => $search,
            'categoryId' => $categoryId,
            'discountText' => $this->cart->percentText((float) config('pos.near_expiry_discount_percent')),
            'payToken' => $this->cart->payToken(), // one-time token for the payment box (stops double taps)
        ]);
    }

    // POST /pos/cart/{product} : "Add to order".
    public function add(Product $product): RedirectResponse
    {
        return $this->run(fn () => $this->cart->add($product), "{$product->product_name} added to the order.");
    }

    // POST /pos/cart/{product}/increase : "+" button.
    public function increase(Product $product): RedirectResponse
    {
        return $this->run(fn () => $this->cart->increase($product));
    }

    // POST /pos/cart/{product}/decrease : "−" button.
    public function decrease(Product $product): RedirectResponse
    {
        return $this->run(fn () => $this->cart->decrease($product));
    }

    // DELETE /pos/cart/{product} : "Remove" button.
    public function remove(Product $product): RedirectResponse
    {
        return $this->run(fn () => $this->cart->remove($product), "{$product->product_name} removed from the order.");
    }

    // DELETE /pos/cart : "Cancel / Void" (after the confirm box). Only clears the unpaid order.
    public function clear(): RedirectResponse
    {
        return $this->run(fn () => $this->cart->clear(), 'Order cancelled.');
    }

    // Same rule as the JavaScript in pos/index.blade.php: product name or product number
    // contains the search text, upper and lower case ignored. An empty search matches everything.
    private function matchesSearch(Product $product, string $search): bool
    {
        return $search === ''
            || Str::contains($product->product_name.' '.$product->product_number, $search, ignoreCase: true);
    }

    // Do the cart action, then go back to the POS (keeping the search and category).
    // A refused action (out of stock, too many) comes back as an error message.
    private function run(callable $action, ?string $success = null): RedirectResponse
    {
        $back = back(302, [], route('pos.index'));

        try {
            $action();
        } catch (RuntimeException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $success ? $back->with('success', $success) : $back;
    }
}
