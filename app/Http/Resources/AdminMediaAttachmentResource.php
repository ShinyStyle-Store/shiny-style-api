<?php

namespace App\Http\Resources;

use App\Models\MediaAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin MediaAttachment */
class AdminMediaAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $asset = $this->mediaAsset;

        return [
            'id' => $this->getKey(),
            'asset_public_id' => $asset->public_id,
            'kind' => $asset->media_type,
            'role' => $this->role,
            'url' => Storage::disk($asset->disk)->url($asset->path),
            'original_name' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'width' => $asset->width,
            'height' => $asset->height,
            'duration_seconds' => $asset->duration_seconds,
            'alt_ar' => $this->alt_ar,
            'alt_en' => $this->alt_en,
            'caption_ar' => $this->caption_ar,
            'caption_en' => $this->caption_en,
            'sort_order' => $this->sort_order,
            'is_primary' => $this->is_primary,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
