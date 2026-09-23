<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductOptionValue extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_option_id',
        'code',
        'value_ar',
        'value_en',
        'metadata',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $value): void {
            $value->value_ar_normalized = trim((string) $value->value_ar);
            $value->value_en_normalized = mb_strtolower(trim((string) $value->value_en));
        });
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function sellableItems(): BelongsToMany
    {
        return $this->belongsToMany(SellableItem::class, 'sellable_item_option_values');
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
