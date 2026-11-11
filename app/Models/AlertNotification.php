<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A stock or expiry alert for one batch (table alert_notification).
// Open alert = resolved_at is null. Only AlertService::sync() writes rows.
class AlertNotification extends Model
{
    protected $table = 'alert_notification';
    protected $primaryKey = 'alert_id';
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'emailed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id', 'batch_id');
    }
}
