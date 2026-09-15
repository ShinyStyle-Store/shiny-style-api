<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function defaultSellableItem(): HasOne
    {
        return $this->hasOne(SellableItem::class)->where('is_default', true);
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
        $query->where(function (Builder $query): void {
            $query->whereNull('published_at')
                ->orWhere('published_at', '<=', now());
        });
    }

    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->active()->published();
    }
}
