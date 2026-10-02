<?php

namespace App\Http\Resources;

use App\Models\SellableItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sellableItems = $this->publicSellableItems();
        $sellableItem = $this->displaySellableItem($sellableItems);
        $category = $this->primaryCategory();
        $image = $this->primaryImage($sellableItems);

        return [
            'id' => (string) $this->getKey(),
            'slug' => $this->slug,
            'name' => $this->localizedValue($this->name_ar, $this->name_en),
            'category' => $category ? [
                'slug' => $category->slug,
                'name' => $this->localizedValue($category->name_ar, $category->name_en),
            ] : null,
            ...$this->pricePresentation($sellableItem),
            'badge' => $this->badge,
            'inStock' => $sellableItems->contains(
                fn ($item): bool => $item->availableQuantity() > 0,
            ),
            'image' => $this->mediaUrl($image),
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

    /** @return array{price: ?float, originalPrice: ?float, offerApplied: bool, offerId: ?string, discountPercentage: ?float} */
    protected function pricePresentation(?SellableItem $sellableItem): array
    {
        if ($sellableItem === null) {
            return [
                'price' => null,
                'originalPrice' => null,
                'offerApplied' => false,
                'offerId' => null,
                'discountPercentage' => null,
            ];
        }

        $pricing = $this->pricingFor($sellableItem);
        $hasOffer = ($pricing['offerApplied'] ?? false) === true;

        return [
            'price' => $hasOffer ? (float) $pricing['effectivePrice'] : (float) $sellableItem->price,
            'originalPrice' => $hasOffer
                ? (float) $pricing['basePrice']
                : ($sellableItem->original_price === null ? null : (float) $sellableItem->original_price),
            'offerApplied' => $hasOffer,
            'offerId' => $hasOffer ? (string) $pricing['offerId'] : null,
            'discountPercentage' => $hasOffer ? (float) $pricing['discountPercentage'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function pricingFor(SellableItem $sellableItem): ?array
    {
        $pricing = $this->resource->getAttribute('offerPricing');
        $result = is_array($pricing) ? ($pricing[(int) $sellableItem->getKey()] ?? null) : null;

        return is_array($result) ? $result : null;
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

    protected function primaryImage(Collection $sellableItems)
    {
        return $this->productImages->first(fn ($attachment): bool => $attachment->is_primary);
    }

    protected function orderedAttachments(Collection $attachments): Collection
    {
        return $attachments
            ->sort(fn ($left, $right): int => ($left->sort_order <=> $right->sort_order)
                ?: ($left->getKey() <=> $right->getKey()))
            ->values();
    }

    protected function mediaUrl($attachment): ?string
    {
        $asset = $attachment?->mediaAsset;

        return $asset ? Storage::disk($asset->disk)->url($asset->path) : null;
    }
}
