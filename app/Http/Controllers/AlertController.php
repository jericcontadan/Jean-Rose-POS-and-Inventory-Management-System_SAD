<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

// Alerts (screen 6, FR-07). OWNER and CASHIER. Thin controller: the alert rules live in AlertService.
class AlertController extends Controller
{
    // GET /alerts : the open alerts grouped LOW STOCK, OUT OF STOCK, NEAR EXPIRY.
    public function index(AlertService $alerts): View
    {
        return view('alerts.index', [
            'groups' => $alerts->openGrouped(),
            'count' => $alerts->openCount(), // same number as the Alerts button in the header
            // Where "View" goes, decided on the server: the owner searches Inventory,
            // the cashier searches the POS (a cashier is never sent to an owner page).
            'viewRoute' => Gate::allows('owner') ? 'inventory.index' : 'pos.index',
        ]);
    }
}
