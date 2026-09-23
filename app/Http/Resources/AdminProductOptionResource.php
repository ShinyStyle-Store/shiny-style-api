<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'productId' => $this->product_id,
            'code' => $this->code,
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en,
            'sortOrder' => (int) $this->sort_order,
            'values' => $this->whenLoaded('values', fn () => AdminProductOptionValueResource::collection($this->values)),
            'valuesCount' => $this->when(isset($this->values_count), (int) $this->values_count),
            'usedByVariantsCount' => $this->when(isset($this->used_by_variants_count), (int) $this->used_by_variants_count),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'archivedAt' => $this->deleted_at?->toISOString(),
        ];
    }
}
