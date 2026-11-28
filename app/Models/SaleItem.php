<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One line of a sale, sold from one batch (table sale_item).
class SaleItem extends Model
{
    protected $table = 'sale_item';
    protected $primaryKey = 'sale_item_id';
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    // The product name as it was when sold (saved on the row). Very old rows without it fall back to the current name.
    public function displayName(): string
    {
        return $this->product_name ?? $this->batch->product->product_name;
    }

    // true when this line was voided on its own (item void). Voided lines are never counted in totals.
    public function isVoided(): bool
    {
        return $this->item_status === 'VOIDED';
    }

    // The owner who voided this line (null if not voided).
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'voided_by', 'user_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'sale_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id', 'batch_id');
    }
}
