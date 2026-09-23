<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductReviewImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attachment = $this->mediaAttachment;
        $asset = $attachment?->mediaAsset;

        return [
            'id' => (string) $this->getKey(),
            'image' => $asset === null ? null : [
                'url' => Storage::disk($asset->disk)->url($asset->path),
                'alt' => $this->localizedValue($attachment?->alt_ar, $attachment?->alt_en),
                'width' => $asset->width,
                'height' => $asset->height,
            ],
            'sortOrder' => (int) $this->sort_order,
        ];
    }

    private function localizedValue(?string $arabic, ?string $english): ?string
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : $arabic;
        }

        return $arabic !== null && $arabic !== '' ? $arabic : $english;
    }
}
