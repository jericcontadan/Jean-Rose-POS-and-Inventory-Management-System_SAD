<?php

namespace App\Http\Controllers;

use App\Http\Requests\BatchRequest;
use App\Http\Requests\PriceRequest;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

// Update price (screen 10) and Add or Edit Product (screen 12), FR-06 / FR-09. OWNER only (route group can:owner).
// Thin controller: the rules live in InventoryService (changePrice, addProduct, editProduct, addBatch).
class ProductController extends Controller
{
    private const SAVE_FAILED = 'Save failed. Nothing was changed. Please try again.';

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
            return redirect()->route('products.price.edit', $product)->withInput()->with('error', self::SAVE_FAILED);
        }

        $message = $changed
            ? "Price of {$product->product_name} is now ₱".number_format((float) $newPrice, 2).'.'
            : "Price of {$product->product_name} is unchanged. Nothing was saved.";

        return redirect()->route('inventory.index')->with('success', $message);
    }

    // GET /products/create : the Add product card.
    public function create(): View
    {
        return view('products.create', $this->dropdowns());
    }

    // POST /products : "Save product". ProductRequest has already checked every field.
    public function store(ProductRequest $request): RedirectResponse
    {
        try {
            $product = $this->inventory->addProduct($request->user(), $request->validated(), $request->file('image'));
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('products.create')->withInput()->with('error', self::SAVE_FAILED);
        }

        return redirect()->route('inventory.index')
            ->with('success', "Product {$product->product_name} ({$product->product_number}) was added.");
    }

    // GET /products/{product}/edit : the Edit product card and the "Add new batch" card.
    public function edit(Product $product): View
    {
        return view('products.edit', $this->dropdowns() + [
            'product' => $product,
            'batches' => $product->batches()->with('inventory')->orderBy('expiry_date')->get(),
        ]);
    }

    // PUT /products/{product} : "Save changes" (product fields only, no batch).
    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        try {
            $changed = $this->inventory->editProduct($request->user(), $product, $request->validated(), $request->file('image'));
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('products.edit', $product)->withInput()->with('error', self::SAVE_FAILED);
        }

        $message = $changed
            ? "Product {$request->validated('product_name')} was saved."
            : "Product {$product->product_name} is unchanged. Nothing was saved.";

        return redirect()->route('inventory.index')->with('success', $message);
    }

    // POST /products/{product}/batches : "Add batch" (a new delivery with its own expiry date).
    public function storeBatch(BatchRequest $request, Product $product): RedirectResponse
    {
        try {
            $batch = $this->inventory->addBatch($request->user(), $product, $request->validated());
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('products.edit', $product)->withInput()->with('error', self::SAVE_FAILED);
        }

        return redirect()->route('products.edit', $product)
            ->with('success', "Batch {$batch->batch_number} ({$batch->quantity_in_batch} pcs) was added to {$product->product_name}.");
    }

    // The Category and Supplier dropdowns, sorted by name.
    private function dropdowns(): array
    {
        return [
            'categories' => Category::orderBy('category_name')->get(),
            'suppliers' => Supplier::orderBy('supplier_name')->get(),
        ];
    }
}
