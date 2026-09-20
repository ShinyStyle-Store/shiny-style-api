<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use Illuminate\Support\Facades\Storage;

/** Formats an already-loaded category cover without issuing relationship queries. */
final class CategoryCoverPresenter
{
    public static function forPublic(Category $category): ?array
    {
        $cover = self::loadedCover($category);
        if ($cover === null) {
            return null;
        }

        [$attachment, $asset] = $cover;
        $english = app()->getLocale() === 'en';
        $alt = $english ? $attachment->alt_en : $attachment->alt_ar;
        $fallback = $english
            ? ($category->name_en ?: $category->name_ar)
            : ($category->name_ar ?: $category->name_en);

        return [
            'id' => $asset->public_id,
            'url' => Storage::disk($asset->disk)->url($asset->path),
            'alt' => $alt ?: $fallback,
            'width' => $asset->width,
            'height' => $asset->height,
            'mimeType' => $asset->mime_type,
        ];
    }

    public static function forAdmin(Category $category): ?array
    {
        $cover = self::loadedCover($category);
        if ($cover === null) {
            return null;
        }

        [$attachment, $asset] = $cover;

        return [
            'id' => $asset->public_id,
            'url' => Storage::disk($asset->disk)->url($asset->path),
            'altAr' => $attachment->alt_ar ?: $category->name_ar,
            'altEn' => $attachment->alt_en ?: $category->name_en,
            'width' => $asset->width,
            'height' => $asset->height,
            'mimeType' => $asset->mime_type,
            'sizeBytes' => $asset->size_bytes,
        ];
    }

    /** @return array{MediaAttachment, MediaAsset}|null */
    private static function loadedCover(Category $category): ?array
    {
        if (! $category->relationLoaded('coverImageAttachment')) {
            return null;
        }

        $attachment = $category->getRelation('coverImageAttachment');
        if (! $attachment instanceof MediaAttachment || ! $attachment->relationLoaded('mediaAsset')) {
            return null;
        }

        $asset = $attachment->getRelation('mediaAsset');

        return $asset instanceof MediaAsset ? [$attachment, $asset] : null;
    }
}
