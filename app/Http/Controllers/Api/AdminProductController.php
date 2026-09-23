<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProductIndexRequest;
use App\Http\Requests\AdminProductRequest;
use App\Http\Resources\AdminProductResource;
use App\Models\Product;
use App\Services\AdminProductCreationService;
use App\Services\AdminProductUpdateService;
use App\Services\AdminProductSummaryQuery;
use App\Services\ProductArchiveService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function __construct(private readonly AdminProductSummaryQuery $summaryQuery) {}

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

        $query->with([
            'categories' => fn (BelongsToMany $category) => $category->withTrashed()->orderByDesc('category_product.is_primary')->orderBy('categories.id'),
            'primaryProductImage.mediaAsset',
        ])
            ->withCount('sellableItems')
            ->withCount(['sellableItems as active_sellable_items_count' => fn (Builder $item) => $item->where('status', 'active')]);
        $this->summaryQuery->apply($query);

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
        $primaryCategoryId = $data['primary_category_id'] ?? null;
        $primaryImage = $data['primary_image'] ?? null;
        $galleryImages = $data['gallery_images'] ?? [];
        unset($data['category_ids'], $data['primary_category_id'], $data['primary_image'], $data['gallery_images']);
        $data['slug'] ??= $this->generatedSlug($data['name_en'] ?? $data['name_ar']);
        $data['status'] ??= 'draft';
        $data['is_featured'] ??= false;
        $data['published_at'] ??= null;

        try {
            $product = app(AdminProductCreationService::class)->create(
                $data,
                $categoryIds,
                $primaryCategoryId,
                $primaryImage,
                $galleryImages,
                $request->user(),
            );
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
        $mediaCommand = [];
        foreach (['primary_image', 'gallery_images', 'primary_attachment_id', 'remove_attachment_ids'] as $field) {
            if (array_key_exists($field, $data)) {
                $mediaCommand[$field] = $data[$field];
                unset($data[$field]);
            }
        }
        unset($data['category_ids'], $data['primary_category_id']);

        try {
            app(AdminProductUpdateService::class)->update(
                $product,
                $data,
                $hasCategories,
                $categoryIds,
                $primaryCategoryId,
                $mediaCommand,
                $request->user(),
            );
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

        return $this->summaryQuery->hydrate($product);
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
