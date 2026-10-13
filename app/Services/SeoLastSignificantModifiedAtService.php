<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SeoRecord;
use App\Support\SeoSettingCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Resolves the latest known change that can affect one public SEO detail response. */
final class SeoLastSignificantModifiedAtService
{
    /** @param array<string, mixed> $presentation */
    public function resolve(
        Model $entity,
        string $locale,
        array $presentation,
        ?SeoRecord $record,
    ): ?CarbonImmutable {
        $sources = [];
        $this->addColumns($sources, DB::table($entity->getTable())->where($entity->getKeyName(), $entity->getKey()), [
            'created_at', 'seo_last_significant_modified_at',
            ...($entity instanceof ContentPage || $entity instanceof Category || $entity instanceof Product
                ? ['seo_last_significant_modified_at_'.$locale]
                : []),
        ]);
        $this->addColumns($sources, DB::table('seo_records')
            ->where('seoable_type', $entity->getMorphClass())
            ->where('seoable_id', $entity->getKey())
            ->where('locale', $locale), ['created_at', 'updated_at']);

        if ($entity instanceof Product) {
            $this->addProductSources($sources, $entity, $locale);
        } elseif ($entity instanceof Category) {
            $this->addCategorySources($sources, $entity, $locale);
        } elseif (! $entity instanceof ContentPage) {
            return null;
        }

        $imageIds = $this->imageSettingIds($entity, $record, $presentation);
        if ($record?->og_image_id !== null) {
            $imageIds[] = (int) $record->og_image_id;
        }
        if ($imageIds !== []) {
            $this->addColumns($sources, DB::table('media_assets')->whereIn('id', array_unique($imageIds)), [
                'created_at', 'updated_at', 'deleted_at',
            ]);
        }

        $this->addSettingsSources($sources, $entity, $locale, $presentation, $record);

        if ($sources === []) {
            return null;
        }

        $query = array_shift($sources);
        foreach ($sources as $source) {
            $query->unionAll($source);
        }

        $now = CarbonImmutable::now('UTC');
        $latest = DB::query()->fromSub($query, 'seo_change_dates')
            ->where('changed_at', '<=', $now->toDateTimeString())
            ->max('changed_at');
        if (! is_string($latest) || trim($latest) === '') {
            return null;
        }

        return CarbonImmutable::parse($latest, 'UTC')->utc();
    }

    /** @param list<Builder> $sources @param list<string> $columns */
    private function addColumns(array &$sources, Builder $query, array $columns): void
    {
        foreach ($columns as $column) {
            $source = clone $query;
            $source->selectRaw($this->timestampExpression($column).' AS changed_at')->whereNotNull($column);
            $sources[] = $source;
        }
    }

    /** @param list<Builder> $sources */
    private function addProductSources(array &$sources, Product $product, string $locale): void
    {
        $productId = (int) $product->getKey();
        $sellableItems = DB::table('sellable_items')->where('product_id', $productId);
        $this->addColumns($sources, $sellableItems, [
            'created_at', 'deleted_at', 'superseded_at', 'seo_last_significant_modified_at',
        ]);

        $options = DB::table('product_options')->where('product_id', $productId);
        $this->addColumns($sources, $options, ['created_at', 'deleted_at']);
        $values = DB::table('product_option_values as option_values')
            ->join('product_options as options', 'options.id', '=', 'option_values.product_option_id')
            ->where('options.product_id', $productId);
        $this->addColumns($sources, $values, ['option_values.created_at', 'option_values.deleted_at']);

        $variantIds = DB::table('sellable_items')->select('id')->where('product_id', $productId);
        $attachments = DB::table('media_attachments')->where(function (Builder $query) use ($product, $variantIds): void {
            $query->where(function (Builder $query) use ($product): void {
                $query->where('mediable_type', $product->getMorphClass())
                    ->where('mediable_id', $product->getKey());
            })->orWhere(function (Builder $query) use ($variantIds): void {
                $query->where('mediable_type', (new \App\Models\SellableItem)->getMorphClass())
                    ->whereIn('mediable_id', $variantIds);
            });
        });
        $this->addColumns($sources, $attachments, ['created_at', 'updated_at']);

        $assetIds = DB::table('media_attachments')->select('media_asset_id')->where(function (Builder $query) use ($product, $variantIds): void {
            $query->where(function (Builder $query) use ($product): void {
                $query->where('mediable_type', $product->getMorphClass())
                    ->where('mediable_id', $product->getKey());
            })->orWhere(function (Builder $query) use ($variantIds): void {
                $query->where('mediable_type', (new \App\Models\SellableItem)->getMorphClass())
                    ->whereIn('mediable_id', $variantIds);
            });
        });
        $this->addColumns($sources, DB::table('media_assets')->whereIn('id', $assetIds), ['created_at', 'updated_at', 'deleted_at']);

        $this->addColumns($sources, DB::table('category_product')->where('product_id', $productId), ['created_at', 'updated_at']);
        $relatedCategories = $product->getAttribute('seo_related_categories');
        if (is_iterable($relatedCategories)) {
            $categoryIds = collect($relatedCategories)
                ->filter(static fn ($category): bool => $category instanceof Category)
                ->map(static fn (Category $category): int => (int) $category->getKey())
                ->unique()->values()->all();
            if ($categoryIds !== []) {
                $this->addColumns($sources, DB::table('categories')->whereIn('id', $categoryIds), [
                    'created_at', 'deleted_at', 'seo_last_significant_modified_at',
                    'seo_last_significant_modified_at_'.$locale,
                ]);
            }
        }

        $offers = DB::table('offers')
            ->join('offer_product', 'offer_product.offer_id', '=', 'offers.id')
            ->where('offer_product.product_id', $productId)
            ->get(['offers.created_at', 'offers.updated_at', 'offers.starts_at', 'offers.ends_at', 'offers.is_enabled']);
        foreach ($offers as $offer) {
            foreach (['created_at', 'updated_at'] as $column) {
                if ($offer->{$column} !== null) {
                    $sources[] = $this->timestampLiteral($offer->{$column});
                }
            }

            $now = CarbonImmutable::now('UTC');
            if ((bool) $offer->is_enabled) {
                $startsAt = CarbonImmutable::parse($offer->starts_at, 'UTC')->utc();
                $endsAt = CarbonImmutable::parse($offer->ends_at, 'UTC')->utc();
                if ($startsAt->lessThanOrEqualTo($now) && $endsAt->greaterThan($now)) {
                    $sources[] = $this->timestampLiteral($startsAt->toDateTimeString());
                } elseif ($endsAt->lessThanOrEqualTo($now)) {
                    $sources[] = $this->timestampLiteral($endsAt->toDateTimeString());
                }
            }
        }

        $publishedAt = $product->published_at;
        if ($publishedAt !== null && $publishedAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $sources[] = $this->timestampLiteral($publishedAt->utc()->toDateTimeString());
        }
    }

    /** @param list<Builder> $sources */
    private function addCategorySources(array &$sources, Category $category, string $locale): void
    {
        $attachments = DB::table('media_attachments')
            ->where('mediable_type', $category->getMorphClass())
            ->where('mediable_id', $category->getKey())
            ->where('role', 'category_cover');
        $this->addColumns($sources, $attachments, ['created_at', 'updated_at']);
        $assetIds = DB::table('media_attachments')
            ->select('media_asset_id')
            ->where('mediable_type', $category->getMorphClass())
            ->where('mediable_id', $category->getKey())
            ->where('role', 'category_cover');
        $this->addColumns($sources, DB::table('media_assets')->whereIn('id', $assetIds), ['created_at', 'updated_at', 'deleted_at']);

        $relatedCategories = $category->getAttribute('seo_related_categories');
        if (is_iterable($relatedCategories)) {
            $categoryIds = collect($relatedCategories)
                ->filter(static fn ($relatedCategory): bool => $relatedCategory instanceof Category)
                ->map(static fn (Category $relatedCategory): int => (int) $relatedCategory->getKey())
                ->unique()->values()->all();
            if ($categoryIds !== []) {
                $this->addColumns($sources, DB::table('categories')->whereIn('id', $categoryIds), [
                    'created_at', 'deleted_at', 'seo_last_significant_modified_at',
                    'seo_last_significant_modified_at_'.$locale,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $presentation @return list<int> */
    private function imageSettingIds(Model $entity, ?SeoRecord $record, array $presentation): array
    {
        if (($presentation['image'] ?? null) !== null && $presentation['image'] !== '') {
            return [];
        }

        $ids = [];
        if ($record?->og_image_id !== null) {
            $ids[] = (int) $record->og_image_id;
        }

        $settingKeys = ['default_og_image_id'];
        if ($entity instanceof ContentPage) {
            $settingKeys[] = 'store_logo_id';
        }
        foreach (DB::table('seo_settings')->whereIn('key', $settingKeys)->pluck('value_json') as $value) {
            $id = json_decode((string) $value, true);
            if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param list<Builder> $sources @param array<string, mixed> $presentation */
    private function addSettingsSources(
        array &$sources,
        Model $entity,
        string $locale,
        array $presentation,
        ?SeoRecord $record,
    ): void {
        $keys = ['supportedLocales'];
        if ($record?->robots_index === null || $record?->robots_follow === null) {
            $keys[] = 'defaultRobotsPolicy';
        }
        if (($record?->meta_title === null || trim($record->meta_title) === '')
            && empty($presentation['name']) && empty($presentation['title'])) {
            $keys[] = 'defaultSeoTitle';
            $keys[] = 'storeName';
        }
        if (($record?->meta_description === null || trim($record->meta_description) === '')
            && empty($presentation['description']) && empty($presentation['body'])) {
            $keys[] = 'defaultMetaDescription';
        }
        $customImageIsAvailable = $record?->ogImage !== null && ! $record->ogImage->trashed();
        if (empty($presentation['image']) && ! $customImageIsAvailable) {
            $keys[] = 'defaultOgImageId';
            if ($entity instanceof ContentPage) {
                $keys[] = 'storeLogoId';
            }
        }
        $keys = array_values(array_unique($keys));
        $databaseKeys = array_map(
            static fn (string $key): string => SeoSettingCatalog::definition($key)['key'],
            $keys,
        );

        $settings = DB::table('seo_settings')->whereIn('key', $databaseKeys)
            ->where(function (Builder $query) use ($locale): void {
                $query->where('locale', '')->orWhere('locale', $locale);
            });
        $this->addColumns($sources, $settings, ['created_at', 'updated_at']);

        $audits = DB::table('seo_audits')->where('target_type', 'seo_setting')
            ->where(function (Builder $query) use ($keys, $locale): void {
                foreach ($keys as $key) {
                    foreach (['before_json', 'after_json'] as $snapshot) {
                        $query->orWhere(function (Builder $entry) use ($key, $locale, $snapshot): void {
                            $entry->whereJsonContains($snapshot.'->key', $key)
                                ->where(function (Builder $entryLocale) use ($locale, $snapshot): void {
                                    $entryLocale->whereJsonContains($snapshot.'->locale', $locale)
                                        ->orWhereNull($snapshot.'->locale');
                                });
                        });
                    }
                }
            });
        $this->addColumns($sources, $audits, ['created_at']);
    }

    private function timestampExpression(string $column): string
    {
        return DB::getDriverName() === 'pgsql'
            ? 'CAST('.$column.' AS TIMESTAMP)'
            : 'datetime('.$column.')';
    }

    private function timestampLiteral(string $timestamp): Builder
    {
        $expression = DB::getDriverName() === 'pgsql'
            ? 'CAST(? AS TIMESTAMP) AS changed_at'
            : 'datetime(?) AS changed_at';

        return DB::query()->selectRaw($expression, [$timestamp]);
    }
}
