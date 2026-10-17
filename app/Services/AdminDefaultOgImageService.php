<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminDefaultOgImageService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly SeoPublicImageService $publicImages,
        private readonly SeoSettingsService $settings,
    ) {}

    /** @return array{id: int, url: string} */
    public function upload(UploadedFile $image, User $actor): array
    {
        $asset = $this->media->uploadImage($image, $actor, 'image');

        try {
            $url = $this->publicImages->url($asset);
            if ($url === null) {
                throw ValidationException::withMessages([
                    'image' => 'The uploaded image is not publicly available.',
                ]);
            }

            $this->settings->update([[
                'key' => 'default_og_image_id',
                'locale' => '',
                'value' => (int) $asset->getKey(),
            ]], $actor);

            return [
                'id' => (int) $asset->getKey(),
                'url' => $url,
            ];
        } catch (Throwable $exception) {
            $this->discardUnreferencedAsset($asset);

            throw $exception;
        }
    }

    private function discardUnreferencedAsset(MediaAsset $asset): void
    {
        try {
            $this->media->deleteOrphanedAsset($asset);
        } catch (Throwable $exception) {
            Log::warning('Default SEO image cleanup failed.', [
                'media_asset_id' => $asset->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
