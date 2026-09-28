<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminShippingAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'type' => $this->type,
            'parentId' => $this->parent_id,
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en,
            'shippingFee' => (string) $this->shipping_fee,
            'isActive' => (bool) $this->is_active,
            'isSelectable' => (bool) $this->is_selectable,
            'sortOrder' => $this->sort_order,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
