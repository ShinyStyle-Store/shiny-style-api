<?php

namespace App\Http\Resources;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Banner */
class AdminBannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attachment = $this->getRelation('bannerImageAttachment');
        $asset = $attachment?->getRelation('mediaAsset');

        return [
            'id' => $this->getKey(),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'cta_text_ar' => $this->cta_text_ar,
            'cta_text_en' => $this->cta_text_en,
            'cta_type' => $this->cta_type,
            'cta_target' => $this->cta_target,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'image' => $asset ? [
                'id' => $asset->public_id,
                'url' => Storage::disk($asset->disk)->url($asset->path),
                'mime_type' => $asset->mime_type,
                'width' => $asset->width,
                'height' => $asset->height,
                'size_bytes' => $asset->size_bytes,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
