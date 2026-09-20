<?php

namespace App\Http\Resources;

use App\Services\CategoryCoverPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'slug' => $this->slug,
            'name' => $this->localizedValue($this->name_ar, $this->name_en),
            'description' => $this->localizedValue($this->description_ar, $this->description_en),
            'coverImage' => CategoryCoverPresenter::forPublic($this->resource),
            'parentId' => $this->parent_id,
            'parent' => $this->category_parent_summary,
            'depth' => $this->hierarchy_depth,
            'sortOrder' => $this->sort_order,
            'hasChildren' => $this->has_children,
            'childrenCount' => $this->children_count,
            ...($this->category_detail ? [
                'breadcrumbs' => collect($this->category_breadcrumbs)->map(fn ($category): array => [
                    'id' => $category->getKey(),
                    'name' => $this->localizedValue($category->name_ar, $category->name_en),
                    'slug' => $category->slug,
                ])->values()->all(),
                'children' => collect($this->category_detail_children)->map(fn ($category): array => [
                    'id' => $category->getKey(),
                    'name' => $this->localizedValue($category->name_ar, $category->name_en),
                    'slug' => $category->slug,
                    'depth' => $category->hierarchy_depth,
                    'sortOrder' => $category->sort_order,
                    'hasChildren' => $category->has_children,
                    'childrenCount' => $category->children_count,
                ])->values()->all(),
            ] : []),
        ];
    }

    private function localizedValue(?string $arabic, ?string $english): ?string
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== '' ? $english : null);
    }
}
