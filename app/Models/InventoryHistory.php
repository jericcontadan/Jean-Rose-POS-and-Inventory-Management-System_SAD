<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One stock movement: RESTOCK (+), SALE (-) or VOID (+) (table inventory_history).
class InventoryHistory extends Model
{
    protected $table = 'inventory_history';
    protected $primaryKey = 'transaction_id';
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_datetime' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id', 'inventory_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id', 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_id', 'user_id');
    }
}
