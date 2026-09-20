<?php

namespace App\Http\Resources;

use App\Services\CategoryCoverPresenter;
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
            'coverImage' => CategoryCoverPresenter::forAdmin($this->resource),
            'status' => $this->status,
            'parent' => $this->category_parent_summary,
            'sortOrder' => $this->sort_order,
            'depth' => $this->hierarchy_depth,
            'hasChildren' => $this->has_children,
            'childrenCount' => $this->children_count,
            'productsCount' => $this->products_count,
            'isEffectivelyVisible' => $this->is_effectively_visible,
            'visibilityReason' => $this->visibility_reason,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            ...($this->archived_at === null ? [] : [
                'archivedAt' => $this->archived_at?->toISOString(),
            ]),
        ];
    }
}
