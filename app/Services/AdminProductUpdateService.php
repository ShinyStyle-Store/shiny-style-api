<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminProductUpdateService
{
    public function __construct(
        private readonly AdminMediaManagementService $media,
        private readonly MediaService $mediaFiles,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param list<int>|null $categoryIds
     * @param array{primary_image?: UploadedFile|null, gallery_images?: list<UploadedFile>, primary_attachment_id?: int|null, remove_attachment_ids?: list<int>} $mediaCommand
     */
    public function update(
        Product $product,
        array $data,
        bool $hasCategories,
        ?array $categoryIds,
        mixed $primaryCategoryId,
        array $mediaCommand,
        ?User $createdBy,
    ): Product {
        $createdFiles = [];
        $removedAssetIds = [];

        try {
            $updated = DB::transaction(function () use (
                $product,
                $data,
                $hasCategories,
                $categoryIds,
                $primaryCategoryId,
                $mediaCommand,
                $createdBy,
                &$createdFiles,
                &$removedAssetIds,
            ): Product {
                $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                if ($hasCategories) {
                    $this->assertCategoriesAvailable($categoryIds);
                    $this->syncCategories($locked, $categoryIds ?? [], $primaryCategoryId);
                }

                $locked->fill($data);
                $locked->save();
                $this->assertLifecycle($locked);

                if ($mediaCommand !== []) {
                    $this->validateCommand($mediaCommand);

                    $images = $this->lockedProductImages($locked);
                    $removeIds = $this->removeIds($mediaCommand);
                    $this->assertRemovalsBelongToProduct($images, $removeIds);

                    $primaryAttachmentId = $this->primaryAttachmentId($mediaCommand);
                    if ($primaryAttachmentId !== null) {
                        if (in_array($primaryAttachmentId, $removeIds, true)) {
                            throw ValidationException::withMessages([
                                'primary_attachment_id' => 'The primary attachment cannot also be removed.',
                            ]);
                        }
                        if (! $images->has($primaryAttachmentId)) {
                            throw ValidationException::withMessages([
                                'primary_attachment_id' => 'The selected attachment is not an active Product image.',
                            ]);
                        }
                    }

                    $primaryImage = $mediaCommand['primary_image'] ?? null;
                    $galleryImages = $mediaCommand['gallery_images'] ?? [];
                    $this->validateUploads($primaryImage, $galleryImages);

                    $remaining = $images->reject(fn (MediaAttachment $image): bool => in_array((int) $image->getKey(), $removeIds, true));
                    $newAttachments = [];

                    $finalCount = $remaining->count() + ($primaryImage instanceof UploadedFile ? 1 : 0) + count($galleryImages);
                    $maximum = (int) config('media.images.max_product_images_per_create', 10);
                    if ($finalCount > $maximum) {
                        throw ValidationException::withMessages([
                            'gallery_images' => "A maximum of {$maximum} Product images may exist after this update.",
                        ]);
                    }

                    if ($primaryImage instanceof UploadedFile) {
                        $attachment = $this->media->upload($locked, $primaryImage, 'image', ['sort_order' => 0], false, $createdBy);
                        $newAttachments[] = $attachment;
                        $createdFiles[] = $this->fileDescriptor($attachment);
                    }

                    foreach ($galleryImages as $image) {
                        $attachment = $this->media->upload($locked, $image, 'image', ['sort_order' => 0], false, $createdBy);
                        $newAttachments[] = $attachment;
                        $createdFiles[] = $this->fileDescriptor($attachment);
                    }

                    foreach ($images as $id => $attachment) {
                        if (! in_array((int) $id, $removeIds, true)) {
                            continue;
                        }

                        $removedAssetIds[] = (int) $attachment->media_asset_id;
                        $attachment->delete();
                    }

                    $final = $this->orderFinalImages($remaining->values(), $newAttachments, $primaryAttachmentId, $primaryImage instanceof UploadedFile);
                    $this->normalizeImages($final);
                }

                return $locked;
            });
        } catch (Throwable $exception) {
            $this->cleanupCreatedFiles($createdFiles);

            throw $exception;
        }

        foreach (array_unique($removedAssetIds) as $assetId) {
            try {
                $this->mediaFiles->deleteOrphanedAsset((int) $assetId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $updated;
    }

    /** @param array<string, mixed> $command */
    private function validateCommand(array $command): void
    {
        if (($command['primary_image'] ?? null) instanceof UploadedFile && $this->primaryAttachmentId($command) !== null) {
            throw ValidationException::withMessages([
                'primary_image' => 'A new primary image cannot be combined with primary_attachment_id.',
            ]);
        }

        $removeIds = $this->removeIds($command);
        if (count($removeIds) !== count(array_unique($removeIds))) {
            throw ValidationException::withMessages([
                'remove_attachment_ids' => 'Removal attachment IDs must be distinct.',
            ]);
        }
    }

    /** @param list<UploadedFile> $galleryImages */
    private function validateUploads(?UploadedFile $primaryImage, array $galleryImages): void
    {
        if ($primaryImage !== null) {
            $this->mediaFiles->validateImage($primaryImage, 'primary_image');
        }

        foreach ($galleryImages as $index => $image) {
            if (! $image instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    "gallery_images.{$index}" => 'Each gallery image must be a file.',
                ]);
            }
            $this->mediaFiles->validateImage($image, "gallery_images.{$index}");
        }
    }

    /** @return \Illuminate\Support\Collection<int, MediaAttachment> */
    private function lockedProductImages(Product $product)
    {
        return MediaAttachment::query()
            ->where('mediable_type', $product->getMorphClass())
            ->where('mediable_id', $product->getKey())
            ->where('role', MediaRole::PRODUCT_IMAGE)
            ->whereHas('mediaAsset', fn (Builder $asset): Builder => $asset->where('media_type', 'image'))
            ->with('mediaAsset')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MediaAttachment $attachment): int => (int) $attachment->getKey());
    }

    /** @param \Illuminate\Support\Collection<int, MediaAttachment> $images @param list<int> $removeIds */
    private function assertRemovalsBelongToProduct($images, array $removeIds): void
    {
        foreach ($removeIds as $id) {
            if (! $images->has($id)) {
                throw ValidationException::withMessages([
                    'remove_attachment_ids' => 'One or more attachments are not active Product images owned by this Product.',
                ]);
            }
        }
    }

    /** @param \Illuminate\Support\Collection<int, MediaAttachment> $remaining @param list<MediaAttachment> $newAttachments */
    private function orderFinalImages($remaining, array $newAttachments, ?int $primaryAttachmentId, bool $newPrimary): array
    {
        $retained = $remaining->values()->all();

        if ($primaryAttachmentId !== null) {
            $retained = $this->moveFirst($retained, $primaryAttachmentId);
        } elseif (! $newPrimary) {
            $currentPrimary = collect($retained)->first(fn (MediaAttachment $image): bool => $image->is_primary);
            if ($currentPrimary !== null) {
                $retained = $this->moveFirst($retained, (int) $currentPrimary->getKey());
            }
        }

        if ($newPrimary) {
            return array_merge([$newAttachments[0]], $retained, array_slice($newAttachments, 1));
        }

        return array_merge($retained, $newAttachments);
    }

    /** @param list<MediaAttachment> $images @return list<MediaAttachment> */
    private function moveFirst(array $images, int $id): array
    {
        $selected = null;
        $others = [];
        foreach ($images as $image) {
            if ((int) $image->getKey() === $id) {
                $selected = $image;
            } else {
                $others[] = $image;
            }
        }

        return $selected === null ? $images : array_merge([$selected], $others);
    }

    /** @param list<MediaAttachment> $images */
    private function normalizeImages(array $images): void
    {
        if ($images !== []) {
            MediaAttachment::query()->whereIn('id', array_map(
                static fn (MediaAttachment $image): int => (int) $image->getKey(),
                $images,
            ))->update(['is_primary' => false]);
        }

        foreach ($images as $index => $image) {
            $image->sort_order = $index;
            $image->is_primary = $index === 0;
            $image->save();
        }
    }

    /** @param MediaAttachment $attachment @return array{id: int, disk: string, path: string} */
    private function fileDescriptor(MediaAttachment $attachment): array
    {
        $asset = $attachment->getRelation('mediaAsset');

        return [
            'id' => (int) $asset->getKey(),
            'disk' => (string) $asset->disk,
            'path' => (string) $asset->path,
        ];
    }

    /** @param list<array{id: int, disk: string, path: string}> $files */
    private function cleanupCreatedFiles(array $files): void
    {
        foreach (array_reverse($files) as $file) {
            try {
                if (MediaAsset::withTrashed()->whereKey($file['id'])->exists()) {
                    $this->mediaFiles->deleteOrphanedAsset($file['id']);
                } else {
                    Storage::disk($file['disk'])->delete($file['path']);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @param array<string, mixed> $command */
    private function primaryAttachmentId(array $command): ?int
    {
        return array_key_exists('primary_attachment_id', $command) && $command['primary_attachment_id'] !== null
            ? (int) $command['primary_attachment_id']
            : null;
    }

    /** @param array<string, mixed> $command @return list<int> */
    private function removeIds(array $command): array
    {
        return array_values(array_map('intval', $command['remove_attachment_ids'] ?? []));
    }

    private function assertCategoriesAvailable(?array $categoryIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds ?? [])));
        if ($ids === []) {
            return;
        }

        $visibleIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();
        $available = Category::query()->whereIn('id', $ids)->whereIn('id', $visibleIds)
            ->whereNull('deleted_at')->where('status', 'active')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($available) !== count($ids)) {
            throw ValidationException::withMessages(['category_ids' => 'One or more selected categories are unavailable.']);
        }
    }

    private function syncCategories(Product $product, array $categoryIds, mixed $primaryCategoryId): void
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($ids === []) {
            $product->categories()->sync([]);

            return;
        }

        $primary = $primaryCategoryId === null
            ? ($product->categories()->wherePivot('is_primary', true)->whereIn('categories.id', $ids)->value('categories.id') ?? $ids[0])
            : (int) $primaryCategoryId;
        if (! in_array($primary, $ids, true)) {
            throw ValidationException::withMessages(['primary_category_id' => 'The primary category must be included in category_ids.']);
        }

        $product->categories()->sync(collect($ids)->mapWithKeys(
            fn (int $id): array => [$id => ['is_primary' => $id === $primary]],
        )->all());
    }

    private function assertLifecycle(Product $product): void
    {
        if ($product->status !== 'active' && $product->published_at === null) {
            return;
        }

        if (! $product->categories()->where('categories.status', 'active')->exists()) {
            throw ValidationException::withMessages(['status' => 'An active product must have an active category.']);
        }
        if (! $product->sellableItems()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['status' => 'An active product must have an active sellable item.']);
        }
    }
}
