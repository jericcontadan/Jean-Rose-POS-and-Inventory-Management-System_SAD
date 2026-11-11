<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One paid (or later voided) receipt (table sale).
class Sale extends Model
{
    protected $table = 'sale';
    protected $primaryKey = 'sale_id';
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sale_datetime' => 'datetime',
            'subtotal' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'cash_received' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    // Receipt number shown to people: sale 1 -> "S-0001" (also used as audit_log.transaction_reference).
    public function receiptNumber(): string
    {
        return 'S-'.str_pad((string) $this->sale_id, 4, '0', STR_PAD_LEFT);
    }

    // The cashier (or owner) who made the sale.
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_id', 'user_id');
    }

    // The owner who voided the sale (null if not voided).
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'voided_by', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id', 'sale_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'sale_id', 'sale_id');
    }
}
