<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'parentId' => $this->parent_id,
            'slug' => $this->slug,
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en,
            'descriptionAr' => $this->description_ar,
            'descriptionEn' => $this->description_en,
            'coverImageUrl' => $this->cover_image_url,
            'status' => $this->status,
            'sortOrder' => $this->sort_order,
            'depth' => $this->hierarchy_depth,
            'childrenCount' => $this->children_count,
            'productsCount' => $this->products_count,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
