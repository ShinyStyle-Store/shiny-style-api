<?php

namespace App\Services;

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
    public function resolve(EloquentModel $entity, bool $isPubliclyVisible, array $presentation): array
    {
        $locale = app()->getLocale();
        $settings = $this->settings->all();
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
            ->resolve($entity, $locale, $presentation, $seoRecord)
            ?->toISOString();

        return $resolved;
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
