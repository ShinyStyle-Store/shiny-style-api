<?php

namespace App\Models;

use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_receipt_id', 'order_item_id', 'sellable_item_id', 'original_quantity',
        'initial_received_quantity', 'initial_restockable_quantity', 'initial_reason',
        'effective_received_quantity', 'effective_restockable_quantity', 'effective_reason',
        'initial_note', 'effective_note',
    ];

    protected function casts(): array
    {
        return [
            'initial_reason' => ReturnReason::class,
            'effective_reason' => ReturnReason::class,
            'original_quantity' => 'integer',
            'initial_received_quantity' => 'integer',
            'initial_restockable_quantity' => 'integer',
            'effective_received_quantity' => 'integer',
            'effective_restockable_quantity' => 'integer',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ReturnReceipt::class, 'return_receipt_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function sellableItem(): BelongsTo
    {
        return $this->belongsTo(SellableItem::class)->withTrashed();
    }
}
