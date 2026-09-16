<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProductDetailResource extends ProductListResource
{
    public function toArray(Request $request): array
    {
        $sellableItems = $this->publicSellableItems();
        $displaySellableItem = $this->displaySellableItem($sellableItems);
        $activeOptionValueIds = $sellableItems
            ->flatMap(fn ($sellableItem) => $sellableItem->optionValues->modelKeys())
            ->unique();

        return [
            ...parent::toArray($request),
            'description' => $this->localizedValue($this->description_ar, $this->description_en),
            'features' => $this->localizedJsonValue($this->features),
            'specifications' => $this->localizedJsonValue($this->specifications),
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
                'name' => $this->localizedValue($option->name_ar, $option->name_en),
                'values' => $option->values
                    ->filter(fn ($value): bool => $activeOptionValueIds->contains($value->getKey()))
                    ->map(fn ($value): array => [
                        'id' => (string) $value->getKey(),
                        'code' => $value->code,
                        'label' => $this->localizedValue($value->value_ar, $value->value_en),
                        'metadata' => $value->metadata,
                    ])->values()->all(),
            ])->filter(fn ($option): bool => $option['values'] !== [])->values()->all(),
            'sellableItems' => $sellableItems->map(fn ($item): array => [
                'id' => (string) $item->getKey(),
                'sku' => $item->sku,
                'price' => (float) $item->price,
                'originalPrice' => $item->original_price === null
                    ? null
                    : (float) $item->original_price,
                'inStock' => $item->stock_quantity > 0,
                'optionValues' => (object) $item->optionValues->mapWithKeys(
                    fn ($value): array => [$value->option->code => $value->code],
                )->all(),
            ])->values()->all(),
            'defaultSellableItemId' => $displaySellableItem
                ? (string) $displaySellableItem->getKey()
                : null,
        ];
    }
}
