<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductListResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryHierarchyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request, CategoryHierarchyService $hierarchy)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

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

                $query->distinct()->whereIn('products.id', $matchingProductIds);
            }
        }

        $products = $query
            ->with($this->listingRelations($visibleCategoryIds))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 24)
            ->withQueryString();

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

    public function featured(CategoryHierarchyService $hierarchy)
    {
        $visibleCategoryIds = $hierarchy->effectiveVisibleIds();
        $products = Product::query()
            ->visible($visibleCategoryIds)
            ->featured()
            ->with($this->listingRelations($visibleCategoryIds))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return ProductListResource::collection($products);
    }

    public function show(string $slug, CategoryHierarchyService $hierarchy)
    {
        $visibleCategoryIds = $hierarchy->effectiveVisibleIds();
        $product = Product::query()
            ->visible($visibleCategoryIds)
            ->where('slug', $slug)
            ->with([
                ...$this->listingRelations($visibleCategoryIds, true),
                'options' => fn ($query) => $query->ordered(),
                'options.values' => fn ($query) => $query->ordered(),
                'sellableItems.optionValues.option',
                'sellableItems.variantVideos.mediaAsset',
                'productVideos.mediaAsset',
            ])
            ->firstOrFail();

        return new ProductDetailResource($product);
    }

    private function listingRelations(array $visibleCategoryIds, bool $includeVariantImages = false): array
    {
        $relations = [
            'categories' => fn ($query) => $query->whereIn('categories.id', $visibleCategoryIds)->ordered(),
            'sellableItems' => fn ($query) => $query->active()->orderBy('id'),
            'productImages.mediaAsset',
        ];

        if ($includeVariantImages) {
            $relations['sellableItems'] = fn ($query) => $query->active()->orderBy('id')
                ->with(['variantImages.mediaAsset']);
        }

        return $relations;
    }
}
