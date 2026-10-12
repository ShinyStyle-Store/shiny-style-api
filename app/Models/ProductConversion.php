<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductConversion extends Model
{
    protected $fillable = [
        'product_id',
        'source_sellable_item_id',
        'idempotency_key',
        'request_fingerprint',
        'context_token',
        'old_stock',
        'allocated_stock',
        'stock_difference',
        'stock_difference_reason',
        'return_restock_baseline',
        'allocation',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'old_stock' => 'integer',
            'allocated_stock' => 'integer',
            'stock_difference' => 'integer',
            'return_restock_baseline' => 'array',
            'allocation' => 'array',
            'result' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceSellableItem(): BelongsTo
    {
        return $this->belongsTo(SellableItem::class, 'source_sellable_item_id');
    }
}
