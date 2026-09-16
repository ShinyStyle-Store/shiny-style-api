<?php

namespace App\Http\Resources;

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
            'parentId' => $this->parent_id,
            'sortOrder' => $this->sort_order,
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
