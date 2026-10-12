<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnInventoryTransferReversal extends Model
{
    protected $fillable = [
        'return_inventory_transfer_id', 'reason', 'recorded_by_user_id', 'idempotency_key',
        'request_fingerprint', 'source_stock_before', 'source_stock_after', 'target_stock_before',
        'target_stock_after', 'response_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'source_stock_before' => 'integer',
            'source_stock_after' => 'integer',
            'target_stock_before' => 'integer',
            'target_stock_after' => 'integer',
            'response_snapshot' => 'array',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(ReturnInventoryTransfer::class, 'return_inventory_transfer_id');
    }
}
