<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductOptionValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'optionId' => $this->product_option_id,
            'code' => $this->code,
            'valueAr' => $this->value_ar,
            'valueEn' => $this->value_en,
            'metadata' => $this->metadata,
            'sortOrder' => (int) $this->sort_order,
            'usedByVariantsCount' => $this->when(isset($this->sellable_items_count), (int) $this->sellable_items_count),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'archivedAt' => $this->deleted_at?->toISOString(),
        ];
    }
}
