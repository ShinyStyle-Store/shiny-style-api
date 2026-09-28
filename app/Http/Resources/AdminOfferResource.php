<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Offer */
class AdminOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'discountPercentage' => (string) $this->discount_percentage,
            'startsAt' => $this->starts_at?->toISOString(),
            'endsAt' => $this->ends_at?->toISOString(),
            'isEnabled' => (bool) $this->is_enabled,
            'state' => $this->state(),
            'productIds' => $this->products->modelKeys(),
            'products' => $this->products->map(fn ($product): array => [
                'id' => $product->getKey(),
                'slug' => $product->slug,
                'nameAr' => $product->name_ar,
                'nameEn' => $product->name_en,
                'status' => $product->status,
                'publishedAt' => $product->published_at?->toISOString(),
                'archivedAt' => $product->deleted_at?->toISOString(),
            ])->values()->all(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
