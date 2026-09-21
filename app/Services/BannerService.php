<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Models\Banner;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BannerService
{
    public function __construct(private readonly MediaService $media) {}

    /** @return Collection<int, Banner> */
    public function publicList(): Collection
    {
        return Banner::query()->visible()->with('bannerImageAttachment.mediaAsset')->ordered()->get()
            ->filter(function (Banner $banner): bool {
                $asset = $banner->bannerImageAttachment?->mediaAsset;
                if (! $asset instanceof MediaAsset) {
                    return false;
                }
                try {
                    return Storage::disk($asset->disk)->exists($asset->path);
                } catch (Throwable) {
                    return false;
                }
            })->values();
    }

    /** @return Collection<int, Banner> */
    public function adminList(): Collection
    {
        return Banner::query()->with('bannerImageAttachment.mediaAsset')->ordered()->get();
    }

    public function create(array $data, UploadedFile $image, ?User $user = null): Banner
    {
        $asset = $this->uploadBannerImage($image, $user);
        try {
            $banner = DB::transaction(function () use ($data, $asset): Banner {
                $banner = Banner::query()->create($data);
                $this->media->attach($asset, $banner, MediaRole::BANNER_IMAGE, ['is_primary' => true]);

                return $banner;
            });
        } catch (Throwable $exception) {
            $this->discard($asset);
            throw $exception;
        }

        return $banner->load('bannerImageAttachment.mediaAsset');
    }

    public function update(Banner $banner, array $data): Banner
    {
        return DB::transaction(function () use ($banner, $data): Banner {
            $locked = Banner::query()->whereKey($banner->getKey())->lockForUpdate()->firstOrFail();
            $locked->fill($data)->save();

            return $locked->load('bannerImageAttachment.mediaAsset');
        });
    }

    public function replaceImage(Banner $banner, UploadedFile $image, ?User $user = null): Banner
    {
        $newAsset = $this->uploadBannerImage($image, $user);
        $oldAssetIds = [];
        try {
            $updated = DB::transaction(function () use ($banner, $newAsset, &$oldAssetIds): Banner {
                $locked = Banner::query()->whereKey($banner->getKey())->lockForUpdate()->firstOrFail();
                $oldAttachments = $locked->mediaAttachments()->lockForUpdate()->get();
                $oldAssetIds = $oldAttachments->pluck('media_asset_id')->map(fn ($id): int => (int) $id)->unique()->all();
                foreach ($oldAttachments as $attachment) {
                    $attachment->delete();
                }
                $this->media->attach($newAsset, $locked, MediaRole::BANNER_IMAGE, ['is_primary' => true]);

                return $locked->load('bannerImageAttachment.mediaAsset');
            });
        } catch (Throwable $exception) {
            $this->discard($newAsset);
            throw $exception;
        }

        $this->cleanup($oldAssetIds);

        return $updated;
    }

    public function delete(Banner $banner): void
    {
        $assetIds = DB::transaction(function () use ($banner): array {
            $locked = Banner::query()->whereKey($banner->getKey())->lockForUpdate()->firstOrFail();
            $attachments = $locked->mediaAttachments()->lockForUpdate()->get();
            $ids = $attachments->pluck('media_asset_id')->map(fn ($id): int => (int) $id)->unique()->all();
            foreach ($attachments as $attachment) {
                $attachment->delete();
            }
            $locked->delete();

            return $ids;
        });
        $this->cleanup($assetIds);
    }

    private function discard(MediaAsset $asset): void
    {
        try {
            $this->media->deleteOrphanedAsset($asset);
        } catch (Throwable) {
            Log::warning('Banner media cleanup failed.');
        }
    }

    private function uploadBannerImage(UploadedFile $image, ?User $user): MediaAsset
    {
        try {
            return $this->media->uploadImage($image, $user);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            if (! array_key_exists('file', $errors)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'image' => $errors['file'],
            ]);
        }
    }

    /** @param list<int> $ids */
    private function cleanup(array $ids): void
    {
        foreach ($ids as $id) {
            try {
                $this->media->deleteOrphanedAsset($id);
            } catch (Throwable) {
                Log::warning('Banner media cleanup failed.');
            }
        }
    }
}
