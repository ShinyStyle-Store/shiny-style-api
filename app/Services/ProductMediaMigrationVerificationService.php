<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class ProductMediaMigrationVerificationService
{
    /** @return array{scanned: int, verified: int, issues: list<array{legacy_id: int|null, reason: string}>} */
    public function verify(): array
    {
        $issues = [];
        if (! Schema::hasTable('product_media')) {
            return ['scanned' => 0, 'verified' => 0, 'issues' => [['legacy_id' => null, 'reason' => 'legacy table is missing']]];
        }
        if (! Schema::hasTable('legacy_product_media_migrations')) {
            return ['scanned' => 0, 'verified' => 0, 'issues' => [['legacy_id' => null, 'reason' => 'provenance ledger is missing']]];
        }

        $records = DB::table('product_media')->whereNull('deleted_at')->orderBy('id')->get();
        foreach ($records as $record) {
            $reason = $this->verifyRecord($record);
            if ($reason !== null) {
                $issues[] = ['legacy_id' => (int) $record->id, 'reason' => $reason];
            }
        }

        return [
            'scanned' => $records->count(),
            'verified' => $records->count() - count($issues),
            'issues' => $issues,
        ];
    }

    private function verifyRecord(object $record): ?string
    {
        $mapping = DB::table('legacy_product_media_migrations')
            ->where('legacy_product_media_id', $record->id)
            ->first();
        if ($mapping === null) {
            return 'mapping is missing';
        }
        if ($mapping->status !== 'migrated') {
            return 'mapping is not successfully migrated';
        }
        if ($mapping->media_asset_id === null) {
            return 'mapped asset reference is missing';
        }
        if ($mapping->media_attachment_id === null) {
            return 'mapped attachment reference is missing';
        }

        $asset = MediaAsset::query()->find($mapping->media_asset_id);
        if ($asset === null) {
            return 'mapped asset is missing or deleted';
        }
        try {
            if (! Storage::disk($asset->disk)->exists($asset->path)) {
                return 'mapped asset file is missing';
            }
        } catch (\Throwable) {
            return 'mapped asset file could not be checked';
        }
        $attachment = MediaAttachment::query()->find($mapping->media_attachment_id);
        if ($attachment === null) {
            return 'mapped attachment is missing';
        }
        if ((int) $attachment->media_asset_id !== (int) $asset->getKey()) {
            return 'mapped attachment references another asset';
        }

        if ($record->sellable_item_id !== null) {
            $owner = SellableItem::withTrashed()->find($record->sellable_item_id);
            if ($owner === null || $owner->trashed()) {
                return 'legacy SellableItem owner is missing or deleted';
            }
            if ((int) $owner->product_id !== (int) $record->product_id) {
                return 'legacy Product and SellableItem ownership is inconsistent';
            }
            $role = match ($record->type) {
                'image' => MediaRole::VARIANT_IMAGE,
                'video' => MediaRole::VARIANT_VIDEO,
                default => null,
            };
        } else {
            $owner = Product::withTrashed()->find($record->product_id);
            if ($owner === null || $owner->trashed()) {
                return 'legacy Product owner is missing or deleted';
            }
            $role = match ($record->type) {
                'image' => MediaRole::PRODUCT_IMAGE,
                'video' => MediaRole::PRODUCT_VIDEO,
                default => null,
            };
        }

        if ($role === null) {
            return 'legacy media type has no supported role';
        }
        if ($attachment->mediable_type !== $owner->getMorphClass()
            || (string) $attachment->mediable_id !== (string) $owner->getKey()) {
            return 'mapped attachment owner is incorrect';
        }
        if ((string) $attachment->role !== $role) {
            return 'mapped attachment role is incorrect';
        }
        if ($attachment->alt_ar !== $record->alt_text_ar
            || $attachment->alt_en !== $record->alt_text_en
            || (int) $attachment->sort_order !== (int) $record->sort_order
            || (bool) $attachment->is_primary !== (bool) $record->is_primary) {
            return 'mapped attachment metadata does not match legacy media';
        }
        if ($asset->media_type !== $record->type || MediaRole::mediaType($role) !== $asset->media_type) {
            return 'mapped asset type does not match legacy media type';
        }

        return null;
    }
}
