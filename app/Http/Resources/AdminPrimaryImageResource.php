<?php

namespace App\Http\Resources;

use App\Models\MediaAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin MediaAttachment */
class AdminPrimaryImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $asset = $this->getRelation('mediaAsset');

        return [
            'id' => $this->getKey(),
            'url' => Storage::disk($asset->disk)->url($asset->path),
            'altAr' => $this->alt_ar,
            'altEn' => $this->alt_en,
            'width' => $asset->width,
            'height' => $asset->height,
        ];
    }
}
