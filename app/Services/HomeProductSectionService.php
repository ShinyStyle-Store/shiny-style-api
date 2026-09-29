<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class HomeProductSectionService
{
    public function __construct(private readonly CategoryHierarchyService $hierarchy) {}

    /** @return array{featured: Collection<int, Product>, bestSelling: Collection<int, Product>, newest: Collection<int, Product>, offers: Collection<int, Product>} */
    public function sections(int $limit, ?CarbonImmutable $at = null): array
    {
        $visibleCategoryIds = $this->hierarchy->effectiveVisibleIds();
        $instant = ($at ?? CarbonImmutable::now())->utc();

        return [
            'featured' => $this->featured($limit, $visibleCategoryIds),
            'bestSelling' => $this->bestSelling($limit, $visibleCategoryIds),
            'newest' => $this->newest($limit, $visibleCategoryIds),
            'offers' => $this->offers($limit, $visibleCategoryIds, $instant),
        ];
    }

    /** @param list<int>|null $visibleCategoryIds @return Collection<int, Product> */
    public function featured(int $limit, ?array $visibleCategoryIds = null): Collection
    {
        return $this->baseQuery($visibleCategoryIds)->featured()
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** @param list<int>|null $visibleCategoryIds @return Collection<int, Product> */
    public function newest(int $limit, ?array $visibleCategoryIds = null): Collection
    {
        return $this->baseQuery($visibleCategoryIds)
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** @param list<int>|null $visibleCategoryIds @return Collection<int, Product> */
    public function bestSelling(int $limit, ?array $visibleCategoryIds = null): Collection
    {
        return $this->bestSellingQuery($visibleCategoryIds)->limit($limit)->get();
    }

    public function paginateSection(string $section, int $perPage, int $page = 1, ?CarbonImmutable $at = null): LengthAwarePaginator
    {
        $visibleCategoryIds = $this->hierarchy->effectiveVisibleIds();
        $instant = ($at ?? CarbonImmutable::now())->utc();

        $paginator = match ($section) {
            'featured' => $this->sectionQuery($visibleCategoryIds, 'featured')
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page),
            'newest' => $this->sectionQuery($visibleCategoryIds, 'newest')
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page),
            'best-selling' => $this->bestSellingQuery($visibleCategoryIds)
                ->paginate($perPage, ['*'], 'page', $page),
            'offers' => $this->baseQuery($visibleCategoryIds)
                ->whereHas('offers', fn ($query) => $query
                    ->where('is_enabled', true)
                    ->where('starts_at', '<=', $instant)
                    ->where('ends_at', '>', $instant))
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page),
            default => throw new \InvalidArgumentException('Unsupported product section.'),
        };

        return $paginator->withQueryString();
    }

    /** @param list<int> $visibleCategoryIds @return Collection<int, Product> */
    private function offers(int $limit, array $visibleCategoryIds, CarbonImmutable $at): Collection
    {
        return $this->baseQuery($visibleCategoryIds)
            ->whereHas('offers', fn ($query) => $query
                ->where('is_enabled', true)
                ->where('starts_at', '<=', $at)
                ->where('ends_at', '>', $at))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @param list<int>|null $visibleCategoryIds @return Builder */
    private function bestSellingQuery(?array $visibleCategoryIds): Builder
    {
        $sales = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->whereNotNull('order_items.product_id')
            ->select('order_items.product_id')
            ->selectRaw('SUM(order_items.quantity) AS sold_quantity')
            ->groupBy('order_items.product_id');

        return $this->baseQuery($visibleCategoryIds)
            ->joinSub($sales, 'product_sales', fn ($join) => $join->on('products.id', '=', 'product_sales.product_id'))
            ->select('products.*')->selectRaw('product_sales.sold_quantity')
            ->orderByDesc('product_sales.sold_quantity')->orderByDesc('products.id');
    }

    /** @param list<int> $visibleCategoryIds @return Builder */
    private function sectionQuery(array $visibleCategoryIds, string $section): Builder
    {
        $query = $this->baseQuery($visibleCategoryIds);

        if ($section === 'featured') {
            $query->featured();
        }

        return $query;
    }

    /** @param list<int>|null $visibleCategoryIds */
    public function cardRelations(?array $visibleCategoryIds = null, bool $includeVariantImages = false): array
    {
        $visibleCategoryIds ??= $this->hierarchy->effectiveVisibleIds();
        $relations = [
            'categories' => fn ($query) => $query->whereIn('categories.id', $visibleCategoryIds)->ordered(),
            'sellableItems' => fn ($query) => $query->active()->orderBy('id'),
            'productImages.mediaAsset',
        ];

        if ($includeVariantImages) {
            $relations['sellableItems'] = fn ($query) => $query->active()->orderBy('id')->with(['variantImages.mediaAsset']);
        }

        return $relations;
    }

    /** @param list<int>|null $visibleCategoryIds */
    private function baseQuery(?array $visibleCategoryIds): Builder
    {
        $visibleCategoryIds ??= $this->hierarchy->effectiveVisibleIds();

        return Product::query()->visible($visibleCategoryIds)->with($this->cardRelations($visibleCategoryIds));
    }
}
