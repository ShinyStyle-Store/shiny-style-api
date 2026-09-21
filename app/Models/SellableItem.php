<?php

namespace App\Models;

use App\Enums\MediaRole;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellableItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'original_price',
        'stock_quantity',
        'reserved_quantity',
        'status',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'original_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'sellable_item_option_values');
    }

    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'mediable')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $images): void {
                    $images->where('role', MediaRole::VARIANT_IMAGE)
                        ->whereHas('mediaAsset', fn (Builder $asset) => $asset->where('media_type', 'image'));
                })->orWhere(function (Builder $videos): void {
                    $videos->where('role', MediaRole::VARIANT_VIDEO)
                        ->whereHas('mediaAsset', fn (Builder $asset) => $asset->where('media_type', 'video'));
                });
            });
    }

    public function variantImages(): MorphMany
    {
        return $this->mediaAttachments()->where('role', MediaRole::VARIANT_IMAGE)
            ->whereHas('mediaAsset', fn (Builder $query) => $query->where('media_type', 'image'))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function primaryVariantImage(): MorphOne
    {
        return $this->morphOne(MediaAttachment::class, 'mediable')
            ->where('role', MediaRole::VARIANT_IMAGE)->where('is_primary', true)
            ->whereHas('mediaAsset', fn (Builder $query) => $query->where('media_type', 'image'))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function variantVideos(): MorphMany
    {
        return $this->mediaAttachments()->where('role', MediaRole::VARIANT_VIDEO)
            ->whereHas('mediaAsset', fn (Builder $query) => $query->where('media_type', 'video'))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function availableQuantity(): int
    {
        return (int) $this->stock_quantity - (int) $this->reserved_quantity;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }

    #[Scope]
    protected function inStock(Builder $query): void
    {
        $query->where('stock_quantity', '>', 0);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
