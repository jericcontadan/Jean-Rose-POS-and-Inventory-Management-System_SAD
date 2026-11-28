<?php

namespace App\Http\Controllers;

use App\Exceptions\VoidException;
use App\Http\Requests\VoidRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

// Sales Records (screen 7, FR-08), Void a Sale (screen 8, FR-08/FR-09) and Void one item. OWNER only (route group can:owner).
// Thin controller: the void rules live in SaleService::void(). Excel export: ExportController (card on the Audit Log page).
class SalesRecordController extends Controller
{
    public function __construct(private SaleService $sales)
    {
    }

    // GET /sales?date=2026-11-12&void=5 : the sales of that day grouped by receipt, plus the daily summary.
    // With ?void=<sale id> the void card for that sale opens on the right (only for a PAID sale).
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'void' => ['nullable', 'integer', 'min:1'],
            'void_item' => ['nullable', 'integer', 'min:1'], // opens the "void item" card for that sale_item
            'open' => ['nullable', 'integer', 'min:1'],      // shows the items of that sale already expanded
        ]);

        // No date chosen: today in Asia/Manila (the app timezone in config/app.php).
        $date = isset($filters['date'])
            ? Carbon::createFromFormat('Y-m-d', $filters['date'])->startOfDay()
            : Carbon::today();

        $voidSale = $this->sales->saleToVoid(isset($filters['void']) ? (int) $filters['void'] : null);
        $voidItem = $voidSale ? null : $this->sales->itemToVoid(isset($filters['void_item']) ? (int) $filters['void_item'] : null);

        return view('sales.index', [
            'date' => $date,
            'sales' => $this->sales->salesForDate($date),
            'summary' => $this->sales->dailySummary($date),
            'voidSale' => $voidSale,
            'voidItems' => $voidSale ? $this->sales->itemsSummary($voidSale) : null,
            'voidItem' => $voidItem,
            // The sale whose items start expanded: the one from ?open= or the one of the item being voided.
            'openSaleId' => $voidItem?->sale_id ?? (isset($filters['open']) ? (int) $filters['open'] : null),
        ]);
    }

    // POST /sales/{sale}/void : "Confirm void" in the void card. VoidRequest has already checked the reason.
    public function void(VoidRequest $request, Sale $sale): RedirectResponse
    {
        // Go back to the day of that sale (without the void card).
        $back = route('sales.index', ['date' => $sale->sale_datetime->format('Y-m-d')]);

        try {
            $this->sales->void($request->user(), $sale, $request->validated('reason'));
        } catch (VoidException $e) {
            // For example: already voided. Nothing was changed.
            return redirect($back)->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e); // write the real error to the log for us, show a simple message to the owner
            return redirect($back)->with('error', 'Void failed. Nothing was changed. Please try again.');
        }

        return redirect($back)->with('success', "Sale {$sale->receiptNumber()} was voided.");
    }

    // POST /sales/items/{item}/void : "Confirm void item" in the void item card. VoidRequest has already checked the reason.
    public function voidItem(VoidRequest $request, SaleItem $item): RedirectResponse
    {
        // Go back to the day of that sale with its items expanded (without the card).
        $sale = $item->sale;
        $back = route('sales.index', ['date' => $sale->sale_datetime->format('Y-m-d'), 'open' => $sale->sale_id]);

        try {
            $sale = $this->sales->voidItem($request->user(), $item, $request->validated('reason'));
        } catch (VoidException $e) {
            // For example: the item or the sale is already voided. Nothing was changed.
            return redirect($back)->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            return redirect($back)->with('error', 'Void failed. Nothing was changed. Please try again.');
        }

        $message = "Item {$item->displayName()} x{$item->quantity} was voided from sale {$sale->receiptNumber()}.";
        if ($sale->sale_status === 'CANCELLED') {
            $message .= ' No items are left, so the sale is now cancelled.';
        }

        return redirect($back)->with('success', $message);
    }
}
