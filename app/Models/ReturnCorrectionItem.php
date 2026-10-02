<?php

namespace App\Models;

use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnCorrectionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_correction_id', 'return_receipt_item_id', 'previous_received_quantity',
        'previous_restockable_quantity', 'previous_reason', 'new_received_quantity',
        'new_restockable_quantity', 'new_reason', 'new_note',
    ];

    protected function casts(): array
    {
        return [
            'previous_reason' => ReturnReason::class,
            'new_reason' => ReturnReason::class,
            'previous_received_quantity' => 'integer',
            'previous_restockable_quantity' => 'integer',
            'new_received_quantity' => 'integer',
            'new_restockable_quantity' => 'integer',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(ReturnCorrection::class, 'return_correction_id');
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(ReturnReceiptItem::class, 'return_receipt_item_id');
    }
}
