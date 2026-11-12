<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

// Audit Log (screen 13, FR-09). OWNER only (route group can:owner).
// READ ONLY: this controller only has index(). There is no edit or delete, on purpose (T18).
// Export to Excel is shown but disabled (built in a later step).
class AuditLogController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    // GET /audit-log?from=2026-11-01&to=2026-11-12&user=3&action=SALE
    // All filters are optional and work together. No dates = today in Asia/Manila.
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            // To may not be before From (only checked when From is filled).
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'user' => ['nullable', 'integer', 'exists:user_account,user_id'],
            'action' => ['nullable', 'in:'.implode(',', AuditService::ACTIONS)],
        ]);

        // Only one date filled: use the same day for the other one.
        $fromText = $filters['from'] ?? $filters['to'] ?? null;
        $toText = $filters['to'] ?? $filters['from'] ?? null;

        $from = $fromText ? Carbon::createFromFormat('Y-m-d', $fromText)->startOfDay() : Carbon::today();
        $to = $toText ? Carbon::createFromFormat('Y-m-d', $toText)->startOfDay() : Carbon::today();

        $userId = isset($filters['user']) ? (int) $filters['user'] : null;
        $action = $filters['action'] ?? null;

        return view('audit.index', [
            'from' => $from,
            'to' => $to,
            'userId' => $userId,
            'action' => $action,
            'actions' => AuditService::ACTIONS,
            'users' => $this->audit->usersForFilter(),
            'rows' => $this->audit->search($from, $to, $userId, $action),
        ]);
    }
}
