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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Resolves the latest known change that can affect one public SEO detail response. */
final class SeoLastSignificantModifiedAtService
{
    /** @var array<int, list<object>> */
    private array $productOffers = [];

    /** @var array<string, array{organizationId: ?string, name: ?string, url: ?string, logo: ?string}> */
    private array $storeIdentities = [];

    public function __construct(
        private readonly SeoMetadataService $metadata,
        private readonly StoreStructuredDataService $storeStructuredData,
    ) {}

    /** @param iterable<Product> $products */
    public function prepareProductBatch(iterable $products): void
    {
        $productIds = [];
        foreach ($products as $product) {
            $productIds[] = (int) $product->getKey();
            $this->productOffers[(int) $product->getKey()] = [];
        }

        if ($productIds === []) {
            return;
        }

        $offers = DB::table('offer_product')
            ->join('offers', 'offers.id', '=', 'offer_product.offer_id')
            ->whereIn('offer_product.product_id', array_unique($productIds))
            ->get([
                'offer_product.product_id', 'offers.created_at', 'offers.updated_at',
                'offers.starts_at', 'offers.ends_at', 'offers.is_enabled',
            ]);

        foreach ($offers as $offer) {
            $this->productOffers[(int) $offer->product_id][] = $offer;
        }
    }

    /** @param array<string, mixed> $presentation */
    public function resolve(
        Model $entity,
        string $locale,
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
    ): ?CarbonImmutable {
        $sources = $this->sources($entity, $locale, $presentation, $record, $settings);

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

    /**
     * @param list<string> $settingKeys
     * @param list<int> $assetIds
     */
    public function resolveFixedPage(array $settingKeys, array $assetIds, string $locale): ?CarbonImmutable
    {
        $definitions = collect($settingKeys)
            ->map(static fn (string $key): ?array => SeoSettingCatalog::definition($key) === null
                ? null
                : ['apiKey' => $key, ...SeoSettingCatalog::definition($key)])
            ->filter()
            ->values();
        $databaseKeys = $definitions->pluck('key')->all();
        $dates = [];

        if ($databaseKeys !== []) {
            $settingDate = DB::table('seo_settings')
                ->whereIn('key', $databaseKeys)
                ->whereIn('locale', ['', $locale])
                ->max('updated_at');
            if (is_string($settingDate)) {
                $dates[] = $settingDate;
            }

            $auditDate = DB::table('seo_audits')
                ->where('target_type', 'seo_setting')
                ->where(function (Builder $query) use ($definitions, $locale): void {
                    foreach ($definitions as $definition) {
                        $settingLocale = $definition['localized'] ? $locale : null;
                        foreach (['before_json', 'after_json'] as $snapshot) {
                            $query->orWhere(function (Builder $entry) use ($definition, $settingLocale, $snapshot): void {
                                $entry->where($snapshot.'->key', $definition['apiKey']);
                                if ($settingLocale === null) {
                                    $entry->whereNull($snapshot.'->locale');
                                } else {
                                    $entry->where($snapshot.'->locale', $settingLocale);
                                }
                            });
                        }
                    }
                })
                ->max('created_at');
            if (is_string($auditDate)) {
                $dates[] = $auditDate;
            }
        }

        if ($assetIds !== []) {
            $assets = DB::table('media_assets')->whereIn('id', array_values(array_unique($assetIds)))
                ->get(['created_at', 'updated_at', 'deleted_at']);
            foreach ($assets as $asset) {
                array_push($dates, $asset->created_at, $asset->updated_at, $asset->deleted_at);
            }
        }

        $now = CarbonImmutable::now('UTC');
        $validDates = collect($dates)
            ->filter(static fn (mixed $date): bool => is_string($date) && trim($date) !== '')
            ->map(static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC')->utc())
            ->filter(static fn (CarbonImmutable $date): bool => $date->lessThanOrEqualTo($now));
        $latest = null;
        foreach ($validDates as $date) {
            if ($latest === null || $date->greaterThan($latest)) {
                $latest = $date;
            }
        }

        return $latest;
    }

    /**
     * @param list<array{entity: Model, locale: string, presentation: array<string, mixed>, record: ?SeoRecord}> $entries
     * @return array<int, ?CarbonImmutable>
     */
    public function resolveMany(array $entries, Collection $settings): array
    {
        if ($entries === []) {
            return [];
        }

        $combined = null;
        foreach ($entries as $index => $entry) {
            $sources = $this->sources(
                $entry['entity'],
                $entry['locale'],
                $entry['presentation'],
                $entry['record'],
                $settings,
            );
            if ($sources === []) {
                continue;
            }

            $entityQuery = array_shift($sources);
            foreach ($sources as $source) {
                $entityQuery->unionAll($source);
            }

            $keyedQuery = DB::query()
                ->fromSub($entityQuery, 'seo_entity_dates')
                ->selectRaw('? AS entity_key, changed_at', [$index]);
            if ($combined === null) {
                $combined = $keyedQuery;
            } else {
                $combined->unionAll($keyedQuery);
            }
        }

        $results = array_fill(0, count($entries), null);
        if ($combined === null) {
            return $results;
        }

        $dates = DB::query()
            ->fromSub($combined, 'seo_change_dates')
            ->where('changed_at', '<=', CarbonImmutable::now('UTC')->toDateTimeString())
            ->selectRaw('entity_key, MAX(changed_at) AS changed_at')
            ->groupBy('entity_key')
            ->get();

        foreach ($dates as $date) {
            if (is_string($date->changed_at) && trim($date->changed_at) !== '') {
                $results[(int) $date->entity_key] = CarbonImmutable::parse($date->changed_at, 'UTC')->utc();
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $presentation @return list<Builder> */
    private function sources(
        Model $entity,
        string $locale,
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
    ): array {
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
            return [];
        }

        $imageResolution = $this->metadata->resolveOpenGraphImage($entity, $presentation, $record, $settings);
        $imageIds = $imageResolution['assetIds'];
        if ($record?->og_image_id !== null) {
            $imageIds[] = (int) $record->og_image_id;
        }
        if ($imageIds !== []) {
            $this->addColumns($sources, DB::table('media_assets')->whereIn('id', array_unique($imageIds)), [
                'created_at', 'updated_at', 'deleted_at',
            ]);
        }

        $this->addSettingsSources(
            $sources,
            $entity,
            $locale,
            $presentation,
            $record,
            $settings,
            $imageResolution,
        );

        return $sources;
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

        $offers = array_key_exists($productId, $this->productOffers)
            ? $this->productOffers[$productId]
            : DB::table('offers')
                ->join('offer_product', 'offer_product.offer_id', '=', 'offers.id')
                ->where('offer_product.product_id', $productId)
                ->get(['offers.created_at', 'offers.updated_at', 'offers.starts_at', 'offers.ends_at', 'offers.is_enabled'])
                ->all();
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

    /** @param list<Builder> $sources @param array<string, mixed> $presentation */
    private function addSettingsSources(
        array &$sources,
        Model $entity,
        string $locale,
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
        array $imageResolution,
    ): void {
        $keys = ['supportedLocales', 'storeName'];
        $storeIdentity = $entity instanceof Product
            ? $this->storeIdentity($settings, $locale)
            : null;
        if ($storeIdentity !== null && $storeIdentity['name'] !== null) {
            $keys = array_merge($keys, ['canonicalBaseUrl', 'storeLogoId']);
        }
        if ($record?->robots_index === null || $record?->robots_follow === null) {
            $keys[] = 'defaultRobotsPolicy';
        }
        $titleResolution = $this->metadata->resolveTitle(
            $presentation,
            $record,
            $settings,
            $locale,
        );
        $keys = array_merge($keys, $titleResolution['settingKeys']);
        $descriptionResolution = $this->metadata->resolveDescriptionWithDependencies(
            $presentation,
            $record,
            $settings,
            $locale,
        );
        if ($descriptionResolution['usesDefaultMetaDescription']) {
            $keys[] = 'defaultMetaDescription';
        }
        $keys = array_merge($keys, $imageResolution['settingKeys']);
        $identityLogoIds = [];
        if ($storeIdentity !== null && $storeIdentity['name'] !== null) {
            if ($storeIdentity['logo'] !== null) {
                $logoId = $this->settingValue($settings, 'storeLogoId');
                if (filter_var($logoId, FILTER_VALIDATE_INT) !== false) {
                    $identityLogoIds[] = (int) $logoId;
                }
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

        if ($identityLogoIds !== []) {
            $this->addColumns($sources, DB::table('media_assets')->whereIn('id', $identityLogoIds), [
                'created_at', 'updated_at', 'deleted_at',
            ]);
        }

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

    private function settingValue(Collection $settings, string $apiKey): mixed
    {
        $definition = SeoSettingCatalog::definition($apiKey);
        if ($definition === null) {
            return null;
        }

        return $settings->first(static fn ($setting): bool => $setting->key === $definition['key']
            && $setting->locale === '')?->value_json;
    }

    /** @return array{organizationId: ?string, name: ?string, url: ?string, logo: ?string} */
    private function storeIdentity(Collection $settings, string $locale): array
    {
        $identitySettings = $settings->filter(static fn ($setting): bool => in_array(
            $setting->key,
            ['store_name', 'canonical_base_url', 'store_logo_id'],
            true,
        ))->map(static fn ($setting): array => [
            'key' => $setting->key,
            'locale' => $setting->locale,
            'value' => $setting->value_json,
        ])->values()->all();
        $cacheKey = hash('sha256', serialize([$locale, $identitySettings]));

        return $this->storeIdentities[$cacheKey] ??= $this->storeStructuredData->identity($settings, $locale);
    }

    private function timestampLiteral(string $timestamp): Builder
    {
        $expression = DB::getDriverName() === 'pgsql'
            ? 'CAST(? AS TIMESTAMP) AS changed_at'
            : 'datetime(?) AS changed_at';

        return DB::query()->selectRaw($expression, [$timestamp]);
    }
}
