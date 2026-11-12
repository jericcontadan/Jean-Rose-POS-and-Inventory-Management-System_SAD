<?php

namespace App\Http\Controllers;

use App\Http\Requests\PriceRequest;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

// Update price (screen 10, FR-06 / FR-09). OWNER only (route group can:owner).
// Thin controller: the price rules live in InventoryService::changePrice(). Add or Edit Product comes later.
class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
    }

    // GET /products/{product}/price : the Update price card.
    public function editPrice(Product $product): View
    {
        return view('products.price', ['product' => $product]);
    }

    // PUT /products/{product}/price : "Save price". PriceRequest has already checked the price.
    public function updatePrice(PriceRequest $request, Product $product): RedirectResponse
    {
        $newPrice = (string) $request->validated('unit_price');

        try {
            $changed = $this->inventory->changePrice($request->user(), $product, $newPrice);
        } catch (Throwable $e) {
            report($e); // write the real error to the log for us, show a simple message to the owner
            return redirect()->route('products.price.edit', $product)->withInput()
                ->with('error', 'Save failed. Nothing was changed. Please try again.');
        }

        $message = $changed
            ? "Price of {$product->product_name} is now ₱".number_format((float) $newPrice, 2).'.'
            : "Price of {$product->product_name} is unchanged. Nothing was saved.";

        return redirect()->route('inventory.index')->with('success', $message);
    }
}
