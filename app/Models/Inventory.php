<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// The stock count and status of one batch (table inventory).
// Only InventoryService may change quantity_on_hand (see docs/08-architecture.md).
class Inventory extends Model
{
    protected $table = 'inventory';
    protected $primaryKey = 'inventory_id';
    public $timestamps = false; // updated_at is set by MySQL (ON UPDATE CURRENT_TIMESTAMP)

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id', 'batch_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(InventoryHistory::class, 'inventory_id', 'inventory_id');
    }
}
