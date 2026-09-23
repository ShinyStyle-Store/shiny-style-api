<?php

namespace App\Services;

use App\Models\SellableItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

final class AdminProductSummaryQuery
{
    public function apply(Builder $products): Builder
    {
        $products->selectSub($this->activeVariants()->selectRaw('MIN(price)'), 'active_min_price')
            ->selectSub($this->activeVariants()->selectRaw('MAX(price)'), 'active_max_price')
            ->selectSub($this->activeVariants()->selectRaw('COALESCE(SUM(stock_quantity), 0)'), 'active_stock_total')
            ->selectSub($this->activeVariants()->selectRaw(
                'COALESCE(SUM(CASE WHEN stock_quantity > reserved_quantity THEN stock_quantity - reserved_quantity ELSE 0 END), 0)',
            ), 'active_stock_available')
            ->selectSub($this->activeVariants()->selectRaw('COALESCE(SUM(reserved_quantity), 0)'), 'active_stock_reserved');

        return $products;
    }

    public function hydrate(Product $product): Product
    {
        $summary = $this->activeVariants(false)
            ->where('product_id', $product->getKey())
            ->selectRaw('MIN(price) AS active_min_price')
            ->selectRaw('MAX(price) AS active_max_price')
            ->selectRaw('COALESCE(SUM(stock_quantity), 0) AS active_stock_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN stock_quantity > reserved_quantity THEN stock_quantity - reserved_quantity ELSE 0 END), 0) AS active_stock_available')
            ->selectRaw('COALESCE(SUM(reserved_quantity), 0) AS active_stock_reserved')
            ->first();

        return $product
            ->setAttribute('active_min_price', $summary?->active_min_price)
            ->setAttribute('active_max_price', $summary?->active_max_price)
            ->setAttribute('active_stock_total', (int) ($summary?->active_stock_total ?? 0))
            ->setAttribute('active_stock_available', (int) ($summary?->active_stock_available ?? 0))
            ->setAttribute('active_stock_reserved', (int) ($summary?->active_stock_reserved ?? 0));
    }

    private function activeVariants(bool $correlated = true): Builder
    {
        $query = SellableItem::query()->where('status', 'active');

        if ($correlated) {
            $query->whereColumn('sellable_items.product_id', 'products.id');
        }

        return $query;
    }
}
