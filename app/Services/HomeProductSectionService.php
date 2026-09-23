<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class HomeProductSectionService
{
    public function __construct(private readonly CategoryHierarchyService $hierarchy) {}

    /** @return array{featured: Collection<int, Product>, bestSelling: Collection<int, Product>, newest: Collection<int, Product>, offers: list<mixed>} */
    public function sections(int $limit): array
    {
        $visibleCategoryIds = $this->hierarchy->effectiveVisibleIds();

        return [
            'featured' => $this->featured($limit, $visibleCategoryIds),
            'bestSelling' => $this->bestSelling($limit, $visibleCategoryIds),
            'newest' => $this->newest($limit, $visibleCategoryIds),
            'offers' => [],
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

    public function paginateSection(string $section, int $perPage, int $page = 1): LengthAwarePaginator
    {
        $visibleCategoryIds = $this->hierarchy->effectiveVisibleIds();

        $paginator = match ($section) {
            'featured' => $this->sectionQuery($visibleCategoryIds, 'featured')
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page),
            'newest' => $this->sectionQuery($visibleCategoryIds, 'newest')
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page),
            'best-selling' => $this->bestSellingQuery($visibleCategoryIds)
                ->paginate($perPage, ['*'], 'page', $page),
            'offers' => Product::query()->whereRaw('1 = 0')
                ->paginate($perPage, ['*'], 'page', $page),
            default => throw new \InvalidArgumentException('Unsupported product section.'),
        };

        return $paginator->withQueryString();
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
