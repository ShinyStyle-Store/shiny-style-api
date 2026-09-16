<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sellableItems = $this->publicSellableItems();
        $sellableItem = $this->displaySellableItem($sellableItems);
        $category = $this->primaryCategory();
        $image = $this->primaryImage();

        return [
            'id' => (string) $this->getKey(),
            'slug' => $this->slug,
            'name' => $this->localizedValue($this->name_ar, $this->name_en),
            'category' => $category ? [
                'slug' => $category->slug,
                'name' => $this->localizedValue($category->name_ar, $category->name_en),
            ] : null,
            'price' => $sellableItem ? (float) $sellableItem->price : null,
            'originalPrice' => $sellableItem?->original_price === null
                ? null
                : (float) $sellableItem->original_price,
            'badge' => $this->badge,
            'inStock' => $sellableItems->contains(
                fn ($item): bool => $item->stock_quantity > 0,
            ),
            'image' => $image?->secure_url,
        ];
    }

    protected function primaryCategory()
    {
        return $this->categories->first(
            fn ($category): bool => (bool) ($category->pivot?->is_primary ?? false),
        ) ?? $this->categories->first();
    }

    protected function publicSellableItems(): Collection
    {
        return $this->resource->publicSellableItems($this->sellableItems);
    }

    protected function displaySellableItem(Collection $sellableItems)
    {
        return $this->resource->displaySellableItem($sellableItems);
    }

    protected function localizedValue(mixed $arabic, mixed $english): mixed
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== '' ? $english : null);
    }

    protected function localizedJsonValue(mixed $value): mixed
    {
        if (! is_array($value) || (! array_key_exists('ar', $value) && ! array_key_exists('en', $value))) {
            return $value;
        }

        return $this->localizedValue($value['ar'] ?? null, $value['en'] ?? null);
    }

    protected function primaryImage()
    {
        return $this->media->first(
            fn ($media): bool => $media->type === 'image' && $media->is_primary,
        ) ?? $this->media->firstWhere('type', 'image');
    }
}
