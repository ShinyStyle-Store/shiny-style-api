<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SeoRedirect;
use App\Models\SeoRecord;
use App\Models\SeoSetting;
use App\Support\SeoSettingCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SeoAuditReportService
{
    private const LOCALES = ['ar', 'en'];

    public function __construct(
        private readonly CategoryHierarchyService $categories,
        private readonly SeoEligibilityService $eligibility,
        private readonly SeoMetadataService $metadata,
        private readonly SeoPublicImageService $images,
        private readonly SeoSettingsService $settings,
        private readonly SeoSitemapItemsService $sitemap,
    ) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $settings = $this->settings->all();
        $supportedLocales = $this->settingValue($settings, 'supported_locales');
        $supportedLocales = is_array($supportedLocales) ? $supportedLocales : self::LOCALES;
        $sitemapCounts = $this->eligibleSitemapUrlCounts();
        $productCounts = $this->productContentCounts(
            $settings,
            $supportedLocales,
            $sitemapCounts['byType']['product'],
        );
        $imageCounts = $productCounts['invalidImages'];

        $visibleCategoryIds = $this->categories->visibilitySnapshot()->visibleIds();
        $imageCounts['categories'] = $this->invalidImageOverrides(
            Category::query()->whereIn('id', $visibleCategoryIds),
            $supportedLocales,
        );
        $imageCounts['pages'] = $this->invalidImageOverrides(
            ContentPage::query()->whereIn('slug', config('content.page_slugs', [])),
            $supportedLocales,
        );

        $invalidByLocale = array_fill_keys(self::LOCALES, 0);
        $invalidTotal = 0;
        foreach ($imageCounts as $entityCounts) {
            foreach (self::LOCALES as $locale) {
                $invalidByLocale[$locale] += $entityCounts[$locale];
                $invalidTotal += $entityCounts[$locale];
            }
        }

        return [
            'scope' => [
                'products' => 'Effectively public products with at least one supported, non-empty localized name.',
                'indexEligibility' => 'Current Laravel visibility, locale, robots, canonical, redirect, and SEO indexing rules.',
                'seoImages' => 'Public product, category, and configured content-page locale records; counts invalid non-null custom image overrides.',
                'activeRedirects' => 'All active SEO redirect rows across domain scopes.',
                'descriptionCriteria' => 'The final public description after custom, localized content, then localized default fallback; empty after HTML and whitespace cleanup is missing. No minimum length is applied.',
            ],
            'products' => [
                'indexEligible' => [
                    'unit' => 'distinct products and product-locale pages',
                    'distinctProducts' => $productCounts['indexEligible']['distinctProducts'],
                    'byLocale' => $productCounts['indexEligible']['byLocale'],
                    'totalProductLocalePages' => array_sum($productCounts['indexEligible']['byLocale']),
                    'indexingAllowed' => (bool) config('seo.indexing_allowed', false),
                ],
                'customSeoTitleByLocale' => $productCounts['customSeoTitle'],
                'missingUsefulDescriptionByLocale' => $productCounts['missingDescription'],
            ],
            'seoImages' => [
                'invalidCustomOverrides' => [
                    'byLocale' => $invalidByLocale,
                    'byEntityType' => $imageCounts,
                    'totalPageLocaleOverrides' => $invalidTotal,
                    'unconfiguredOverrideIsNotAnError' => true,
                ],
            ],
            'sitemap' => [
                'eligibleUrlsByType' => $sitemapCounts['byType'],
                'eligibleUrlsByLocale' => $sitemapCounts['byLocale'],
                'totalEligibleUrls' => $sitemapCounts['total'],
                'xmlPublicationStatus' => null,
                'lastGenerationError' => null,
                'publicationStatusAvailable' => false,
                'countsAreGoogleIndexingMetrics' => false,
            ],
            'activeRedirects' => SeoRedirect::query()->where('is_active', true)->count(),
        ];
    }

    /** @param Collection<int, SeoSetting> $settings @param list<string> $supportedLocales
     *  @param array{ar: int, en: int, total: int, distinctEntities: int} $eligibleProductSummary
     *  @return array{indexEligible: array{distinctProducts: int, byLocale: array{ar: int, en: int}}, customSeoTitle: array{ar: int, en: int}, missingDescription: array{ar: int, en: int}, invalidImages: array{products: array{ar: int, en: int}}}
     */
    private function productContentCounts(
        Collection $settings,
        array $supportedLocales,
        array $eligibleProductSummary,
    ): array {
        $counts = [
            'indexEligible' => ['distinctProducts' => 0, 'byLocale' => array_fill_keys(self::LOCALES, 0)],
            'customSeoTitle' => array_fill_keys(self::LOCALES, 0),
            'missingDescription' => array_fill_keys(self::LOCALES, 0),
            'invalidImages' => ['products' => array_fill_keys(self::LOCALES, 0)],
        ];

        $counts['indexEligible']['byLocale'] = [
            'ar' => $eligibleProductSummary['ar'],
            'en' => $eligibleProductSummary['en'],
        ];
        $counts['indexEligible']['distinctProducts'] = $eligibleProductSummary['distinctEntities'];

        $visibleCategoryIds = $this->categories->visibilitySnapshot()->visibleIds();
        Product::query()
            ->visible($visibleCategoryIds)
            ->select(['id', 'name_ar', 'name_en', 'description_ar', 'description_en'])
            ->with('seoRecords.ogImage')
            ->chunkById(200, function (Collection $products) use (&$counts, $settings, $supportedLocales): void {
                foreach ($products as $product) {
                    $records = $product->seoRecords->keyBy('locale');
                    foreach ($this->eligibility->availableLocales($product, $supportedLocales) as $locale) {
                        $record = $records->get($locale);
                        if ($this->metadata->cleanPublicText($record?->meta_title) !== null) {
                            $counts['customSeoTitle'][$locale]++;
                        }

                        $sourceDescription = $this->localizedProductDescription($product, $locale);
                        $description = $this->metadata->resolveDescription(
                            ['description' => $sourceDescription],
                            $record,
                            $settings,
                            $locale,
                        );
                        if ($description === null) {
                            $counts['missingDescription'][$locale]++;
                        }

                        if ($record?->og_image_id !== null && $this->images->url($record->ogImage) === null) {
                            $counts['invalidImages']['products'][$locale]++;
                        }
                    }
                }
            });

        return $counts;
    }

    /** @param Builder<Category>|Builder<ContentPage> $query @param list<string> $supportedLocales
     *  @return array{ar: int, en: int}
     */
    private function invalidImageOverrides(Builder $query, array $supportedLocales): array
    {
        $counts = array_fill_keys(self::LOCALES, 0);
        $query->with('seoRecords.ogImage')
            ->chunkById(200, function (Collection $entities) use (&$counts, $supportedLocales): void {
                foreach ($entities as $entity) {
                    $records = $entity->seoRecords->keyBy('locale');
                    foreach ($this->eligibility->availableLocales($entity, $supportedLocales) as $locale) {
                        $record = $records->get($locale);
                        if ($record instanceof SeoRecord
                            && $record->og_image_id !== null
                            && $this->images->url($record->ogImage) === null) {
                            $counts[$locale]++;
                        }
                    }
                }
            });

        return $counts;
    }

    private function localizedProductDescription(Product $product, string $locale): ?string
    {
        $arabic = $product->description_ar;
        $english = $product->description_en;

        if ($locale === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== '' ? $english : null);
    }

    /** @return array{byType: array<string, array{ar: int, en: int, total: int, distinctEntities: int}>, byLocale: array{ar: int, en: int}, total: int} */
    private function eligibleSitemapUrlCounts(): array
    {
        $byType = [];
        $byLocale = array_fill_keys(self::LOCALES, 0);
        $total = 0;

        foreach (SeoSitemapItemsService::TYPES as $type) {
            $summary = $this->sitemap->eligibleSummary($type);
            $counts = $summary['byLocale'];
            $typeTotal = $summary['totalLocaleUrls'];
            $byType[$type] = [
                'ar' => $counts['ar'],
                'en' => $counts['en'],
                'total' => $typeTotal,
                'distinctEntities' => $summary['distinctEntities'],
            ];
            foreach (self::LOCALES as $locale) {
                $byLocale[$locale] += $counts[$locale];
            }
            $total += $typeTotal;
        }

        return ['byType' => $byType, 'byLocale' => $byLocale, 'total' => $total];
    }

    /** @param Collection<int, SeoSetting> $settings */
    private function settingValue(Collection $settings, string $apiKey, ?string $locale = null): mixed
    {
        $definition = SeoSettingCatalog::definition($apiKey);
        if ($definition === null) {
            return null;
        }

        return $settings->first(static fn (SeoSetting $setting): bool => $setting->key === $definition['key']
            && $setting->locale === ($locale ?? ''))?->value_json;
    }
}
