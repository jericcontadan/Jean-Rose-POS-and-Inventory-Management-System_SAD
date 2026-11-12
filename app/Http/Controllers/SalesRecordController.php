<?php

namespace App\Http\Controllers;

use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

// Sales Records (screen 7, FR-08). OWNER only (route group can:owner).
// READ ONLY in this step: Void (Step 7) and Export to Excel (Step 12) are shown but not working yet.
class SalesRecordController extends Controller
{
    public function __construct(private SaleService $sales)
    {
    }

    // GET /sales?date=2026-11-12 : the sales of that day grouped by receipt, plus the daily summary.
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // No date chosen: today in Asia/Manila (the app timezone in config/app.php).
        $date = isset($filters['date'])
            ? Carbon::createFromFormat('Y-m-d', $filters['date'])->startOfDay()
            : Carbon::today();

        return view('sales.index', [
            'date' => $date,
            'sales' => $this->sales->salesForDate($date),
            'summary' => $this->sales->dailySummary($date),
        ]);
    }
}
