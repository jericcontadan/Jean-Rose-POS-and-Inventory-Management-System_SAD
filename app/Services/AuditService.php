<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\UserAccount;

// The ONLY place that writes to audit_log (docs/08-architecture.md, rule 5).
// The audit log is read-only in the app: there is no update or delete here.
class AuditService
{
    /**
     * Save one audit row.
     *
     * $action: LOGIN, SALE, VOID or INVENTORY_UPDATE
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
}
