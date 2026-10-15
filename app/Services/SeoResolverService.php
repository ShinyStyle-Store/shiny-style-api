<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SeoRecord;
use App\Support\SeoSettingCatalog;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;

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
