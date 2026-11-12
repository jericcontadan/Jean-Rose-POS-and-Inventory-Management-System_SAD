<?php

namespace App\Http\Controllers;

use App\Exceptions\VoidException;
use App\Http\Requests\VoidRequest;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

// Sales Records (screen 7, FR-08) and Void a Sale (screen 8, FR-08/FR-09). OWNER only (route group can:owner).
// Thin controller: the void rules live in SaleService::void(). Export to Excel comes in Step 12.
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
        ]);

        // No date chosen: today in Asia/Manila (the app timezone in config/app.php).
        $date = isset($filters['date'])
            ? Carbon::createFromFormat('Y-m-d', $filters['date'])->startOfDay()
            : Carbon::today();

        $voidSale = $this->sales->saleToVoid(isset($filters['void']) ? (int) $filters['void'] : null);

        return view('sales.index', [
            'date' => $date,
            'sales' => $this->sales->salesForDate($date),
            'summary' => $this->sales->dailySummary($date),
            'voidSale' => $voidSale,
            'voidItems' => $voidSale ? $this->sales->itemsSummary($voidSale) : null,
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
}
