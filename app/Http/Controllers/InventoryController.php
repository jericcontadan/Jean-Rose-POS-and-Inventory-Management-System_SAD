<?php

namespace App\Http\Controllers;

use App\Exceptions\BatchInUseException;
use App\Exceptions\StockRuleException;
use App\Http\Requests\RemoveStockRequest;
use App\Http\Requests\StockRequest;
use App\Models\Inventory;
use App\Services\InventoryService;
use App\Services\StockStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

// Inventory screen (screen 9, FR-06 / FR-07), Update stock (screen 10), Remove stock and Delete batch (trash).
// OWNER only (route group can:owner). Thin controller: the rules live in InventoryService::restock(), removeStock() and deleteBatch().
// Import and Export come later.
class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventory,
        private StockStatusService $status,
    ) {
    }

    // GET /inventory?q=serum : summary cards + one table row per batch.
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim($filters['q'] ?? '');

        $rows = $this->inventory->batchRows();

        // Like the POS: rows that do not match are sent hidden, so typing or Clear can show them again
        // without reloading the page.
        $rows = $rows->map(fn (array $row) => $row + ['matches' => $this->matchesSearch($row, $search)]);

        return view('inventory.index', [
            'rows' => $rows,
            'summary' => $this->inventory->summary($rows), // always the whole inventory, not only the search result
            'search' => $search,
        ]);
    }

    // GET /inventory/{inventory}/stock : the Update stock card for one batch.
    public function editStock(Inventory $inventory): View
    {
        $inventory->load('batch.product');

        return view('inventory.stock', [
            'inventory' => $inventory,
            'expired' => $this->status->isExpired($inventory->batch->expiry_date), // expired: Save is turned off
        ]);
    }

    // PUT /inventory/{inventory}/stock : "Save stock". StockRequest has already checked the quantity.
    public function updateStock(StockRequest $request, Inventory $inventory): RedirectResponse
    {
        try {
            $saved = $this->inventory->restock($request->user(), $inventory, (int) $request->validated('quantity'));
        } catch (StockRuleException $e) {
            // Refused by a rule (expired batch): show the reason, nothing was saved.
            return redirect()->route('inventory.stock.edit', $inventory)->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e); // write the real error to the log for us, show a simple message to the owner
            return redirect()->route('inventory.stock.edit', $inventory)->withInput()
                ->with('error', 'Save failed. Nothing was changed. Please try again.');
        }

        $saved->load('batch.product');

        return redirect()->route('inventory.index')->with('success',
            "Stock of {$saved->batch->product->product_name} (batch {$saved->batch->batch_number}) is now {$saved->quantity_on_hand}.");
    }

    // GET /inventory/{inventory}/remove : the Remove stock card for one batch (expired or damaged pieces).
    public function editRemove(Inventory $inventory): View
    {
        return view('inventory.remove', [
            'inventory' => $inventory->load('batch.product'),
            'reasons' => InventoryService::REMOVE_REASONS,
        ]);
    }

    // PUT /inventory/{inventory}/remove : "Remove stock". RemoveStockRequest has already checked the quantity and reason.
    public function updateRemove(RemoveStockRequest $request, Inventory $inventory): RedirectResponse
    {
        try {
            $saved = $this->inventory->removeStock(
                $request->user(), $inventory, (int) $request->validated('quantity'), $request->validated('reason'));
        } catch (StockRuleException $e) {
            // More than on hand: show the reason, nothing was saved.
            return redirect()->route('inventory.remove.edit', $inventory)->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('inventory.remove.edit', $inventory)->withInput()
                ->with('error', 'Save failed. Nothing was changed. Please try again.');
        }

        $saved->load('batch.product');

        return redirect()->route('inventory.index')->with('success',
            "Stock of {$saved->batch->product->product_name} (batch {$saved->batch->batch_number}) is now {$saved->quantity_on_hand}.");
    }

    // DELETE /inventory/{inventory} : the trash on a row. Deletes that batch only if it was never sold
    // and has no inventory history (checked again inside InventoryService::deleteBatch()).
    public function destroy(Request $request, Inventory $inventory): RedirectResponse
    {
        try {
            $text = $this->inventory->deleteBatch($request->user(), $inventory);
        } catch (BatchInUseException $e) {
            return redirect()->route('inventory.index')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('inventory.index')->with('error', 'Delete failed. Nothing was changed. Please try again.');
        }

        return redirect()->route('inventory.index')->with('success', $text.'.');
    }

    // Same rule as the JavaScript in inventory/index.blade.php: product name, product number,
    // batch number or supplier contains the search text (case ignored). Empty search matches everything.
    private function matchesSearch(array $row, string $search): bool
    {
        $text = implode(' ', [$row['product_name'], $row['product_number'], $row['batch_number'], $row['supplier'] ?? '']);

        return $search === '' || Str::contains($text, $search, ignoreCase: true);
    }
}
