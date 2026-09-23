<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\ProductReviewImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminProductReviewImageService
{
    public function __construct(private readonly MediaService $media) {}

    /** @return Collection<int, ProductReviewImage> */
    public function list(Product $product, bool $archived = false): Collection
    {
        $query = ProductReviewImage::query()
            ->where('product_id', $product->getKey())
            ->with('mediaAttachment.mediaAsset')
            ->ordered();

        if ($archived) {
            $query->withTrashed()->whereNotNull('deleted_at');
        }

        return $query->get();
    }

    public function find(Product $product, int $id, bool $withTrashed = false): ProductReviewImage
    {
        $query = ProductReviewImage::query()->where('product_id', $product->getKey());
        if ($withTrashed) {
            $query->withTrashed();
        }

        $review = $query->with('mediaAttachment.mediaAsset')->whereKey($id)->first();
        if ($review === null) {
            throw (new ModelNotFoundException)->setModel(ProductReviewImage::class, [$id]);
        }

        return $review;
    }

    public function upload(Product $product, UploadedFile $file, array $attributes, ?User $user): ProductReviewImage
    {
        $this->validateFiles([$file]);
        $created = [];

        try {
            $review = DB::transaction(function () use ($product, $file, $attributes, $user, &$created): ProductReviewImage {
                $locked = $this->lockProduct($product);
                $this->assertCapacity($locked, 1);
                $review = ProductReviewImage::query()->create([
                    'product_id' => $locked->getKey(),
                    'status' => $attributes['status'] ?? 'draft',
                    'sort_order' => $this->nextOrder($locked),
                ]);
                $attachment = $this->attach($review, $file, $attributes, $user);
                $created[] = $attachment;

                return $review->load('mediaAttachment.mediaAsset');
            });
        } catch (Throwable $exception) {
            $this->cleanup($created);
            throw $exception;
        }

        return $review;
    }

    /** @param list<UploadedFile> $files */
    public function bulk(Product $product, array $files, array $attributes, ?User $user): Collection
    {
        $this->validateFiles($files);
        $created = [];

        try {
            $reviews = DB::transaction(function () use ($product, $files, $attributes, $user, &$created): Collection {
                $locked = $this->lockProduct($product);
                $this->assertCapacity($locked, count($files));
                $order = $this->nextOrder($locked);
                $result = new Collection();

                foreach ($files as $index => $file) {
                    $review = ProductReviewImage::query()->create([
                        'product_id' => $locked->getKey(),
                        'status' => $attributes['status'] ?? 'draft',
                        'sort_order' => $order + $index,
                    ]);
                    $attachment = $this->attach($review, $file, $attributes, $user);
                    $created[] = $attachment;
                    $result->push($review->load('mediaAttachment.mediaAsset'));
                }

                return $result;
            });
        } catch (Throwable $exception) {
            $this->cleanup($created);
            throw $exception;
        }

        return $reviews;
    }

    /** @param array<string, mixed> $data */
    public function update(ProductReviewImage $review, array $data): ProductReviewImage
    {
        return DB::transaction(function () use ($review, $data): ProductReviewImage {
            $locked = ProductReviewImage::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
            $attachment = $this->attachmentForUpdate($locked);
            if (array_key_exists('status', $data)) {
                $locked->status = $data['status'];
                $locked->save();
            }
            unset($data['status']);
            if ($data !== []) {
                $attachment->fill($data)->save();
            }

            return $locked->load('mediaAttachment.mediaAsset');
        });
    }

    /** @param array<string, mixed> $data */
    public function replace(ProductReviewImage $review, UploadedFile $file, array $data, ?User $user): ProductReviewImage
    {
        $this->validateFiles([$file]);
        $created = [];
        $oldAssetId = null;

        try {
            $updated = DB::transaction(function () use ($review, $file, $data, $user, &$created, &$oldAssetId): ProductReviewImage {
                $locked = ProductReviewImage::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
                $old = $this->attachmentForUpdate($locked);
                $oldAssetId = (int) $old->media_asset_id;
                $attachment = $this->attach($locked, $file, $data + [
                    'alt_ar' => $old->alt_ar,
                    'alt_en' => $old->alt_en,
                    'caption_ar' => $old->caption_ar,
                    'caption_en' => $old->caption_en,
                ], $user);
                $created[] = $attachment;
                MediaAttachment::query()->whereKey($old->getKey())->delete();

                foreach (['alt_ar', 'alt_en', 'caption_ar', 'caption_en'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $attachment->{$field} = $data[$field];
                    }
                }
                $attachment->save();

                return $locked->load('mediaAttachment.mediaAsset');
            });
        } catch (Throwable $exception) {
            $this->cleanup($created);
            throw $exception;
        }

        if ($oldAssetId !== null) {
            try {
                $this->media->deleteOrphanedAsset($oldAssetId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $updated;
    }

    /** @param list<array{id:int,sort_order:int}> $items */
    public function reorder(Product $product, array $items): Collection
    {
        return DB::transaction(function () use ($product, $items): Collection {
            $locked = $this->lockProduct($product);
            $reviews = ProductReviewImage::query()->where('product_id', $locked->getKey())
                ->with('mediaAttachment.mediaAsset')->ordered()->lockForUpdate()->get()->keyBy('id');
            if ($reviews->count() !== count($items) || $reviews->keys()->diff(array_column($items, 'id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'The complete current review image set is required.']);
            }

            foreach (collect($items)->sortBy('sort_order')->values() as $index => $item) {
                $reviews[(int) $item['id']]->update(['sort_order' => $index]);
            }

            return $reviews->fresh()->sortBy('sort_order')->values();
        });
    }

    public function delete(ProductReviewImage $review): bool
    {
        return DB::transaction(function () use ($review): bool {
            $locked = ProductReviewImage::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
            $product = Product::query()->whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $deleted = $locked->delete();
            $this->compact($product);

            return $deleted;
        });
    }

    public function restore(Product $product, int $id): ProductReviewImage
    {
        return DB::transaction(function () use ($product, $id): ProductReviewImage {
            $lockedProduct = $this->lockProduct($product);
            $review = ProductReviewImage::withTrashed()->where('product_id', $lockedProduct->getKey())
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $review->trashed()) {
                throw ValidationException::withMessages(['reviewImage' => 'The review image is not archived.']);
            }
            $attachment = MediaAttachment::query()->with('mediaAsset')->where('mediable_type', $review->getMorphClass())
                ->where('mediable_id', $review->getKey())->where('role', MediaRole::CUSTOMER_REVIEW_IMAGE)->first();
            if ($attachment === null || $attachment->mediaAsset === null) {
                throw ValidationException::withMessages(['reviewImage' => 'The archived review image media is no longer available.']);
            }

            $this->assertCapacity($lockedProduct, 1);
            $nextSortOrder = $this->nextOrder($lockedProduct);
            $review->restore();
            $review->forceFill([
                'status' => 'draft',
                'sort_order' => $nextSortOrder,
            ]);
            $review->save();

            return $review->load('mediaAttachment.mediaAsset');
        });
    }

    private function lockProduct(Product $product): Product
    {
        return Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
    }

    private function nextOrder(Product $product): int
    {
        $maxSortOrder = ProductReviewImage::query()
            ->where('product_id', $product->getKey())
            ->max('sort_order');

        return $maxSortOrder === null ? 0 : ((int) $maxSortOrder + 1);
    }

    private function assertCapacity(Product $product, int $additional): void
    {
        $count = ProductReviewImage::query()->where('product_id', $product->getKey())->count();
        $maximum = (int) config('media.images.max_review_images_per_product', 20);
        if ($count + $additional > $maximum) {
            throw ValidationException::withMessages(['image' => "A maximum of {$maximum} review images may be stored for this Product."]);
        }
    }

    /** @param list<UploadedFile> $files */
    private function validateFiles(array $files): void
    {
        $maximum = (int) config('media.images.max_review_images_per_request', 5);
        if (count($files) > $maximum) {
            throw ValidationException::withMessages(['images' => "A maximum of {$maximum} review images may be uploaded per request."]);
        }
        $limit = (int) config('media.images.max_review_image_size_bytes', 5 * 1024 * 1024);
        foreach ($files as $index => $file) {
            $this->media->validateImage($file, count($files) === 1 ? 'image' : "images.{$index}", $limit);
        }
    }

    /** @param array<string,mixed> $attributes */
    private function attach(ProductReviewImage $review, UploadedFile $file, array $attributes, ?User $user): MediaAttachment
    {
        return $this->media->uploadAndAttach($file, $review, MediaRole::CUSTOMER_REVIEW_IMAGE, [
            'alt_ar' => $attributes['alt_ar'] ?? null,
            'alt_en' => $attributes['alt_en'] ?? null,
            'caption_ar' => $attributes['caption_ar'] ?? null,
            'caption_en' => $attributes['caption_en'] ?? null,
            'sort_order' => 0,
            'is_primary' => false,
        ], $user, (int) config('media.images.max_review_image_size_bytes', 5 * 1024 * 1024));
    }

    private function attachmentForUpdate(ProductReviewImage $review): MediaAttachment
    {
        $attachment = MediaAttachment::query()->with('mediaAsset')->where('mediable_type', $review->getMorphClass())
            ->where('mediable_id', $review->getKey())->where('role', MediaRole::CUSTOMER_REVIEW_IMAGE)
            ->lockForUpdate()->first();
        if ($attachment === null || $attachment->mediaAsset === null) {
            throw ValidationException::withMessages(['reviewImage' => 'The review image media is unavailable.']);
        }

        return $attachment;
    }

    private function compact(Product $product): void
    {
        $reviews = ProductReviewImage::query()->where('product_id', $product->getKey())->ordered()->lockForUpdate()->get();
        foreach ($reviews as $index => $review) {
            if ((int) $review->sort_order !== $index) {
                $review->update(['sort_order' => $index]);
            }
        }
    }

    /** @param list<MediaAttachment> $attachments */
    private function cleanup(array $attachments): void
    {
        foreach (array_reverse($attachments) as $attachment) {
            try {
                if (MediaAttachment::query()->whereKey($attachment->getKey())->exists()) {
                    $this->media->detach($attachment);
                }
            } catch (Throwable) {
                // Preserve the original failure; orphan cleanup can be retried.
            }
        }
    }
}
