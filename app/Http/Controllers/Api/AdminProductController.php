<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProductIndexRequest;
use App\Http\Requests\AdminProductRequest;
use App\Http\Resources\AdminProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellableItem;
use App\Services\ProductArchiveService;
use App\Services\CategoryHierarchyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function index(AdminProductIndexRequest $request)
    {
        $filters = $request->validated();
        $query = Product::query();

        if (($filters['include_archived'] ?? false) === true) {
            $query->withTrashed();
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['category_id'])) {
            $query->whereHas('categories', fn (Builder $category) => $category->whereKey($filters['category_id']));
        }
        if (isset($filters['featured'])) {
            $query->where('is_featured', (bool) $filters['featured']);
        }
        if (isset($filters['publication'])) {
            match ($filters['publication']) {
                'published' => $query->whereNotNull('published_at')->where('published_at', '<=', now()),
                'scheduled' => $query->whereNotNull('published_at')->where('published_at', '>', now()),
                'unpublished' => $query->whereNull('published_at'),
            };
        }
        if (! empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        $query->with(['categories' => fn (BelongsToMany $category) => $category->withTrashed()->orderByDesc('category_product.is_primary')->orderBy('categories.id')])
            ->withCount('sellableItems')
            ->withCount(['sellableItems as active_sellable_items_count' => fn (Builder $item) => $item->where('status', 'active')]);

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('name_en')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return AdminProductResource::collection(
            $query->paginate($filters['per_page'] ?? 20)->withQueryString(),
        );
    }

    public function store(AdminProductRequest $request): JsonResponse|AdminProductResource
    {
        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids'], $data['primary_category_id']);
        $data['slug'] ??= $this->generatedSlug($data['name_en'] ?? $data['name_ar']);
        $data['status'] ??= 'draft';
        $data['is_featured'] ??= false;
        $data['published_at'] ??= null;

        try {
            $product = DB::transaction(function () use ($data, $categoryIds, $request): Product {
                $this->assertCategoriesAvailable($categoryIds);
                $product = Product::create($data);
                if ($categoryIds !== null) {
                    $this->syncCategories($product, $categoryIds, $request->input('primary_category_id'));
                }
                $this->assertLifecycle($product);

                return $product;
            });
        } catch (QueryException $exception) {
            $this->convertSlugCollision($exception);
            throw $exception;
        }

        return (new AdminProductResource($this->loadProduct($product)))->response()->setStatusCode(201);
    }

    public function show(Product $product): AdminProductResource
    {
        return new AdminProductResource($this->loadProduct($product));
    }

    public function update(AdminProductRequest $request, Product $product): AdminProductResource
    {
        $data = $request->validated();
        $hasCategories = array_key_exists('category_ids', $data);
        $categoryIds = $data['category_ids'] ?? null;
        $primaryCategoryId = $data['primary_category_id'] ?? null;
        unset($data['category_ids'], $data['primary_category_id']);

        try {
            DB::transaction(function () use ($data, $hasCategories, $categoryIds, $primaryCategoryId, $product): void {
                $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                if ($hasCategories) {
                    $this->assertCategoriesAvailable($categoryIds);
                    $this->syncCategories($locked, $categoryIds, $primaryCategoryId);
                }
                $locked->fill($data);
                $locked->save();
                $this->assertLifecycle($locked);
            });
        } catch (QueryException $exception) {
            $this->convertSlugCollision($exception);
            throw $exception;
        }

        return new AdminProductResource($this->loadProduct($product->refresh()));
    }

    public function archive(Product $product, ProductArchiveService $archive): JsonResponse|AdminProductResource
    {
        $archive->archive($product);

        return new AdminProductResource($this->loadProduct($product->refresh()));
    }

    public function restore(int $product, ProductArchiveService $archive): JsonResponse|AdminProductResource
    {
        $result = $archive->restore($product);
        if (is_string($result)) {
            return response()->json([
                'code' => $result,
                'message' => 'The product is not archived.',
            ], 409);
        }

        return new AdminProductResource($this->loadProduct($result));
    }

    private function loadProduct(Product $product): Product
    {
        $product = $product->load([
            'categories' => fn (BelongsToMany $category) => $category->withTrashed()->orderBy('id'),
            'primaryProductImage.mediaAsset',
        ])->loadCount([
            'options as options_count',
            'sellableItems',
            'sellableItems as active_sellable_items_count' => fn (Builder $item) => $item->where('status', 'active'),
        ]);

        $summary = SellableItem::query()
            ->where('product_id', $product->getKey())
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->selectRaw('MIN(price) AS active_min_price')
            ->selectRaw('MAX(price) AS active_max_price')
            ->selectRaw('COALESCE(SUM(stock_quantity), 0) AS active_stock_total')
            ->selectRaw('COALESCE(SUM(reserved_quantity), 0) AS active_stock_reserved')
            ->first();

        return $product->setAttribute('active_min_price', $summary?->active_min_price)
            ->setAttribute('active_max_price', $summary?->active_max_price)
            ->setAttribute('active_stock_total', (int) ($summary?->active_stock_total ?? 0))
            ->setAttribute('active_stock_reserved', (int) ($summary?->active_stock_reserved ?? 0));
    }

    private function syncCategories(Product $product, array $categoryIds, mixed $primaryCategoryId): void
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds === []) {
            $product->categories()->sync([]);

            return;
        }
        $primary = $primaryCategoryId === null
            ? ($product->categories()->wherePivot('is_primary', true)->whereIn('categories.id', $categoryIds)->value('categories.id') ?? $categoryIds[0])
            : (int) $primaryCategoryId;

        if (! in_array($primary, $categoryIds, true)) {
            throw ValidationException::withMessages([
                'primary_category_id' => 'The primary category must be included in category_ids.',
            ]);
        }

        $product->categories()->sync(collect($categoryIds)->mapWithKeys(
            fn (int $id): array => [$id => ['is_primary' => $id === $primary]],
        )->all());
    }

    private function assertCategoriesAvailable(?array $categoryIds): void
    {
        if ($categoryIds === null) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        $visibleIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();
        $available = Category::query()->whereIn('id', $ids)->whereIn('id', $visibleIds)
            ->whereNull('deleted_at')->where('status', 'active')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($available) !== count($ids)) {
            throw ValidationException::withMessages([
                'category_ids' => 'One or more selected categories are unavailable.',
            ]);
        }
    }

    private function assertLifecycle(Product $product): void
    {
        if ($product->status !== 'active' && $product->published_at === null) {
            return;
        }

        if (! $product->categories()->where('categories.status', 'active')->exists()) {
            throw ValidationException::withMessages(['status' => 'An active product must have an active category.']);
        }
        if (! $product->sellableItems()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['status' => 'An active product must have an active sellable item.']);
        }
    }

    private function applySearch(Builder $query, string $search): void
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $pattern = "%{$escaped}%";
        $query->where(function (Builder $nested) use ($operator, $pattern): void {
            foreach (['name_ar', 'name_en', 'slug', 'description_ar', 'description_en'] as $field) {
                $method = $field === 'name_ar' ? 'whereRaw' : 'orWhereRaw';
                $nested->{$method}("{$field} {$operator} ? ESCAPE '\\'", [$pattern]);
            }
        });
    }

    private function generatedSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $suffix = 2;
        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function convertSlugCollision(QueryException $exception): void
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'products.slug') || str_contains($message, 'products_slug_unique')) {
            throw ValidationException::withMessages(['slug' => 'The slug has already been taken.']);
        }
    }
}
