<?php

namespace App\Http\Resources;

use App\Services\SeoRedirectService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeoRedirectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            ...app(SeoRedirectService::class)->snapshot($this->resource),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
