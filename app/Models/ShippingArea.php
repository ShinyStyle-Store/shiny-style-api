<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'parent_id',
        'type',
        'name_ar',
        'name_en',
        'shipping_fee',
        'is_active',
        'is_selectable',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'shipping_fee' => 'decimal:2',
            'is_active' => 'boolean',
            'is_selectable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function selectable(Builder $query): void
    {
        $query->where('is_selectable', true);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function localizedName(): string
    {
        $locale = strtolower((string) app()->getLocale());
        $primaryLocale = explode('-', $locale, 2)[0];
        $fallbackLocale = strtolower((string) config('app.fallback_locale', 'en'));
        $fallbackLanguage = explode('-', $fallbackLocale, 2)[0];

        foreach ([$primaryLocale, $fallbackLanguage, 'en', 'ar'] as $language) {
            $name = $language === 'ar' ? $this->name_ar : $this->name_en;

            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        return 'Shipping area';
    }
}
