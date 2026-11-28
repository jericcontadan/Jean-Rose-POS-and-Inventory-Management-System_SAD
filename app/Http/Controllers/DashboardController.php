<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use App\Services\SaleService;
use App\Services\StockStatusService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

// Dashboard: the owner's home page after login. OWNER only (route group can:owner).
// Thin controller: the numbers come from SaleService (same rules as Sales Records) and AlertService.
class DashboardController extends Controller
{
    // The alert cards, in the order the owner should act on them.
    private const ALERT_TYPES = [StockStatusService::EXPIRED, StockStatusService::OUT, StockStatusService::NEAR, StockStatusService::LOW];

    // GET /dashboard
    public function index(SaleService $sales, AlertService $alerts): View
    {
        // Count the open alerts of each type (0 when a type has none).
        $groups = $alerts->openGrouped();
        $alertCounts = [];
        foreach (self::ALERT_TYPES as $type) {
            $alertCounts[$type] = isset($groups[$type]) ? $groups[$type]->count() : 0;
        }

        return view('dashboard.index', [
            'today' => Carbon::today(), // Asia/Manila (config/app.php)
            'dashboard' => $sales->dashboard(Carbon::today()),
            'alertCounts' => $alertCounts,
        ]);
    }
}
