<?php

namespace App\Http\Resources;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Banner */
class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attachment = $this->getRelation('bannerImageAttachment');
        $asset = $attachment?->getRelation('mediaAsset');
        $title = $this->localized($this->title_ar, $this->title_en);
        $text = $this->localized($this->cta_text_ar, $this->cta_text_en);

        return [
            'id' => $this->getKey(),
            'title' => $title,
            'description' => $this->localized($this->description_ar, $this->description_en),
            'cta' => $text !== null ? [
                'text' => $text,
                'type' => $this->cta_type,
                'target' => $this->cta_target,
            ] : null,
            'image' => $asset ? [
                'url' => Storage::disk($asset->disk)->url($asset->path),
                'alt' => $title,
            ] : null,
        ];
    }

    private function localized(?string $arabic, ?string $english): ?string
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== null && $arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== null && $english !== '' ? $english : null);
    }
}
