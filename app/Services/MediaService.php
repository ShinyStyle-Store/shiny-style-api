<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Exceptions\MediaOperationException;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaService
{
    /** Store a validated image upload and create its reusable asset record. */
    public function uploadImage(UploadedFile $file, ?User $createdBy = null): MediaAsset
    {
        $image = $this->inspectImage($file);
        $disk = (string) config('media.disk');
        $directory = 'media/images/'.now()->format('Y/m');
        $filename = Str::ulid().'.'.$image['extension'];
        $expectedPath = $directory.'/'.$filename;

        try {
            $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);
        } catch (Throwable) {
            $this->deleteStoredFileQuietly($disk, $expectedPath);

            throw new MediaOperationException('The media file could not be stored.');
        }

        if (! is_string($path) || $path === '') {
            $this->deleteStoredFileQuietly($disk, $expectedPath);

            throw new MediaOperationException('The media file could not be stored.');
        }

        try {
            return DB::transaction(fn (): MediaAsset => MediaAsset::query()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => substr($file->getClientOriginalName(), 0, 255),
                'media_type' => $image['media_type'],
                'mime_type' => $image['mime_type'],
                'extension' => $image['extension'],
                'size_bytes' => $image['size_bytes'],
                'width' => $image['width'],
                'height' => $image['height'],
                'checksum' => $image['checksum'],
                'created_by' => $createdBy?->getKey(),
            ]));
        } catch (Throwable) {
            $this->deleteStoredFileQuietly($disk, $path);

            throw new MediaOperationException('The media asset could not be recorded.');
        }
    }

    /** Validate image bytes before a caller begins its database transaction. */
    public function validateImage(UploadedFile $file, string $errorField = 'file'): void
    {
        $this->inspectImage($file, $errorField);
    }

    /** Attach an existing asset to a mapped model using an application-known role. */
    public function attach(
        MediaAsset $asset,
        Model $entity,
        string $role,
        array $attributes = [],
    ): MediaAttachment {
        if (! in_array($role, MediaRole::values(), true)) {
            throw new \InvalidArgumentException('The media role is not supported.');
        }

        if (! $entity->exists || ! in_array($entity::class, Relation::morphMap(), true)) {
            throw new \InvalidArgumentException('The media entity type is not supported.');
        }

        $allowedAttributes = [
            'locale', 'device', 'alt_ar', 'alt_en', 'caption_ar', 'caption_en', 'sort_order', 'is_primary',
        ];
        if (array_diff(array_keys($attributes), $allowedAttributes) !== []) {
            throw new \InvalidArgumentException('Unsupported media attachment attributes were provided.');
        }

        return DB::transaction(function () use ($asset, $entity, $role, $attributes): MediaAttachment {
            $lockedAsset = MediaAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $entity->mediaAttachments()->create(array_merge($attributes, [
                'media_asset_id' => $lockedAsset->getKey(),
                'role' => $role,
            ]));
        });
    }

    /** Hard-delete the attachment row, then remove its asset only if no references remain. */
    public function detach(MediaAttachment $attachment): bool
    {
        $attachmentId = $attachment->getKey();
        $assetId = $attachment->media_asset_id;

        $detached = DB::transaction(function () use ($attachmentId, $assetId): bool {
            MediaAsset::withTrashed()->whereKey($assetId)->lockForUpdate()->first();
            $lockedAttachment = MediaAttachment::query()->whereKey($attachmentId)->lockForUpdate()->first();

            return $lockedAttachment?->delete() ?? false;
        });

        if ($detached) {
            $this->deleteOrphanedAsset((int) $assetId);
        }

        return $detached;
    }

    public function isOrphaned(MediaAsset|int $asset): bool
    {
        $assetId = $asset instanceof MediaAsset ? $asset->getKey() : $asset;
        $exists = MediaAsset::withTrashed()->whereKey($assetId)->exists();

        return $exists && ! MediaAttachment::query()->where('media_asset_id', $assetId)->exists();
    }

    /**
     * Remove the stored file and database row only when the asset has no attachments.
     * Missing assets are treated as already deleted; attached assets are left untouched.
     */
    public function deleteOrphanedAsset(MediaAsset|int $asset): bool
    {
        $assetId = $asset instanceof MediaAsset ? $asset->getKey() : $asset;
        $diskAndPath = DB::transaction(function () use ($assetId): array|false|null {
            $lockedAsset = MediaAsset::withTrashed()->whereKey($assetId)->lockForUpdate()->first();

            if ($lockedAsset === null) {
                return null;
            }

            if (MediaAttachment::query()->where('media_asset_id', $assetId)->exists()) {
                return false;
            }

            if (! $lockedAsset->trashed()) {
                $lockedAsset->delete();
            }

            return ['disk' => $lockedAsset->disk, 'path' => $lockedAsset->path];
        });

        if ($diskAndPath === null) {
            return true;
        }
        if ($diskAndPath === false) {
            return false;
        }

        return DB::transaction(function () use ($assetId, $diskAndPath): bool {
            $lockedAsset = MediaAsset::withTrashed()->whereKey($assetId)->lockForUpdate()->first();

            if ($lockedAsset === null) {
                return true;
            }
            if (MediaAttachment::query()->where('media_asset_id', $assetId)->exists()) {
                return false;
            }

            try {
                $storage = Storage::disk($diskAndPath['disk']);
                if (! $storage->delete($diskAndPath['path'])) {
                    throw new MediaOperationException('The media file could not be deleted.');
                }
            } catch (MediaOperationException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new MediaOperationException('The media file could not be deleted.');
            }

            $lockedAsset->forceDelete();

            return true;
        });
    }

    /** @return array{media_type: string, mime_type: string, extension: string, size_bytes: int, width: int, height: int, checksum: ?string} */
    private function inspectImage(UploadedFile $file, string $errorField = 'file'): array
    {
        if (! $file->isValid()) {
            $this->invalidImage('The uploaded file is invalid.', $errorField);
        }

        $size = $file->getSize();
        if (! is_int($size) || $size < 1 || $size > (int) config('media.images.max_image_size_bytes')) {
            $this->invalidImage('The image exceeds the allowed file size.', $errorField);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $mediaType = match (true) {
            is_string($mimeType) && str_starts_with($mimeType, 'image/') => 'image',
            is_string($mimeType) && str_starts_with($mimeType, 'video/') => 'video',
            default => 'file',
        };
        $mimeExtensions = (array) config('media.images.mime_extensions', []);
        $extension = is_string($mimeType) ? ($mimeExtensions[$mimeType] ?? null) : null;
        $clientExtension = strtolower($file->getClientOriginalExtension());
        $allowedMimeTypes = (array) config('media.images.mime_types', []);
        $allowedExtensions = (array) config('media.images.extensions', []);

        if (! is_string($extension)
            || $mediaType !== 'image'
            || ! in_array($mimeType, $allowedMimeTypes, true)
            || ! in_array($extension, $allowedExtensions, true)
            || ! in_array($clientExtension, $allowedExtensions, true)) {
            $this->invalidImage('The uploaded image type is not supported.', $errorField);
        }

        $image = @getimagesize($file->getRealPath());
        if (! is_array($image)
            || ! isset($image[0], $image[1], $image['mime'])
            || $image['mime'] !== $mimeType
            || $image[0] < 1
            || $image[1] < 1) {
            $this->invalidImage('The uploaded file is not a supported image.', $errorField);
        }

        $checksum = hash_file('sha256', $file->getRealPath());

        return [
            'media_type' => $mediaType,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $size,
            'width' => (int) $image[0],
            'height' => (int) $image[1],
            'checksum' => is_string($checksum) ? $checksum : null,
        ];
    }

    private function invalidImage(string $message, string $field = 'file'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function deleteStoredFileQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Storage cleanup is best-effort; never expose adapter details or paths.
        }
    }
}
