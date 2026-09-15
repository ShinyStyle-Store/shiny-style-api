<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sellableItems = $this->sellableItems;
        $sellableItem = $this->activeDefaultSellableItem($sellableItems);
        $category = $this->primaryCategory();
        $image = $this->primaryImage();

        return [
            'id' => (string) $this->getKey(),
            'slug' => $this->slug,
            'name' => [
                'ar' => $this->name_ar,
                'en' => $this->name_en,
            ],
            'category' => $category ? [
                'slug' => $category->slug,
                'name' => [
                    'ar' => $category->name_ar,
                    'en' => $category->name_en,
                ],
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

    protected function activeDefaultSellableItem($sellableItems)
    {
        return $sellableItems->firstWhere('is_default', true) ?? $sellableItems->first();
    }

    protected function primaryImage()
    {
        return $this->media->first(
            fn ($media): bool => $media->type === 'image' && $media->is_primary,
        ) ?? $this->media->firstWhere('type', 'image');
    }
}
