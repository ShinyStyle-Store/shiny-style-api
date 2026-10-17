<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductColorCatalogService
{
    /** @return array{colors: list<array{code: string, nameAr: string, nameEn: string}>, conflicts: list<array{code: string, translations: list<array{nameAr: string, nameEn: string}>}>} */
    public function adminSuggestions(): array
    {
        $rows = $this->baseQuery()
            ->join('products', 'products.id', '=', 'product_options.product_id')
            ->whereNull('products.deleted_at')
            ->select('product_option_values.code', 'product_option_values.value_ar', 'product_option_values.value_en')
            ->distinct()
            ->orderBy('product_option_values.code')
            ->orderBy('product_option_values.value_en')
            ->orderBy('product_option_values.value_ar')
            ->get();

        return $this->present($rows);
    }

    /** @param list<int> $visibleCategoryIds
     *  @return array{colors: list<array{code: string, name: string}>, conflicts: list<array{code: string, translations: list<array{nameAr: string, nameEn: string}>}>}
     */
    public function publicFilterOptions(array $visibleCategoryIds, string $locale): array
    {
        if ($visibleCategoryIds === []) {
            return ['colors' => [], 'conflicts' => []];
        }

        $rows = $this->baseQuery()
            ->join('products', 'products.id', '=', 'product_options.product_id')
            ->join('sellable_item_option_values as color_assignments', 'color_assignments.product_option_value_id', '=', 'product_option_values.id')
            ->join('sellable_items', 'sellable_items.id', '=', 'color_assignments.sellable_item_id')
            ->whereColumn('sellable_items.product_id', 'products.id')
            ->where('products.status', 'active')
            ->whereNotNull('products.published_at')
            ->where('products.published_at', '<=', now())
            ->whereNull('products.deleted_at')
            ->where('sellable_items.status', 'active')
            ->whereNull('sellable_items.deleted_at')
            ->whereNull('sellable_items.superseded_at')
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('category_product')
                ->whereColumn('category_product.product_id', 'products.id')
                ->whereIn('category_product.category_id', $visibleCategoryIds))
            ->select('product_option_values.code', 'product_option_values.value_ar', 'product_option_values.value_en')
            ->distinct()
            ->orderBy('product_option_values.code')
            ->orderBy('product_option_values.value_en')
            ->orderBy('product_option_values.value_ar')
            ->get();

        $presented = $this->present($rows);
        if ($presented['conflicts'] !== []) {
            return ['colors' => [], 'conflicts' => $presented['conflicts']];
        }

        return [
            'colors' => array_map(fn (array $color): array => [
                'code' => $color['code'],
                'name' => $this->localizedName($color['nameAr'], $color['nameEn'], $locale),
            ], $presented['colors']),
            'conflicts' => [],
        ];
    }

    private function baseQuery(): Builder
    {
        return DB::table('product_option_values')
            ->join('product_options', 'product_options.id', '=', 'product_option_values.product_option_id')
            ->where('product_options.code', 'color')
            ->whereNull('product_options.deleted_at')
            ->whereNull('product_option_values.deleted_at');
    }

    /** @param Collection<int, object> $rows
     *  @return array{colors: list<array{code: string, nameAr: string, nameEn: string}>, conflicts: list<array{code: string, translations: list<array{nameAr: string, nameEn: string}>}>}
     */
    private function present(Collection $rows): array
    {
        $byCode = $rows->groupBy('code');
        $colors = [];
        $conflicts = [];

        foreach ($byCode as $code => $translations) {
            $pairs = $translations->map(fn (object $row): array => [
                'nameAr' => (string) $row->value_ar,
                'nameEn' => (string) $row->value_en,
            ])->unique(fn (array $pair): string => json_encode([
                $pair['nameAr'],
                mb_strtolower($pair['nameEn']),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                ->sortBy(fn (array $pair): string => mb_strtolower($pair['nameEn']).'|'.$pair['nameAr'])
                ->values();

            $isCanonicalCode = preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $code) === 1;
            $hasBlankTranslation = $pairs->contains(static fn (array $pair): bool => trim($pair['nameAr']) === ''
                || trim($pair['nameEn']) === '');

            if (! $isCanonicalCode || $hasBlankTranslation || $pairs->count() > 1) {
                $conflicts[] = ['code' => (string) $code, 'translations' => $pairs->all()];
                continue;
            }

            $pair = $pairs->first();
            if ($pair !== null) {
                $colors[] = ['code' => (string) $code, ...$pair];
            }
        }

        usort($colors, static fn (array $left, array $right): int => strcmp($left['code'], $right['code']));

        return ['colors' => $colors, 'conflicts' => $conflicts];
    }

    private function localizedName(string $arabic, string $english, string $locale): string
    {
        if ($locale === 'en') {
            return $english !== '' ? $english : $arabic;
        }

        return $arabic !== '' ? $arabic : $english;
    }
}
