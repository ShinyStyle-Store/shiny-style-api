<?php

namespace App\Models;

use App\Enums\MediaRole;
use App\Services\CategoryHierarchyService;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'features',
        'specifications',
        'badge',
        'status',
        'is_featured',
        'published_at',
        'video_url',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'specifications' => 'array',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    public function sellableItems(): HasMany
    {
        return $this->hasMany(SellableItem::class);
    }

    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'mediable')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $images): void {
                    $images->where('role', MediaRole::PRODUCT_IMAGE)
                        ->whereHas('mediaAsset', fn (Builder $asset) => $asset->where('media_type', 'image'));
                })->orWhere(function (Builder $videos): void {
                    $videos->where('role', MediaRole::PRODUCT_VIDEO)
                        ->whereHas('mediaAsset', fn (Builder $asset) => $asset->where('media_type', 'video'));
                });
            });
    }

    public function productImages(): MorphMany
    {
        return $this->mediaAttachments()->where('role', MediaRole::PRODUCT_IMAGE)
            ->whereHas('mediaAsset', fn (Builder $query) => $query->where('media_type', 'image'))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function primaryProductImage(): MorphOne
    {
        return $this->morphOne(MediaAttachment::class, 'mediable')
            ->where('role', MediaRole::PRODUCT_IMAGE)->where('is_primary', true)
            ->whereHas('mediaAsset', fn (Builder $query) => $query->where('media_type', 'image'))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function productVideos(): MorphMany
    {
        return $this->mediaAttachments()->where('role', MediaRole::PRODUCT_VIDEO)
            ->whereHas('mediaAsset', fn (Builder $query) => $query->where('media_type', 'video'))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function reviewImages(): HasMany
    {
        return $this->hasMany(ProductReviewImage::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function defaultSellableItem(): HasOne
    {
        return $this->hasOne(SellableItem::class)->where('is_default', true);
    }

    public function publicSellableItems(Collection $sellableItems): Collection
    {
        return $sellableItems
            ->filter(fn (SellableItem $sellableItem): bool => $sellableItem->status === 'active'
                && $sellableItem->deleted_at === null)
            ->sortBy('id')
            ->values();
    }

    public function displaySellableItem(Collection $sellableItems): ?SellableItem
    {
        $publicSellableItems = $this->publicSellableItems($sellableItems);

        return $publicSellableItems->firstWhere('is_default', true)
            ?? $publicSellableItems->first(
                fn (SellableItem $sellableItem): bool => $sellableItem->stock_quantity > 0,
            )
            ?? $publicSellableItems->first();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }

    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    #[Scope]
    protected function visible(Builder $query, ?array $visibleCategoryIds = null): void
    {
        $visibleCategoryIds ??= app(CategoryHierarchyService::class)->effectiveVisibleIds();

        $query
            ->active()
            ->published()
            ->whereHas('sellableItems', function (Builder $query): void {
                $query->active();
            })
            ->whereHas('categories', function (Builder $query) use ($visibleCategoryIds): void {
                $query->whereIn('categories.id', $visibleCategoryIds);
            });
    }
}
