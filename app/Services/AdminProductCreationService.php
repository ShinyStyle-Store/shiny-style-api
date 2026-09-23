<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\User;
use App\Services\CategoryHierarchyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminProductCreationService
{
    public function __construct(
        private readonly AdminMediaManagementService $media,
        private readonly MediaService $mediaFiles,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param list<int>|null $categoryIds
     * @param list<UploadedFile> $galleryImages
     */
    public function create(
        array $data,
        ?array $categoryIds,
        mixed $primaryCategoryId,
        ?UploadedFile $primaryImage,
        array $galleryImages,
        ?User $createdBy,
    ): Product {
        $this->validateImages($primaryImage, $galleryImages);

        /** @var list<MediaAttachment> $createdAttachments */
        $createdAttachments = [];
        /** @var list<array{id: int, disk: string, path: string}> $createdAssets */
        $createdAssets = [];

        try {
            return DB::transaction(function () use (
                $data,
                $categoryIds,
                $primaryCategoryId,
                $primaryImage,
                $galleryImages,
                $createdBy,
                &$createdAttachments,
                &$createdAssets,
            ): Product {
                $this->assertCategoriesAvailable($categoryIds);
                $product = Product::create($data);
                if ($categoryIds !== null) {
                    $this->syncCategories($product, $categoryIds, $primaryCategoryId);
                }
                $this->assertLifecycle($product);

                $images = [];
                if ($primaryImage !== null) {
                    $images[] = [$primaryImage, true];
                }
                foreach ($galleryImages as $index => $image) {
                    $images[] = [$image, $primaryImage === null && $index === 0];
                }

                foreach ($images as $index => [$image, $makePrimary]) {
                    $attachment = $this->media->upload(
                        $product,
                        $image,
                        'image',
                        ['sort_order' => $index],
                        $makePrimary,
                        $createdBy,
                    );
                    $createdAttachments[] = $attachment;
                    $asset = $attachment->mediaAsset;
                    $createdAssets[] = [
                        'id' => (int) $asset->getKey(),
                        'disk' => (string) $asset->disk,
                        'path' => (string) $asset->path,
                    ];
                }

                return $product;
            });
        } catch (Throwable $exception) {
            $this->cleanup($createdAttachments, $createdAssets);

            throw $exception;
        }
    }

    /** @param list<UploadedFile> $galleryImages */
    private function validateImages(?UploadedFile $primaryImage, array $galleryImages): void
    {
        $maximum = (int) config('media.images.max_product_images_per_create', config('media.images.max_product_images', 10));
        $count = ($primaryImage === null ? 0 : 1) + count($galleryImages);
        if ($count > $maximum) {
            throw ValidationException::withMessages([
                'gallery_images' => "A maximum of {$maximum} Product images may be uploaded.",
            ]);
        }

        if ($primaryImage !== null) {
            $this->mediaFiles->validateImage($primaryImage, 'primary_image');
        }
        foreach ($galleryImages as $index => $image) {
            $this->mediaFiles->validateImage($image, "gallery_images.{$index}");
        }
    }

    private function assertCategoriesAvailable(?array $categoryIds): void
    {
        if ($categoryIds === null) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        $visibleIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();
        $available = Category::query()->whereIn('id', $ids)->whereIn('id', $visibleIds)
            ->whereNull('deleted_at')->where('status', 'active')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($available) !== count($ids)) {
            throw ValidationException::withMessages([
                'category_ids' => 'One or more selected categories are unavailable.',
            ]);
        }
    }

    private function syncCategories(Product $product, array $categoryIds, mixed $primaryCategoryId): void
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds === []) {
            $product->categories()->sync([]);

            return;
        }

        $primary = $primaryCategoryId === null ? $categoryIds[0] : (int) $primaryCategoryId;
        if (! in_array($primary, $categoryIds, true)) {
            throw ValidationException::withMessages([
                'primary_category_id' => 'The primary category must be included in category_ids.',
            ]);
        }

        $product->categories()->sync(collect($categoryIds)->mapWithKeys(
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

    /** @param list<MediaAttachment> $attachments @param list<array{id: int, disk: string, path: string}> $assets */
    private function cleanup(array $attachments, array $assets): void
    {
        foreach (array_reverse($attachments) as $attachment) {
            try {
                if ($attachment->exists && MediaAttachment::query()->whereKey($attachment->getKey())->exists()) {
                    $this->media->delete($attachment->mediable, $attachment);
                }
            } catch (Throwable) {
                // The exact asset paths are cleaned below as a final compensation attempt.
            }
        }

        foreach ($assets as $asset) {
            try {
                if (MediaAsset::withTrashed()->whereKey($asset['id'])->exists()) {
                    $this->mediaFiles->deleteOrphanedAsset($asset['id']);
                } else {
                    Storage::disk($asset['disk'])->delete($asset['path']);
                }
            } catch (Throwable) {
                // Preserve the original request failure; cleanup can be retried by maintenance tooling.
            }
        }
    }
}
