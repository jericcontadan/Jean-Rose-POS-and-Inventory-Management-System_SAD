<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\UserAccount;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

// The ONLY place that writes to audit_log (docs/08-architecture.md, rule 5).
// The audit log is read-only in the app: there is no update or delete here.
class AuditService
{
    // The values of audit_log.action_type (also the Action filter on the Audit Log screen).
    public const ACTIONS = ['LOGIN', 'SALE', 'VOID', 'INVENTORY_UPDATE', 'EXPORT'];

    /**
     * Save one audit row.
     *
     * $action: LOGIN, SALE, VOID, INVENTORY_UPDATE or EXPORT
     * $module: SALES or INVENTORY
     * $ref:    receipt number like 'S-0001' (or null)
     */
    public function log(
        UserAccount $user,
        string $action,
        string $module,
        ?string $ref = null,
        ?string $details = null,
        ?int $saleId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user->user_id,
            'sale_id' => $saleId,
            'action_type' => $action,
            'module' => $module,
            'transaction_reference' => $ref,
            'details' => $details,
            // created_at is filled by the database default (CURRENT_TIMESTAMP).
        ]);
    }

    // ---------- Reading (Audit Log screen). These only SELECT, they never change a row. ----------

    /**
     * Audit rows from the start of $from to the end of $to, newest first, 50 per page.
     * $userId and $action are optional (null = all). All filters work together.
     */
    public function search(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $userId = null,
        ?string $action = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return $this->baseQuery($from, $to)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->when($action, fn ($query) => $query->where('action_type', $action))
            ->paginate($perPage)
            ->withQueryString(); // keep the filters on the page links
    }

    // Excel export (Audit log report): every row in the date range, newest first, NOT paginated.
    public function forExport(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->baseQuery($from, $to)->get();
    }

    // Rows from the start of $from to the end of $to with their user, newest first (shared by the screen and the export).
    private function baseQuery(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return AuditLog::with('user')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderByDesc('created_at')
            ->orderByDesc('audit_log_id'); // same second: the row saved last comes first
    }

    // Every account for the User filter (inactive ones too: their old rows are still in the log).
    public function usersForFilter(): Collection
    {
        return UserAccount::orderBy('username')->get(['user_id', 'username']);
    }
}
