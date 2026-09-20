<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArchivedCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'status' => $this->status,
            'parent_id' => $this->parent_id,
            'parent' => $this->category_parent_summary === null ? null : [
                'id' => $this->category_parent_summary['id'],
                'slug' => $this->category_parent_summary['slug'],
                'name_ar' => $this->category_parent_summary['nameAr'],
                'name_en' => $this->category_parent_summary['nameEn'],
                'status' => $this->category_parent_summary['status'],
                'is_archived' => $this->category_parent_summary['archived'],
            ],
            'depth' => $this->hierarchy_depth,
            'sort_order' => $this->sort_order,
            'children_count' => $this->children_count,
            'products_count' => $this->products_count,
            'is_effectively_visible' => $this->is_effectively_visible,
            'visibility_reason' => $this->visibility_reason,
            'archived_at' => $this->deleted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
