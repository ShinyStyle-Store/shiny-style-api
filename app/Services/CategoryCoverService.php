<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Coordinates category cover attachment changes around category transactions. */
final class CategoryCoverService
{
    public function __construct(private readonly MediaService $media) {}

    public function upload(?UploadedFile $image, ?User $user): ?MediaAsset
    {
        if ($image === null) {
            return null;
        }

        $this->media->validateImage($image, 'cover_image');

        return $this->media->uploadImage($image, $user);
    }

    /**
     * Must be called within the transaction that already holds a lock on Category.
     * Returns former asset IDs for cleanup after that transaction commits.
     *
     * @return list<int>
     */
    public function changeInTransaction(Category $category, ?MediaAsset $newAsset, bool $remove): array
    {
        $oldAttachments = $category->mediaAttachments()
            ->where('role', MediaRole::CATEGORY_COVER)
            ->lockForUpdate()
            ->get();

        if ($newAsset !== null) {
            $this->media->attach($newAsset, $category, MediaRole::CATEGORY_COVER);
        }

        if ($newAsset === null && ! $remove) {
            return [];
        }

        $assetIds = $oldAttachments->pluck('media_asset_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        foreach ($oldAttachments as $attachment) {
            $attachment->delete();
        }

        return $assetIds;
    }

    public function discardNewAsset(?MediaAsset $asset): void
    {
        if ($asset !== null) {
            $this->cleanupAsset((int) $asset->getKey());
        }
    }

    /** @param list<int> $assetIds */
    public function cleanupFormerAssets(array $assetIds): void
    {
        foreach ($assetIds as $assetId) {
            $this->cleanupAsset($assetId);
        }
    }

    private function cleanupAsset(int $assetId): void
    {
        try {
            $this->media->deleteOrphanedAsset($assetId);
        } catch (Throwable) {
            Log::warning('Category cover media cleanup failed.');
        }
    }
}
