<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnInventoryTransfer extends Model
{
    protected $fillable = [
        'order_id', 'return_receipt_id', 'return_receipt_item_id', 'source_sellable_item_id',
        'target_sellable_item_id', 'quantity', 'reason', 'recorded_by_user_id', 'idempotency_key',
        'request_fingerprint', 'source_stock_before', 'source_stock_after', 'target_stock_before',
        'target_stock_after', 'response_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'source_stock_before' => 'integer',
            'source_stock_after' => 'integer',
            'target_stock_before' => 'integer',
            'target_stock_after' => 'integer',
            'response_snapshot' => 'array',
        ];
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(ReturnReceiptItem::class, 'return_receipt_item_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SellableItem::class, 'source_sellable_item_id')->withTrashed();
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SellableItem::class, 'target_sellable_item_id')->withTrashed();
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(ReturnInventoryTransferReversal::class, 'return_inventory_transfer_id');
    }
}
