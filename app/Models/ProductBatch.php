<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// One delivery of a product with its own expiry date (table product_batch).
class ProductBatch extends Model
{
    protected $table = 'product_batch';
    protected $primaryKey = 'batch_id';
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'date_received' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // Each batch has exactly one stock row.
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class, 'batch_id', 'batch_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'batch_id', 'batch_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AlertNotification::class, 'batch_id', 'batch_id');
    }
}
