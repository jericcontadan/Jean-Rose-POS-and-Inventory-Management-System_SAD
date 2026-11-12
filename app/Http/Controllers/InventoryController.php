<?php

namespace App\Http\Controllers;

use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

// Inventory screen (screen 9, FR-06 / FR-07). OWNER only (route group can:owner).
// Read only in this step: Update stock, Update price, Delete, Add product, Import and Export come later.
class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
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

    // Same rule as the JavaScript in inventory/index.blade.php: product name, product number,
    // batch number or supplier contains the search text (case ignored). Empty search matches everything.
    private function matchesSearch(array $row, string $search): bool
    {
        $text = implode(' ', [$row['product_name'], $row['product_number'], $row['batch_number'], $row['supplier'] ?? '']);

        return $search === '' || Str::contains($text, $search, ignoreCase: true);
    }
}
