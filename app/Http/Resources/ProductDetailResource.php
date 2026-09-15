<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProductDetailResource extends ProductListResource
{
    public function toArray(Request $request): array
    {
        $sellableItems = $this->sellableItems;
        $defaultSellableItem = $this->activeDefaultSellableItem($sellableItems);

        return [
            ...parent::toArray($request),
            'description' => [
                'ar' => $this->description_ar,
                'en' => $this->description_en,
            ],
            'features' => $this->features,
            'specifications' => $this->specifications,
            'gallery' => $this->media
                ->where('type', 'image')
                ->pluck('secure_url')
                ->values()
                ->all(),
            'videoUrl' => $this->media
                ->firstWhere('type', 'video')?->secure_url,
            'options' => $this->options->map(fn ($option): array => [
                'id' => (string) $option->getKey(),
                'code' => $option->code,
                'name' => [
                    'ar' => $option->name_ar,
                    'en' => $option->name_en,
                ],
                'values' => $option->values->map(fn ($value): array => [
                    'id' => (string) $value->getKey(),
                    'code' => $value->code,
                    'label' => [
                        'ar' => $value->value_ar,
                        'en' => $value->value_en,
                    ],
                    'metadata' => $value->metadata,
                ])->values()->all(),
            ])->values()->all(),
            'sellableItems' => $sellableItems->map(fn ($item): array => [
                'id' => (string) $item->getKey(),
                'sku' => $item->sku,
                'price' => (float) $item->price,
                'originalPrice' => $item->original_price === null
                    ? null
                    : (float) $item->original_price,
                'inStock' => $item->stock_quantity > 0,
                'optionValues' => $item->optionValues->mapWithKeys(
                    fn ($value): array => [$value->option->code => $value->code],
                )->all(),
            ])->values()->all(),
            'defaultSellableItemId' => $defaultSellableItem
                ? (string) $defaultSellableItem->getKey()
                : null,
        ];
    }
}
