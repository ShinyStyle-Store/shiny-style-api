<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminMediaManagementService
{
    public function __construct(private readonly MediaService $media) {}

    /** @return Collection<int, MediaAttachment> */
    public function listing(Product|SellableItem $owner): Collection
    {
        return $owner->mediaAttachments()
            ->with('mediaAsset')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findAttachment(Product|SellableItem $owner, int $attachmentId): MediaAttachment
    {
        return $owner->mediaAttachments()
            ->with('mediaAsset')
            ->whereKey($attachmentId)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $metadata */
    public function upload(
        Product|SellableItem $owner,
        UploadedFile $file,
        string $kind,
        array $metadata,
        bool $makePrimary,
        ?User $createdBy,
    ): MediaAttachment {
        $role = $this->roleFor($owner, $kind);
        $metadata['sort_order'] ??= 0;
        $metadata['is_primary'] = false;

        $attachment = $this->media->uploadAndAttach($file, $owner, $role, $metadata, $createdBy);

        if (! $makePrimary) {
            return $attachment->load('mediaAsset');
        }

        try {
            return $this->setPrimary($owner, $attachment);
        } catch (Throwable $exception) {
            try {
                $this->media->detach($attachment);
            } catch (Throwable) {
                // Keep the original failure; orphan cleanup can be retried safely.
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $metadata */
    public function update(Product|SellableItem $owner, MediaAttachment $attachment, array $metadata): MediaAttachment
    {
        $this->assertOwned($owner, $attachment);

        return DB::transaction(function () use ($owner, $attachment, $metadata): MediaAttachment {
            $locked = $owner->mediaAttachments()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->fill($metadata)->save();

            return $locked->load('mediaAsset');
        });
    }

    public function setPrimary(Product|SellableItem $owner, MediaAttachment $attachment): MediaAttachment
    {
        $this->assertOwned($owner, $attachment);

        try {
            return DB::transaction(function () use ($owner, $attachment): MediaAttachment {
                $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

                $locked = $owner->mediaAttachments()
                    ->whereKey($attachment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $ownerAttachments = MediaAttachment::query()
                    ->where('mediable_type', $owner->getMorphClass())
                    ->where('mediable_id', $owner->getKey())
                    ->where('role', $locked->role);
                $primaries = (clone $ownerAttachments)
                    ->where('is_primary', true)
                    ->lockForUpdate()
                    ->get();

                if ($primaries->contains(fn (MediaAttachment $primary): bool => $primary->is($locked))) {
                    return $locked->load('mediaAsset');
                }

                $ownerAttachments
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                $locked->is_primary = true;
                $locked->save();

                return $locked->load('mediaAsset');
            });
        } catch (QueryException $exception) {
            if ($this->isPrimaryIndexViolation($exception)) {
                throw ValidationException::withMessages([
                    'attachment' => 'A primary media attachment already exists for this role.',
                ]);
            }

            throw $exception;
        }
    }

    public function delete(Product|SellableItem $owner, MediaAttachment $attachment): bool
    {
        $this->assertOwned($owner, $attachment);

        return $this->media->detach($attachment);
    }

    private function assertOwned(Product|SellableItem $owner, MediaAttachment $attachment): void
    {
        if ((string) $attachment->mediable_id !== (string) $owner->getKey()
            || $attachment->mediable_type !== $owner->getMorphClass()
            || ! MediaRole::supports($owner::class, $attachment->role)
            || $attachment->mediaAsset === null) {
            throw (new ModelNotFoundException)->setModel(
                MediaAttachment::class,
                [$attachment->getKey()],
            );
        }
    }

    private function roleFor(Product|SellableItem $owner, string $kind): string
    {
        $role = match (true) {
            $owner instanceof Product && $kind === 'image' => MediaRole::PRODUCT_IMAGE,
            $owner instanceof Product && $kind === 'video' => MediaRole::PRODUCT_VIDEO,
            $owner instanceof SellableItem && $kind === 'image' => MediaRole::VARIANT_IMAGE,
            $owner instanceof SellableItem && $kind === 'video' => MediaRole::VARIANT_VIDEO,
            default => null,
        };

        if ($role === null) {
            throw ValidationException::withMessages(['kind' => 'The media kind is not supported for this owner.']);
        }

        return $role;
    }

    private function isPrimaryIndexViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'media_attachments_primary_owner_role_unique')
            || (str_contains($message, 'media_attachments.mediable_type')
                && str_contains($message, 'media_attachments.mediable_id')
                && str_contains($message, 'media_attachments.role')
                && str_contains($message, 'unique'));
    }
}
