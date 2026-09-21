<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Exceptions\ProductMediaMigrationFailure;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\SellableItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LengthException;
use RuntimeException;
use Throwable;

final class ProductMediaMigrationService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly LegacyCloudinaryUrlPolicy $urlPolicy,
    ) {}

    /** @return list<array{legacy_id: int, reason: string}> */
    public function primaryConflicts(): array
    {
        $groups = [];

        ProductMedia::withTrashed()->orderBy('id')->chunkById(500, function ($records) use (&$groups): void {
            foreach ($records as $record) {
                if ($record->trashed() || ! $record->is_primary) {
                    continue;
                }

                $context = $this->ownerContext($record);
                if ($context['status'] !== 'ready') {
                    continue;
                }

                $key = $context['owner']->getMorphClass().':'.$context['owner']->getKey().':'.$context['role'];
                $groups[$key] ??= [];
                $groups[$key][] = (int) $record->getKey();
            }
        });

        $conflicts = [];
        foreach ($groups as $ownerRole => $legacyIds) {
            if (count($legacyIds) > 1) {
                foreach ($legacyIds as $legacyId) {
                    $conflicts[] = ['legacy_id' => $legacyId, 'reason' => 'duplicate legacy primary'];
                }

                continue;
            }

            [$morphType, $ownerId, $role] = explode(':', $ownerRole, 3);
            $legacyId = $legacyIds[0];
            $mappedAttachmentId = DB::table('legacy_product_media_migrations')
                ->where('legacy_product_media_id', $legacyId)
                ->where('status', 'migrated')
                ->value('media_attachment_id');

            $existingPrimary = MediaAttachment::query()
                ->where('mediable_type', $morphType)
                ->where('mediable_id', $ownerId)
                ->where('role', $role)
                ->where('is_primary', true)
                ->when($mappedAttachmentId, fn ($query) => $query->where('id', '<>', $mappedAttachmentId))
                ->exists();

            if ($existingPrimary) {
                $conflicts[] = ['legacy_id' => $legacyId, 'reason' => 'existing unified primary'];
            }
        }

        return $conflicts;
    }

    /** @return array{status: string, reason: ?string} */
    public function inspect(ProductMedia $record): array
    {
        if ($record->trashed()) {
            return ['status' => 'skipped', 'reason' => 'legacy record is soft-deleted'];
        }

        $mapping = DB::table('legacy_product_media_migrations')
            ->where('legacy_product_media_id', $record->getKey())
            ->first();
        if ($mapping?->status === 'migrated') {
            try {
                $this->verifyMapping($record, $mapping);

                return ['status' => 'already_migrated', 'reason' => null];
            } catch (Throwable) {
                return ['status' => 'failed', 'reason' => 'existing migration mapping failed verification'];
            }
        }

        $context = $this->ownerContext($record);
        if ($context['status'] !== 'ready') {
            return ['status' => $context['status'], 'reason' => $context['reason']];
        }

        if (strtolower((string) $record->provider) !== 'cloudinary') {
            return ['status' => 'failed', 'reason' => 'unsupported legacy provider'];
        }
        if (! in_array($record->type, ['image', 'video'], true)) {
            return ['status' => 'failed', 'reason' => 'unsupported legacy media type'];
        }
        if ($record->sort_order < 0) {
            return ['status' => 'failed', 'reason' => 'legacy sort order is invalid'];
        }

        try {
            $this->urlPolicy->validate((string) $record->secure_url);
        } catch (InvalidArgumentException $exception) {
            return ['status' => 'failed', 'reason' => $exception->getMessage()];
        } catch (Throwable) {
            return ['status' => 'failed', 'reason' => 'legacy media URL could not be validated'];
        }

        return ['status' => 'candidate', 'reason' => null];
    }

    /** @return array{status: string, reason: ?string} */
    public function migrate(ProductMedia $record): array
    {
        $inspection = $this->inspect($record);
        if ($inspection['status'] !== 'candidate') {
            $hasCompletedMapping = DB::table('legacy_product_media_migrations')
                ->where('legacy_product_media_id', $record->getKey())
                ->where('status', 'migrated')
                ->exists();
            if ($inspection['status'] === 'failed' && ! $hasCompletedMapping) {
                $this->saveFailure($record, (string) $inspection['reason']);
            }

            return $inspection;
        }

        $this->savePending($record);
        $context = $this->ownerContext($record);
        $owner = $context['owner'];
        $role = $context['role'];
        $mediaType = $record->type;
        $maxBytes = $mediaType === 'image'
            ? (int) config('media.images.max_image_size_bytes')
            : (int) config('media.videos.max_video_size_bytes');
        $tempPath = null;
        $newAsset = null;

        try {
            $reusableAsset = $this->findReusableAsset($record, $mediaType);
            if ($reusableAsset !== null) {
                $attachment = DB::transaction(function () use ($record, $owner, $role, $reusableAsset): MediaAttachment {
                    $this->assertNoPrimaryConflict($record, $owner, $role);
                    $attachment = $this->media->attach($reusableAsset, $owner, $role, $this->attachmentAttributes($record));
                    $this->saveSuccess($record, $reusableAsset, $attachment);
                    $this->verifyMapping($record, $this->mapping($record));

                    return $attachment;
                });

                return ['status' => 'migrated', 'reason' => null];
            }

            [$upload, $tempPath] = $this->download($record, $maxBytes);
            DB::transaction(function () use ($record, $owner, $role, $upload, &$newAsset): void {
                $this->assertNoPrimaryConflict($record, $owner, $role);
                $attachment = $this->media->uploadAndAttach(
                    $upload,
                    $owner,
                    $role,
                    $this->attachmentAttributes($record),
                );
                $newAsset = $attachment->mediaAsset;
                $this->saveSuccess($record, $newAsset, $attachment);
                $this->verifyMapping($record, $this->mapping($record));
            });

            return ['status' => 'migrated', 'reason' => null];
        } catch (Throwable $exception) {
            if ($newAsset instanceof MediaAsset) {
                try {
                    Storage::disk($newAsset->disk)->delete($newAsset->path);
                } catch (Throwable) {
                    // Preserve the migration failure; storage cleanup can be retried operationally.
                }
            }

            $reason = $this->safeFailureReason($exception);
            $this->saveFailure($record, $reason);

            return ['status' => 'failed', 'reason' => $reason];
        } finally {
            if (is_string($tempPath) && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function assertStorageReady(): ?string
    {
        $disk = (string) config('media.disk');
        if ($disk === '') {
            return 'configured media disk is missing';
        }

        if (app()->environment('production')
            && config("filesystems.disks.{$disk}.driver") === 'local'
            && ! in_array($disk, (array) config('media.legacy_migration.durable_disks', []), true)) {
            return 'production local media disk is not declared durable';
        }

        $path = 'media-migration-check/'.bin2hex(random_bytes(12));
        $storage = null;
        $written = false;
        try {
            $storage = Storage::disk($disk);
            if (! $storage->put($path, 'media migration write check')) {
                return 'configured media disk is not writable';
            }
            $written = true;
        } catch (Throwable) {
            return 'configured media disk is not writable';
        } finally {
            if ($written && $storage !== null) {
                try {
                    $storage->delete($path);
                } catch (Throwable) {
                    // The guard file is harmless and can be removed operationally.
                }
            }
        }

        return null;
    }

    private function ownerContext(ProductMedia $record): array
    {
        if ($record->sellable_item_id !== null) {
            $owner = SellableItem::withTrashed()->find($record->sellable_item_id);
            if ($owner === null) {
                return ['status' => 'failed', 'reason' => 'SellableItem owner is missing'];
            }
            if ($owner->trashed()) {
                return ['status' => 'skipped', 'reason' => 'SellableItem owner is soft-deleted'];
            }
            $ownerType = SellableItem::class;
        } else {
            $owner = Product::withTrashed()->find($record->product_id);
            if ($owner === null) {
                return ['status' => 'failed', 'reason' => 'Product owner is missing'];
            }
            if ($owner->trashed()) {
                return ['status' => 'skipped', 'reason' => 'Product owner is soft-deleted'];
            }
            $ownerType = Product::class;
        }

        $role = match (true) {
            $ownerType === Product::class && $record->type === 'image' => MediaRole::PRODUCT_IMAGE,
            $ownerType === Product::class && $record->type === 'video' => MediaRole::PRODUCT_VIDEO,
            $ownerType === SellableItem::class && $record->type === 'image' => MediaRole::VARIANT_IMAGE,
            $ownerType === SellableItem::class && $record->type === 'video' => MediaRole::VARIANT_VIDEO,
            default => null,
        };
        if ($role === null) {
            return ['status' => 'failed', 'reason' => 'legacy media type is unsupported'];
        }

        return ['status' => 'ready', 'reason' => null, 'owner' => $owner, 'role' => $role];
    }

    private function attachmentAttributes(ProductMedia $record): array
    {
        return [
            'alt_ar' => $record->alt_text_ar,
            'alt_en' => $record->alt_text_en,
            'sort_order' => $record->sort_order,
            'is_primary' => (bool) $record->is_primary,
        ];
    }

    private function savePending(ProductMedia $record): void
    {
        $values = [
            'legacy_provider' => (string) $record->provider,
            'legacy_public_id' => $record->public_id,
            'legacy_secure_url' => (string) $record->secure_url,
            'legacy_source_hash' => $this->sourceHash($record),
            'status' => 'pending',
            'failure_reason' => null,
            'media_asset_id' => null,
            'media_attachment_id' => null,
            'updated_at' => now(),
        ];
        DB::table('legacy_product_media_migrations')->updateOrInsert(
            ['legacy_product_media_id' => $record->getKey()],
            [...$values, 'created_at' => now()],
        );
    }

    private function saveFailure(ProductMedia $record, string $reason): void
    {
        DB::table('legacy_product_media_migrations')->updateOrInsert(
            ['legacy_product_media_id' => $record->getKey()],
            [
                'legacy_provider' => (string) $record->provider,
                'legacy_public_id' => $record->public_id,
                'legacy_secure_url' => (string) $record->secure_url,
                'legacy_source_hash' => $this->sourceHash($record),
                'status' => 'failed',
                'failure_reason' => $reason,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function saveSuccess(ProductMedia $record, MediaAsset $asset, MediaAttachment $attachment): void
    {
        $updated = DB::table('legacy_product_media_migrations')
            ->where('legacy_product_media_id', $record->getKey())
            ->update([
                'media_asset_id' => $asset->getKey(),
                'media_attachment_id' => $attachment->getKey(),
                'status' => 'migrated',
                'failure_reason' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Migration tracking row could not be completed.');
        }
    }

    private function mapping(ProductMedia $record): object
    {
        $mapping = DB::table('legacy_product_media_migrations')
            ->where('legacy_product_media_id', $record->getKey())
            ->first();
        if ($mapping === null) {
            throw new RuntimeException('Migration mapping is missing.');
        }

        return $mapping;
    }

    private function verifyMapping(ProductMedia $record, object $mapping): void
    {
        if ($mapping->status !== 'migrated' || $mapping->media_asset_id === null || $mapping->media_attachment_id === null) {
            throw new RuntimeException('Migration mapping is incomplete.');
        }

        $context = $this->ownerContext($record);
        if ($context['status'] !== 'ready') {
            throw new RuntimeException('Migration owner is unavailable.');
        }
        $asset = MediaAsset::query()->find($mapping->media_asset_id);
        $attachment = MediaAttachment::query()->find($mapping->media_attachment_id);
        if ($asset === null || $attachment === null
            || $attachment->media_asset_id !== $asset->getKey()
            || $attachment->mediable_type !== $context['owner']->getMorphClass()
            || (string) $attachment->mediable_id !== (string) $context['owner']->getKey()
            || $attachment->role !== $context['role']
            || $asset->media_type !== $record->type
            || $attachment->alt_ar !== $record->alt_text_ar
            || $attachment->alt_en !== $record->alt_text_en
            || $attachment->sort_order !== $record->sort_order
            || $attachment->is_primary !== (bool) $record->is_primary
            || ! is_string($asset->checksum)
            || $asset->checksum === '') {
            throw new RuntimeException('Migrated media mapping does not match the legacy record.');
        }

        $storage = Storage::disk($asset->disk);
        if (! $storage->exists($asset->path)) {
            throw new RuntimeException('Migrated media file is missing.');
        }
        $url = $storage->url($asset->path);
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Migrated media URL cannot be generated.');
        }
    }

    private function findReusableAsset(ProductMedia $record, string $mediaType): ?MediaAsset
    {
        $mappings = DB::table('legacy_product_media_migrations')
            ->where('legacy_provider', $record->provider)
            ->where('legacy_source_hash', $this->sourceHash($record))
            ->where('legacy_secure_url', $record->secure_url)
            ->where('status', 'migrated')
            ->whereNotNull('media_asset_id')
            ->where('legacy_product_media_id', '<>', $record->getKey())
            ->orderBy('legacy_product_media_id')
            ->get(['media_asset_id']);

        foreach ($mappings as $mapping) {
            $asset = MediaAsset::query()->find($mapping->media_asset_id);
            if ($asset !== null
                && $asset->media_type === $mediaType
                && Storage::disk($asset->disk)->exists($asset->path)) {
                return $asset;
            }
        }

        return null;
    }

    private function assertNoPrimaryConflict(ProductMedia $record, Model $owner, string $role): void
    {
        if (! $record->is_primary) {
            return;
        }

        if (MediaAttachment::query()
            ->where('mediable_type', $owner->getMorphClass())
            ->where('mediable_id', $owner->getKey())
            ->where('role', $role)
            ->where('is_primary', true)
            ->exists()) {
            throw new ProductMediaMigrationFailure('primary media conflict');
        }
    }

    /** @return array{UploadedFile, string} */
    private function download(ProductMedia $record, int $maxBytes): array
    {
        $validatedUrl = $this->urlPolicy->validate((string) $record->secure_url);
        $tempPath = tempnam(sys_get_temp_dir(), 'legacy-media-');
        if (! is_string($tempPath)) {
            throw new RuntimeException('temporary download file could not be created');
        }

        try {
            $options = [
                'allow_redirects' => false,
                'connect_timeout' => (int) config('media.legacy_migration.connect_timeout_seconds', 5),
                'timeout' => (int) config('media.legacy_migration.timeout_seconds', 45),
                'read_timeout' => (int) config('media.legacy_migration.timeout_seconds', 45),
                'verify' => true,
                'decode_content' => false,
                'stream' => true,
                'on_headers' => static function ($response) use ($maxBytes): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                        throw new LengthException('download exceeds configured size limit');
                    }
                },
            ];
            if (! defined('CURLOPT_RESOLVE')) {
                throw new RuntimeException('secure DNS pinning is unavailable');
            }
            $address = $validatedUrl['addresses'][0];
            $resolvedAddress = str_contains($address, ':') ? '['.$address.']' : $address;
            $options['curl'] = [constant('CURLOPT_RESOLVE') => [
                $validatedUrl['host'].':443:'.$resolvedAddress,
            ]];

            $response = Http::withHeaders(['Accept-Encoding' => 'identity'])
                ->withOptions($options)
                ->get((string) $record->secure_url);

            if ($response->status() >= 300 && $response->status() < 400) {
                throw new ProductMediaMigrationFailure('redirect rejected');
            }
            if (! $response->successful()) {
                throw new ProductMediaMigrationFailure('unsuccessful response');
            }

            $body = $response->toPsrResponse()->getBody();
            $output = fopen($tempPath, 'wb');
            if (! is_resource($output)) {
                throw new RuntimeException('temporary download file could not be opened');
            }
            try {
                $downloadedBytes = 0;
                while (! $body->eof()) {
                    try {
                        $chunk = $body->read(8192);
                    } catch (Throwable) {
                        throw new ProductMediaMigrationFailure('timed out or failed');
                    }
                    if ($chunk === '') {
                        continue;
                    }

                    $downloadedBytes += strlen($chunk);
                    if ($downloadedBytes > $maxBytes) {
                        throw new LengthException('download exceeds configured size limit');
                    }
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('temporary download file could not be written');
                    }
                }
            } finally {
                fclose($output);
            }

            $size = filesize($tempPath);
            if (! is_int($size) || $size < 1 || $size > $maxBytes) {
                throw new LengthException('download is empty or exceeds configured size limit');
            }

            $upload = new UploadedFile(
                $tempPath,
                'legacy-product-media-'.$record->getKey(),
                null,
                UPLOAD_ERR_OK,
                true,
            );

            return [$upload, $tempPath];
        } catch (Throwable $exception) {
            @unlink($tempPath);
            throw $exception;
        }
    }

    private function safeFailureReason(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ProductMediaMigrationFailure => $exception->safeReason,
            $exception instanceof LengthException => 'download exceeds configured size limit',
            $exception instanceof ConnectionException => 'timed out or failed',
            $exception instanceof InvalidArgumentException => 'media migration validation failed',
            default => 'media migration failed validation or storage',
        };
    }

    private function sourceHash(ProductMedia $record): string
    {
        return hash('sha256', (string) $record->provider."\0".(string) $record->secure_url);
    }
}
