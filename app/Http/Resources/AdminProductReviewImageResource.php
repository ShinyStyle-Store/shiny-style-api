<?php

namespace App\Http\Resources;

use App\Models\ProductReviewImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ProductReviewImage */
class AdminProductReviewImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attachment = $this->mediaAttachment;
        $asset = $attachment?->mediaAsset;

        return [
            'id' => $this->getKey(),
            'productId' => $this->product_id,
            'status' => $this->status,
            'sortOrder' => (int) $this->sort_order,
            'image' => $asset === null ? null : [
                'url' => Storage::disk($asset->disk)->url($asset->path),
                'altAr' => $attachment?->alt_ar,
                'altEn' => $attachment?->alt_en,
                'width' => $asset->width,
                'height' => $asset->height,
            ],
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
