<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductCatalogIndexRequest;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductListResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryHierarchyService;
use App\Services\CatalogProductQueryService;
use App\Services\HomeProductSectionService;
use App\Services\OfferPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        private readonly HomeProductSectionService $sections,
        private readonly OfferPricingService $pricing,
        private readonly CatalogProductQueryService $catalogQuery,
    ) {}

    public function index(ProductCatalogIndexRequest $request, CategoryHierarchyService $hierarchy)
    {
        $validated = $request->validated();
        $instant = CarbonImmutable::now()->utc();

        $visibility = $hierarchy->visibilitySnapshot();
        $visibleCategoryIds = $visibility->visibleIds();
        $query = Product::query()->visible($visibleCategoryIds);
        $this->applySearch($query, $this->searchWords($validated['q'] ?? null));
        $category = trim($validated['category'] ?? '');

        if ($category !== '') {
            $requestedCategory = Category::query()
                ->whereIn('id', $visibleCategoryIds)
                ->where('slug', $category)
                ->first();

            if ($requestedCategory === null) {
                $query->whereRaw('1 = 0');
            } else {
                $matchingCategoryIds = $visibility->visibleSubtreeIds($requestedCategory->getKey());
                $matchingProductIds = DB::table('category_product')
                    ->select('product_id')
                    ->whereIn('category_id', $matchingCategoryIds)
                    ->distinct();

                $query->whereIn('products.id', $matchingProductIds);
            }
        }

        $products = $this->catalogQuery->apply($query, $validated, $instant)
            ->with($this->sections->cardRelations($visibleCategoryIds))
            ->paginate($validated['per_page'] ?? 24)
            ->withQueryString();

        $this->pricing->attachToProducts($products->getCollection(), $instant);

        return ProductListResource::collection($products);
    }

    private function searchWords(?string $search): array
    {
        if ($search === null) {
            return [];
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($search));

        if ($normalized === '' || $normalized === null) {
            return [];
        }

        return array_values(array_filter(
            explode(' ', Str::lower($normalized)),
            static fn (string $word): bool => $word !== '',
        ));
    }

    private function applySearch(Builder $query, array $words): void
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';

        foreach ($words as $word) {
            $escapedWord = str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                $word,
            );
            $pattern = "%{$escapedWord}%";

            $query->where(function (Builder $query) use ($operator, $pattern): void {
                $query->whereRaw("name_ar {$operator} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("name_en {$operator} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("slug {$operator} ? ESCAPE '\\'", [$pattern]);
            });
        }
    }

    public function show(string $slug, CategoryHierarchyService $hierarchy)
    {
        $instant = CarbonImmutable::now()->utc();
        $visibility = $hierarchy->visibilitySnapshot(true, false);
        $visibleCategoryIds = $visibility->visibleIds();
        $product = Product::query()
            ->visible($visibleCategoryIds)
            ->where('slug', $slug)
            ->with([
                ...$this->sections->cardRelations($visibleCategoryIds, true),
                'seoRecords.ogImage',
                'options' => fn ($query) => $query->ordered(),
                'options.values' => fn ($query) => $query->ordered(),
                'sellableItems.optionValues.option',
                'sellableItems.variantVideos.mediaAsset',
                'productVideos.mediaAsset',
            ])
            ->firstOrFail();

        $product->setAttribute(
            'seo_related_categories',
            $visibility->ancestorsAndSelfFor($product->categories->modelKeys()),
        );
        $primaryCategory = $product->primaryCategory($product->categories);
        $product->setAttribute(
            'seo_product_category_breadcrumbs',
            $primaryCategory === null ? [] : $visibility->breadcrumbs($primaryCategory),
        );

        $this->pricing->attachToProducts(collect([$product]), $instant);

        return new ProductDetailResource($product);
    }

}
