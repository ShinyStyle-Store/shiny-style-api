<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SeoRecord;
use App\Support\SeoSettingCatalog;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class SeoResolverService
{
    public function __construct(
        private readonly SeoMetadataService $metadata,
        private readonly SeoEligibilityService $eligibility,
        private readonly SeoSettingsService $settings,
        private readonly SeoLastSignificantModifiedAtService $lastModified,
    ) {}

    /** @param array<string, mixed> $presentation
     *  @return array<string, mixed>
     */
    public function resolve(
        EloquentModel $entity,
        bool $isPubliclyVisible,
        array $presentation,
        ?Collection $settings = null,
    ): array
    {
        $locale = app()->getLocale();
        $settings ??= $this->settings->all();
        $seoRecord = $this->seoRecord($entity, $locale);
        $supportedLocales = $this->settingValue($settings, 'supported_locales');
        $supportedLocales = is_array($supportedLocales) ? $supportedLocales : ['ar', 'en'];
        $availableLocales = $this->eligibility->availableLocales($entity, $supportedLocales);
        $resolved = $this->metadata->build($entity, $presentation, $seoRecord, $settings, $locale);
        $eligibility = $this->eligibility->resolve(
            $isPubliclyVisible,
            $locale,
            $availableLocales,
            $resolved['robots'],
        );
        $resolved['robots'] = $eligibility['robots'];
        $resolved['indexable'] = $eligibility['indexable'];
        $resolved['availableLocales'] = $availableLocales;
        $resolved['lastSignificantModifiedAt'] = $this->lastModified
            ->resolve($entity, $locale, $presentation, $seoRecord, $settings)
            ?->toISOString();

        return $resolved;
    }

    /** @param Collection<int, mixed>|null $settings @return array<string, mixed> */
    public function resolvePublicPage(string $pageKey, ?Collection $settings = null): array
    {
        $page = SeoSettingCatalog::PUBLIC_PAGES[$pageKey] ?? null;
        if ($page === null) {
            throw new InvalidArgumentException('Unsupported public SEO page.');
        }

        $settings ??= $this->settings->all();
        $locale = app()->getLocale();
        $resolvedPage = $this->metadata->buildFixedPage($page, $settings, $locale);
        $seo = $resolvedPage['seo'];
        $configuredLocales = $this->settingValue($settings, 'supported_locales');
        $availableLocales = is_array($configuredLocales) && array_is_list($configuredLocales)
            ? array_values(array_intersect(['ar', 'en'], $configuredLocales))
            : ['ar', 'en'];
        $eligibility = $this->eligibility->resolve(true, $locale, $availableLocales, $seo['robots']);
        $seo['robots'] = $eligibility['robots'];
        $seo['indexable'] = $eligibility['indexable'];
        $seo['availableLocales'] = $availableLocales;
        $seo['lastSignificantModifiedAt'] = $this->lastModified
            ->resolveFixedPage($resolvedPage['settingKeys'], $resolvedPage['assetIds'], $locale)
            ?->toISOString();

        return $seo;
    }

    /** @param list<string> $supportedLocales
     *  @return array{canonicalPath: string, record: ?SeoRecord}|null
     */
    public function resolveSitemapLocale(
        EloquentModel $entity,
        Collection $settings,
        string $locale,
        array $supportedLocales,
    ): ?array {
        $record = $this->seoRecord($entity, $locale);
        $availableLocales = $this->eligibility->availableLocales($entity, $supportedLocales);
        $metadata = $this->metadata->sitemapPolicy($entity, $record, $settings);
        $eligibility = $this->eligibility->resolve(
            true,
            $locale,
            $availableLocales,
            $metadata['robots'],
        );
        if (! $eligibility['indexable'] || ! is_string($metadata['canonicalPath'])) {
            return null;
        }

        return [
            'canonicalPath' => $metadata['canonicalPath'],
            'record' => $record,
        ];
    }

    /** @param list<string> $supportedLocales
     *  @return array{canonicalPath: ?string, reason: ?string}
     */
    public function sitemapLocaleDecision(
        EloquentModel $entity,
        Collection $settings,
        string $locale,
        array $supportedLocales,
    ): array {
        $record = $this->seoRecord($entity, $locale);
        $availableLocales = $this->eligibility->availableLocales($entity, $supportedLocales);
        if (! in_array($locale, $availableLocales, true)) {
            return ['canonicalPath' => null, 'reason' => 'locale_unavailable'];
        }

        $metadata = $this->metadata->sitemapPolicy($entity, $record, $settings);
        if (! config('seo.indexing_allowed', false)) {
            return ['canonicalPath' => $metadata['canonicalPath'], 'reason' => 'indexing_disabled'];
        }

        if (! is_string($metadata['canonicalPath'])) {
            return ['canonicalPath' => null, 'reason' => 'canonical_path_unavailable'];
        }

        if (! $metadata['robots']['index']) {
            return ['canonicalPath' => $metadata['canonicalPath'], 'reason' => 'robots_noindex'];
        }

        return ['canonicalPath' => $metadata['canonicalPath'], 'reason' => null];
    }

    /**
     * @param list<array{entity: EloquentModel, locale: string, presentation: array<string, mixed>, record: ?SeoRecord}> $entries
     * @return array<int, ?string>
     */
    public function sitemapLastModifiedBatch(array $entries, Collection $settings): array
    {
        return array_map(
            static fn ($date): ?string => $date?->toISOString(),
            $this->lastModified->resolveMany($entries, $settings),
        );
    }

    /** @param iterable<EloquentModel> $entities */
    public function prepareSitemapBatch(iterable $entities): void
    {
        $products = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Product) {
                $products[] = $entity;
            }
        }

        $this->lastModified->prepareProductBatch($products);
    }

    private function seoRecord(EloquentModel $entity, string $locale): ?SeoRecord
    {
        if ($entity->relationLoaded('seoRecords')) {
            $record = $entity->getRelation('seoRecords')->firstWhere('locale', $locale);

            return $record instanceof SeoRecord ? $record : null;
        }

        $record = $entity->seoRecords()->with('ogImage')->where('locale', $locale)->first();

        return $record instanceof SeoRecord ? $record : null;
    }

    private function settingValue(Collection $settings, string $key): mixed
    {
        $databaseKey = SeoSettingCatalog::definition($key)['key'] ?? $key;

        return $settings->first(static fn ($setting): bool => $setting->key === $databaseKey
            && $setting->locale === '')?->value_json;
    }
}
