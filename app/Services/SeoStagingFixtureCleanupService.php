<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SeoStagingFixtureCleanupService
{
    private const PREFIX = 'SEO-STAGING-';

    /** @return array{deleted: int, skipped: int} */
    public function clean(): array
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('SEO acceptance fixtures may be cleaned only when APP_ENV=staging.');
        }

        if (app()->isProduction()) {
            throw new RuntimeException('SEO acceptance fixture cleanup is forbidden in production.');
        }

        if (! Schema::hasTable('seo_staging_fixture_records')) {
            throw new RuntimeException('The SEO staging fixture ownership ledger does not exist.');
        }

        return DB::transaction(function (): array {
            $records = DB::table('seo_staging_fixture_records')->orderBy('id')->get();
            $deleted = 0;
            $skipped = 0;
            $order = ['seo_setting', 'content_page', 'product', 'category'];

            foreach ($order as $recordType) {
                foreach ($records->where('record_type', $recordType) as $record) {
                    $result = match ($recordType) {
                        'seo_setting' => $this->deleteSetting($record),
                        'content_page' => $this->deleteContentPage($record),
                        'product' => $this->deleteProduct($record),
                        'category' => $this->deleteCategory($record),
                        default => false,
                    };

                    if ($result) {
                        DB::table('seo_staging_fixture_records')->where('id', $record->id)->delete();
                        $deleted++;
                    } else {
                        $skipped++;
                    }
                }
            }

            return ['deleted' => $deleted, 'skipped' => $skipped];
        });
    }

    private function deleteSetting(object $record): bool
    {
        $setting = SeoSetting::query()->find($record->record_id);
        if (! $setting instanceof SeoSetting) {
            return true;
        }

        $fingerprint = hash('sha256', json_encode($setting->value_json, JSON_THROW_ON_ERROR));
        if (! is_string($record->record_fingerprint) || ! hash_equals($record->record_fingerprint, $fingerprint)) {
            return false;
        }

        $setting->delete();

        return true;
    }

    private function deleteContentPage(object $record): bool
    {
        $page = ContentPage::query()->find($record->record_id);
        if (! $page instanceof ContentPage) {
            return true;
        }

        $fingerprint = hash('sha256', json_encode([
            $page->slug,
            $page->title_ar,
            $page->title_en,
            $page->body_ar,
            $page->body_en,
        ], JSON_THROW_ON_ERROR));
        $hasFixtureMarker = str_starts_with((string) $page->title_ar, self::PREFIX)
            || str_starts_with((string) $page->title_en, self::PREFIX);
        if (! $hasFixtureMarker
            || ! is_string($record->record_fingerprint)
            || ! hash_equals($record->record_fingerprint, $fingerprint)
            || $page->seoRecords()->exists()) {
            return false;
        }

        $page->delete();

        return true;
    }

    private function deleteProduct(object $record): bool
    {
        $product = Product::withTrashed()->find($record->record_id);
        if (! $product instanceof Product) {
            return true;
        }

        if (! str_starts_with((string) $product->slug, 'seo-staging-')) {
            return false;
        }

        $sellableItemIds = $product->sellableItems()->withTrashed()->pluck('id');
        $hasOrders = DB::table('order_items')
            ->where('product_id', $product->getKey())
            ->orWhereIn('sellable_item_id', $sellableItemIds)
            ->exists();
        $hasConversions = DB::table('product_conversions')
            ->where('product_id', $product->getKey())
            ->orWhereIn('source_sellable_item_id', $sellableItemIds)
            ->exists();
        $hasMediaAttachments = DB::table('media_attachments')
            ->where(function (QueryBuilder $query) use ($product, $sellableItemIds): void {
                $query->where(function (QueryBuilder $productAttachments) use ($product): void {
                    $productAttachments->where('mediable_type', $product->getMorphClass())
                        ->where('mediable_id', $product->getKey());
                })->orWhere(function (QueryBuilder $variantAttachments) use ($sellableItemIds): void {
                    $variantAttachments->where('mediable_type', (new SellableItem)->getMorphClass())
                        ->whereIn('mediable_id', $sellableItemIds);
                });
            })
            ->exists();
        if ($hasOrders
            || $hasConversions
            || $hasMediaAttachments
            || $product->seoRecords()->exists()
            || $product->options()->exists()
            || $product->offers()->exists()
            || $product->reviewImages()->exists()) {
            return false;
        }

        $product->forceDelete();

        return true;
    }

    private function deleteCategory(object $record): bool
    {
        $category = Category::withTrashed()->find($record->record_id);
        if (! $category instanceof Category) {
            return true;
        }

        $hasAttachedProducts = DB::table('category_product')
            ->where('category_id', $category->getKey())
            ->exists();
        if (! str_starts_with((string) $category->slug, 'seo-staging-')
            || $hasAttachedProducts
            || DB::table('categories')->where('parent_id', $category->getKey())->exists()
            || $category->seoRecords()->exists()
            || $category->mediaAttachments()->exists()) {
            return false;
        }

        $category->forceDelete();

        return true;
    }
}
