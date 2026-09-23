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
            'assetPublicId' => $asset->public_id,
            'kind' => $asset->media_type,
            'role' => $this->role,
            'url' => Storage::disk($asset->disk)->url($asset->path),
            'originalName' => $asset->original_name,
            'mimeType' => $asset->mime_type,
            'sizeBytes' => $asset->size_bytes,
            'width' => $asset->width,
            'height' => $asset->height,
            'durationSeconds' => $asset->duration_seconds,
            'altAr' => $this->alt_ar,
            'altEn' => $this->alt_en,
            'captionAr' => $this->caption_ar,
            'captionEn' => $this->caption_en,
            'sortOrder' => $this->sort_order,
            'isPrimary' => $this->is_primary,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
