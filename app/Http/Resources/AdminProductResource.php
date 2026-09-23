<?php

namespace App\Http\Resources;

use App\Models\MediaAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summaryLoaded = array_key_exists('options_count', $this->getAttributes());
        $stockTotal = (int) ($this->active_stock_total ?? 0);
        $stockReserved = (int) ($this->active_stock_reserved ?? 0);
        $stockAvailable = (int) ($this->active_stock_available ?? max($stockTotal - $stockReserved, 0));

        return [
            'id' => $this->getKey(),
            'slug' => $this->slug,
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en,
            'descriptionAr' => $this->description_ar,
            'descriptionEn' => $this->description_en,
            'features' => $this->features,
            'specifications' => $this->specifications,
            'badge' => $this->badge,
            'status' => $this->status,
            'isFeatured' => (bool) $this->is_featured,
            'publishedAt' => $this->published_at?->toISOString(),
            'videoUrl' => $this->when(
                $request->route()?->getActionMethod() !== 'index',
                $this->video_url,
            ),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category): array => [
                'id' => $category->getKey(),
                'slug' => $category->slug,
                'nameAr' => $category->name_ar,
                'nameEn' => $category->name_en,
                'isPrimary' => (bool) $category->pivot->is_primary,
            ])->values()),
            'categoryIds' => $this->whenLoaded('categories', fn () => $this->categories->modelKeys()),
            'primaryCategoryId' => $this->whenLoaded('categories', fn () => $this->categories->firstWhere('pivot.is_primary', true)?->getKey()),
            'variantsCount' => $this->when(isset($this->sellable_items_count), (int) $this->sellable_items_count),
            'activeVariantsCount' => $this->when(isset($this->active_sellable_items_count), (int) $this->active_sellable_items_count),
            'hasOptions' => $this->when($summaryLoaded, (int) $this->options_count > 0),
            'optionsCount' => $this->when($summaryLoaded, (int) $this->options_count),
            'priceRange' => $this->when(array_key_exists('active_min_price', $this->getAttributes()), function (): ?array {
                $min = $this->formatMoney($this->active_min_price);
                $max = $this->formatMoney($this->active_max_price);

                return $min === null || $max === null ? null : ['min' => $min, 'max' => $max];
            }),
            'stock' => $this->when(array_key_exists('active_stock_total', $this->getAttributes()), [
                'total' => $stockTotal,
                'reserved' => $stockReserved,
                'available' => $stockAvailable,
            ]),
            'inStock' => $this->when(array_key_exists('active_stock_total', $this->getAttributes()), $stockAvailable > 0),
            'primaryImage' => $this->when(
                $this->relationLoaded('primaryProductImage'),
                function () use ($request): ?array {
                    $image = $this->getRelation('primaryProductImage');

                    if (! $image instanceof MediaAttachment || ! $image->relationLoaded('mediaAsset')) {
                        return null;
                    }

                    if ($image->getRelation('mediaAsset') === null) {
                        return null;
                    }

                    return (new AdminPrimaryImageResource($image))->resolve($request);
                },
            ),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'archivedAt' => $this->deleted_at?->toISOString(),
        ];
    }

    private function formatMoney(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ($negative ? '-' : '').($whole === '' ? '0' : $whole).'.'.$fraction;
    }
}
