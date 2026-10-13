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
    public function mark(Model $entity, ?CarbonImmutable $at = null, ?string $locale = null): void
    {
        if (! $entity instanceof Product && ! $entity instanceof Category && ! $entity instanceof ContentPage) {
            throw new InvalidArgumentException('Unsupported SEO significant modification target.');
        }

        if ($locale !== null) {
            $this->markLocales($entity, [$locale], $at);

            return;
        }

        DB::table($entity->getTable())
            ->where($entity->getKeyName(), $entity->getKey())
            ->update(['seo_last_significant_modified_at' => ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString()]);
    }

    /** @param list<string> $locales */
    public function markLocales(Model $entity, array $locales, ?CarbonImmutable $at = null): void
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
        }
    }

    /** @param list<int> $productIds */
    public function markProducts(array $productIds, ?CarbonImmutable $at = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }

        DB::table('products')->whereIn('id', $ids)->update([
            'seo_last_significant_modified_at' => ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString(),
        ]);
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
    public function markCategories(array $categoryIds, ?CarbonImmutable $at = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }

        DB::table('categories')->whereIn('id', $ids)->update([
            'seo_last_significant_modified_at' => ($at ?? CarbonImmutable::now('UTC'))->utc()->toDateTimeString(),
        ]);
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
}
