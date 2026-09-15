<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductMedia extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sellable_item_id',
        'provider',
        'type',
        'public_id',
        'secure_url',
        'alt_text_ar',
        'alt_text_en',
        'sort_order',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sellableItem(): BelongsTo
    {
        return $this->belongsTo(SellableItem::class);
    }

    #[Scope]
    protected function images(Builder $query): void
    {
        $query->where('type', 'image');
    }

    #[Scope]
    protected function videos(Builder $query): void
    {
        $query->where('type', 'video');
    }

    #[Scope]
    protected function primary(Builder $query): void
    {
        $query->where('is_primary', true);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
