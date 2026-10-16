<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Support\SeoCanonicalPath;
use App\Support\SeoSitemapCursor;
use Illuminate\Support\Collection;

final class SeoSitemapItemsService
{
    public const TYPES = ['product', 'category', 'page'];

    public const MAX_LIMIT = 100;

    private const SCAN_LIMIT = 200;

    public function __construct(
        private readonly CategoryHierarchyService $categories,
        private readonly HomeProductSectionService $productRelations,
        private readonly SeoSettingsService $seoSettings,
        private readonly SeoResolverService $resolver,
        private readonly SeoRedirectService $redirects,
        private readonly SeoEligibilityService $eligibility,
    ) {}

    /** @return array{items: list<array<string, mixed>>, nextCursor: ?string} */
    public function page(string $type, ?string $cursor, int $limit): array
    {
        if (! config('seo.indexing_allowed', false)) {
            return ['items' => [], 'nextCursor' => null];
        }

        $lastId = $cursor === null ? 0 : SeoSitemapCursor::decode($cursor, $type);
        if ($lastId === null) {
            abort(422, 'The sitemap cursor is invalid or belongs to another entity type.');
        }

        $settings = $this->seoSettings->all();
        $supportedLocales = $this->settingValue($settings, 'supported_locales');
        $supportedLocales = is_array($supportedLocales) ? $supportedLocales : ['ar', 'en'];
        $visibility = $type === 'page'
            ? null
            : $this->categories->visibilitySnapshot(true, $type === 'category');
        $visibleCategoryIds = $visibility?->visibleIds() ?? [];
        $items = [];
        $lastModifiedRequests = [];
        $lastModifiedTargets = [];
        $scanned = 0;
        $exhausted = false;
        $stoppedAtLimit = false;

        while ($scanned < self::SCAN_LIMIT && count($items) < $limit) {
            $batchSize = min(100, self::SCAN_LIMIT - $scanned);
            $entities = $this->entitiesAfter($type, $lastId, $batchSize, $visibleCategoryIds);
            if ($entities->isEmpty()) {
                $exhausted = true;
                break;
            }

            $this->resolver->prepareSitemapBatch($entities);
            $redirectPaths = [];
            foreach ($entities as $entity) {
                $path = SeoCanonicalPath::expectedPath($entity);
                if ($path === null) {
                    continue;
                }
                foreach ($supportedLocales as $locale) {
                    if (in_array($locale, ['ar', 'en'], true)) {
                        $redirectPaths[] = '/'.$locale.$path;
                    }
                }
            }
            $redirectSources = array_fill_keys($this->redirects->existingSourcePaths($redirectPaths), true);

            foreach ($entities as $entity) {
                $lastId = (int) $entity->getKey();
                $scanned++;
                $this->prepareRelatedCategories($entity, $visibility);
                $resolvedItem = $this->item($type, $entity, $settings, $supportedLocales, $redirectSources);
                if ($resolvedItem !== null) {
                    $itemIndex = count($items);
                    $items[] = $resolvedItem['item'];
                    foreach ($resolvedItem['lastModifiedEntries'] as $localeIndex => $entry) {
                        $requestIndex = count($lastModifiedRequests);
                        $lastModifiedRequests[] = $entry;
                        $lastModifiedTargets[] = [$itemIndex, $localeIndex, $requestIndex];
                    }
                }

                if (count($items) >= $limit) {
                    $stoppedAtLimit = true;
                    break;
                }
            }

            if ($stoppedAtLimit) {
                break;
            }

            if ($entities->count() < $batchSize) {
                $exhausted = true;
                break;
            }
        }

        if ($lastModifiedRequests !== []) {
            $dates = $this->resolver->sitemapLastModifiedBatch($lastModifiedRequests, $settings);
            foreach ($lastModifiedTargets as [$itemIndex, $localeIndex, $requestIndex]) {
                $items[$itemIndex]['locales'][$localeIndex]['lastSignificantModifiedAt'] = $dates[$requestIndex] ?? null;
            }
        }

        return [
            'items' => $items,
            'nextCursor' => $exhausted ? null : SeoSitemapCursor::encode($type, $lastId),
        ];
    }

    /** @return array{distinctEntities: int, byLocale: array{ar: int, en: int}, totalLocaleUrls: int} */
    public function eligibleSummary(string $type): array
    {
        $summary = [
            'distinctEntities' => 0,
            'byLocale' => ['ar' => 0, 'en' => 0],
            'totalLocaleUrls' => 0,
        ];
        $cursor = null;

        do {
            $page = $this->page($type, $cursor, self::MAX_LIMIT);
            foreach ($page['items'] as $item) {
                $summary['distinctEntities']++;
                foreach ($item['eligibleLocales'] as $locale) {
                    $summary['byLocale'][$locale]++;
                    $summary['totalLocaleUrls']++;
                }
            }
            $cursor = $page['nextCursor'];
        } while ($cursor !== null);

        return $summary;
    }

    /** @param list<int> $visibleCategoryIds @return Collection<int, Product|Category|ContentPage> */
    private function entitiesAfter(string $type, int $lastId, int $limit, array $visibleCategoryIds): Collection
    {
        return match ($type) {
            'product' => Product::query()
                ->visible($visibleCategoryIds)
                ->where('products.id', '>', $lastId)
                ->with([
                    ...$this->productRelations->cardRelations($visibleCategoryIds, true),
                    'seoRecords.ogImage',
                ])
                ->orderBy('products.id')
                ->limit($limit)
                ->get(),
            'category' => Category::query()
                ->whereIn('id', $visibleCategoryIds)
                ->where('id', '>', $lastId)
                ->with(['seoRecords.ogImage', 'coverImageAttachment.mediaAsset'])
                ->orderBy('id')
                ->limit($limit)
                ->get(),
            'page' => ContentPage::query()
                ->whereIn('slug', config('content.page_slugs', []))
                ->where('id', '>', $lastId)
                ->with('seoRecords.ogImage')
                ->orderBy('id')
                ->limit($limit)
                ->get(),
            default => collect(),
        };
    }

    private function prepareRelatedCategories(
        Product|Category|ContentPage $entity,
        ?CategoryVisibilitySnapshot $visibility,
    ): void
    {
        if ($entity instanceof Product && $visibility !== null) {
            $entity->setAttribute(
                'seo_related_categories',
                $visibility->ancestorsAndSelfFor($entity->categories->modelKeys()),
            );
            return;
        }

        if ($entity instanceof Category && $visibility !== null) {
            $entity->setAttribute('seo_related_categories', $visibility->categoriesAffectingDetail($entity));
        }
    }

    /** @param Collection<int, SeoSetting> $settings @param list<string> $supportedLocales
     *  @param array<string, bool> $redirectSources
     *  @return array{item: array<string, mixed>, lastModifiedEntries: list<array{entity: Product|Category|ContentPage, locale: string, presentation: array<string, mixed>, record: ?\App\Models\SeoRecord}>}|null
     */
    private function item(
        string $type,
        Product|Category|ContentPage $entity,
        Collection $settings,
        array $supportedLocales,
        array $redirectSources,
    ): ?array {
        $eligibleLocales = [];
        $localeItems = [];
        $lastModifiedEntries = [];
        $availableLocales = $this->eligibility->availableLocales($entity, $supportedLocales);
        $expectedPath = SeoCanonicalPath::expectedPath($entity);
        if ($expectedPath === null) {
            return null;
        }

        foreach ($availableLocales as $locale) {
            $localizedPath = '/'.$locale.$expectedPath;
            if (isset($redirectSources[$localizedPath])) {
                continue;
            }

            $presentation = $this->presentation($entity, $locale);
            $resolved = $this->resolver->resolveSitemapLocale(
                $entity,
                $settings,
                $locale,
                $supportedLocales,
            );
            if ($resolved === null) {
                continue;
            }

            $eligibleLocales[] = $locale;
            $localeItems[] = [
                'locale' => $locale,
                'path' => $resolved['canonicalPath'],
                'lastSignificantModifiedAt' => null,
            ];
            $lastModifiedEntries[] = [
                'entity' => $entity,
                'locale' => $locale,
                'presentation' => $presentation,
                'record' => $resolved['record'],
            ];
        }

        if ($eligibleLocales === []) {
            return null;
        }

        return [
            'item' => [
                'id' => (int) $entity->getKey(),
                'entityType' => $type,
                'slug' => (string) $entity->slug,
                'eligibleLocales' => $eligibleLocales,
                'locales' => $localeItems,
            ],
            'lastModifiedEntries' => $lastModifiedEntries,
        ];
    }

    /** @return array<string, mixed> */
    private function presentation(Product|Category|ContentPage $entity, string $locale): array
    {
        return match (true) {
            $entity instanceof Product => $this->productPresentation($entity, $locale),
            $entity instanceof Category => $this->categoryPresentation($entity, $locale),
            default => [
                'title' => $locale === 'ar' ? $entity->title_ar : $entity->title_en,
                'body' => $locale === 'ar' ? $entity->body_ar : $entity->body_en,
            ],
        };
    }

    /** @return array<string, mixed> */
    private function productPresentation(Product $product, string $locale): array
    {
        $sellableItems = $product->publicSellableItems($product->sellableItems);
        $displayItem = $product->displaySellableItem($sellableItems);
        $image = $displayItem?->variantImages->first(fn ($attachment): bool => $attachment->is_primary)
            ?? $product->productImages->first(fn ($attachment): bool => $attachment->is_primary);
        $asset = $image?->mediaAsset;

        return [
            'name' => $this->localizedValue($product->name_ar, $product->name_en, $locale),
            'description' => $this->localizedValue($product->description_ar, $product->description_en, $locale),
            'imageAsset' => $asset,
        ];
    }

    /** @return array<string, mixed> */
    private function categoryPresentation(Category $category, string $locale): array
    {
        return [
            'name' => $this->localizedValue($category->name_ar, $category->name_en, $locale),
            'description' => $this->localizedValue($category->description_ar, $category->description_en, $locale),
            'imageAsset' => CategoryCoverPresenter::loadedCoverAsset($category),
        ];
    }

    private function localizedValue(?string $arabic, ?string $english, string $locale): ?string
    {
        if ($locale === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== '' ? $english : null);
    }

    /** @param Collection<int, SeoSetting> $settings */
    private function settingValue(Collection $settings, string $key): mixed
    {
        return $settings->first(static fn (SeoSetting $setting): bool => $setting->key === $key
            && $setting->locale === '')?->value_json;
    }
}
