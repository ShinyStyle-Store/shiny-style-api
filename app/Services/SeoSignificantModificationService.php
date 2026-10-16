<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SellableItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Writes timestamps for public changes that no surviving source row can describe. */
final class SeoSignificantModificationService
{
    public function mark(Model $entity, ?CarbonImmutable $at = null, ?string $locale = null, bool $notify = true): void
    {
        if (! $entity instanceof Product && ! $entity instanceof Category && ! $entity instanceof ContentPage) {
            throw new InvalidArgumentException('Unsupported SEO significant modification target.');
        }

        if ($locale !== null) {
            $this->markLocales($entity, [$locale], $at, $notify);

            return;
        }

        DB::table($entity->getTable())
            ->where($entity->getKeyName(), $entity->getKey())
            ->update(['seo_last_significant_modified_at' => ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString()]);

        if ($notify) {
            $this->notifyEntity($entity, ['ar', 'en']);
        }
    }

    /** @param list<string> $locales */
    public function markLocales(Model $entity, array $locales, ?CarbonImmutable $at = null, bool $notify = true): void
    {
        if (! $entity instanceof Product && ! $entity instanceof Category && ! $entity instanceof ContentPage) {
            throw new InvalidArgumentException('Unsupported SEO significant modification target.');
        }

        $timestamp = ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString();
        $values = [];
        foreach (array_unique($locales) as $locale) {
            $values['seo_last_significant_modified_at_'.$this->supportedLocale($locale)] = $timestamp;
        }
        if ($values !== []) {
            DB::table($entity->getTable())->where($entity->getKeyName(), $entity->getKey())->update($values);
            if ($notify) {
                $this->notifyEntity($entity, array_values(array_unique($locales)));
            }
        }
    }

    /** @param list<int> $productIds */
    public function markProducts(array $productIds, ?CarbonImmutable $at = null, bool $notify = true): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }

        DB::table('products')->whereIn('id', $ids)->update([
            'seo_last_significant_modified_at' => ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString(),
        ]);

        if ($notify) {
            $visibleCategoryIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();
            $visibleProductIds = Product::query()
                ->visible($visibleCategoryIds)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            foreach (Product::query()->whereIn('id', $visibleProductIds)->get() as $product) {
                $this->notifyEntity($product, ['ar', 'en'], knownPublic: true);
            }
        }
    }

    public function markInventoryAvailabilityTransition(SellableItem $item, int $previousAvailableQuantity): void
    {
        $this->markInventoryAvailabilityTransitions([[
            'item' => $item,
            'previousAvailableQuantity' => $previousAvailableQuantity,
        ]]);
    }

    /** @param list<array{item: SellableItem, previousAvailableQuantity: int}> $transitions */
    public function markInventoryAvailabilityTransitions(array $transitions): void
    {
        $productIds = [];
        foreach ($transitions as $transition) {
            if (($transition['previousAvailableQuantity'] > 0) !== ($transition['item']->availableQuantity() > 0)) {
                $productIds[] = (int) $transition['item']->product_id;
            }
        }

        $this->markProducts($productIds);
    }

    /** @param list<int> $categoryIds */
    public function markCategories(array $categoryIds, ?CarbonImmutable $at = null, bool $notify = true): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }

        DB::table('categories')->whereIn('id', $ids)->update([
            'seo_last_significant_modified_at' => ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString(),
        ]);

        if ($notify) {
            $visibleIds = array_fill_keys(
                app(CategoryHierarchyService::class)->visibilitySnapshot()->visibleIds(),
                true,
            );
            foreach (Category::query()->whereIn('id', array_intersect($ids, array_keys($visibleIds)))->get() as $category) {
                $this->notifyEntity($category, ['ar', 'en'], knownPublic: true);
            }
        }
    }

    /** @param list<array{type: string, id: int, locale: string}> $targets */
    public function markSeoTargets(array $targets, ?CarbonImmutable $at = null): void
    {
        $tables = [
            'product' => 'products',
            'category' => 'categories',
            'content_page' => 'content_pages',
        ];
        $idsByTable = [];
        foreach ($targets as $target) {
            $table = $tables[$target['type']] ?? null;
            if ($table !== null && $target['id'] > 0) {
                $idsByTable[$table][$this->supportedLocale($target['locale'])][] = $target['id'];
            }
        }

        foreach ($idsByTable as $table => $locales) {
            foreach ($locales as $locale => $ids) {
                DB::table($table)->whereIn('id', array_unique($ids))->update([
                    'seo_last_significant_modified_at_'.$locale => ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString(),
                ]);
            }
        }
    }

    private function supportedLocale(string $locale): string
    {
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported SEO significant modification locale.');
        }

        return $locale;
    }

    /** @param list<string> $locales */
    private function notifyEntity(Model $entity, array $locales, bool $knownPublic = false): void
    {
        $scopes = match (true) {
            $entity instanceof Product => ['catalog', 'sitemap', 'metaFeed'],
            $entity instanceof Category => ['catalog', 'sitemap'],
            $entity instanceof ContentPage => ['content', 'sitemap'],
            default => [],
        };

        if ($scopes !== [] && ($knownPublic || $this->isPubliclyVisible($entity))) {
            app(SeoRevalidationService::class)->afterCommitForEntity(
                $entity,
                'updated',
                $locales,
                $scopes,
            );
        }
    }

    private function isPubliclyVisible(Model $entity): bool
    {
        return match (true) {
            $entity instanceof Product => Product::query()
                ->visible(app(CategoryHierarchyService::class)->effectiveVisibleIds())
                ->whereKey($entity->getKey())
                ->exists(),
            $entity instanceof Category => app(CategoryHierarchyService::class)
                ->visibilitySnapshot()
                ->contains($entity),
            $entity instanceof ContentPage => ContentPage::query()
                ->whereKey($entity->getKey())
                ->whereIn('slug', config('content.page_slugs', []))
                ->exists(),
            default => false,
        };
    }
}
