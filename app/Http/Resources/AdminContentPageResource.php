<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminContentPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->exists ? $this->getKey() : null,
            'slug' => $this->slug,
            'titleAr' => $this->title_ar,
            'titleEn' => $this->title_en,
            'bodyAr' => $this->body_ar,
            'bodyEn' => $this->body_en,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
