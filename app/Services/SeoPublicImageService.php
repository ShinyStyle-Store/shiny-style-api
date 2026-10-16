<?php

namespace App\Services;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SeoPublicImageService
{
    public function url(?MediaAsset $asset): ?string
    {
        if (! $asset instanceof MediaAsset || $asset->media_type !== 'image' || $asset->trashed()) {
            return null;
        }

        $configuration = config("filesystems.disks.{$asset->disk}", []);
        if (! is_array($configuration)
            || (($configuration['visibility'] ?? null) !== 'public'
                && ($configuration['driver'] ?? null) !== 'cloudinary')) {
            return null;
        }

        try {
            $url = Storage::disk($asset->disk)->url($asset->path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
                ? $url
                : null;
    }
}
