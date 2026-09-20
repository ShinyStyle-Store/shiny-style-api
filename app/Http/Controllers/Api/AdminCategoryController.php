<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidCategoryHierarchyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminCategoryRequest;
use App\Http\Resources\AdminCategoryResource;
use App\Models\Category;
use App\Services\CategoryHierarchyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCategoryController extends Controller
{
    public function index(CategoryHierarchyService $hierarchy)
    {
        $categories = Category::query()
            ->withCount([
                'children',
                'products' => fn ($query) => $query->withTrashed(),
            ])
            ->ordered()
            ->get();

        foreach ($hierarchy->depths($categories) as $id => $depth) {
            $categories->firstWhere('id', $id)?->setAttribute('hierarchy_depth', $depth);
        }

        return AdminCategoryResource::collection($categories);
    }

    public function show(Category $category, CategoryHierarchyService $hierarchy): AdminCategoryResource
    {
        $category->loadCount([
            'children',
            'products' => fn ($query) => $query->withTrashed(),
        ]);
        $category->setAttribute('hierarchy_depth', $hierarchy->depth($category));

        return new AdminCategoryResource($category);
    }

    public function store(AdminCategoryRequest $request, CategoryHierarchyService $hierarchy)
    {
        $data = $request->validated();

        try {
            $category = DB::transaction(function () use ($data, $hierarchy): Category {
                $parent = isset($data['parent_id'])
                    ? Category::query()->whereKey($data['parent_id'])->lockForUpdate()->first()
                    : null;

                if (isset($data['parent_id']) && $parent === null) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'The selected parent category is unavailable.',
                    ]);
                }

                try {
                    $hierarchy->assertCanAssignToParent(null, $parent);
                } catch (InvalidCategoryHierarchyException) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'The selected parent is not valid for this category.',
                    ]);
                }

                return Category::query()->create($data);
            });
        } catch (QueryException $exception) {
            $this->convertSlugCollision($exception);
            throw $exception;
        }

        $this->loadResourceFields($category, $hierarchy);

        return (new AdminCategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(
        AdminCategoryRequest $request,
        Category $category,
        CategoryHierarchyService $hierarchy,
    ): AdminCategoryResource {
        $data = $request->validated();

        try {
            $category = DB::transaction(function () use ($category, $data, $hierarchy): Category {
                $locked = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

                if (array_key_exists('parent_id', $data)) {
                    $parent = $data['parent_id'] === null
                        ? null
                        : Category::query()->whereKey($data['parent_id'])->lockForUpdate()->first();

                    if ($data['parent_id'] !== null && $parent === null) {
                        throw ValidationException::withMessages([
                            'parent_id' => 'The selected parent category is unavailable.',
                        ]);
                    }

                    try {
                        $hierarchy->assertCanAssignToParent($locked, $parent);
                    } catch (InvalidCategoryHierarchyException) {
                        throw ValidationException::withMessages([
                            'parent_id' => 'The selected parent would create an invalid category hierarchy.',
                        ]);
                    }
                }

                $locked->fill($data);
                $locked->save();

                return $locked;
            });
        } catch (QueryException $exception) {
            $this->convertSlugCollision($exception);
            throw $exception;
        }

        $this->loadResourceFields($category, $hierarchy);

        return new AdminCategoryResource($category);
    }

    public function destroy(Category $category): JsonResponse|Response
    {
        $conflict = DB::transaction(function () use ($category): ?string {
            $locked = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->children()->exists()) {
                return 'category_has_children';
            }

            if (DB::table('category_product')->where('category_id', $locked->getKey())->exists()) {
                return 'category_has_products';
            }

            $locked->delete();

            return null;
        });

        if ($conflict === 'category_has_children') {
            return response()->json([
                'code' => $conflict,
                'message' => 'Move or delete child categories before deleting this category.',
            ], 409);
        }

        if ($conflict === 'category_has_products') {
            return response()->json([
                'code' => $conflict,
                'message' => 'Reassign linked products before deleting this category.',
            ], 409);
        }

        return response()->noContent();
    }

    private function loadResourceFields(Category $category, CategoryHierarchyService $hierarchy): void
    {
        $category->loadCount([
            'children',
            'products' => fn ($query) => $query->withTrashed(),
        ]);
        $category->setAttribute('hierarchy_depth', $hierarchy->depth($category));
    }

    private function convertSlugCollision(QueryException $exception): void
    {
        $message = strtolower($exception->getMessage());
        $slugViolation = str_contains($message, 'categories.slug')
            || (str_contains($message, 'categories_slug_unique') && str_contains($message, 'unique'));

        if ($slugViolation) {
            throw ValidationException::withMessages([
                'slug' => 'The slug has already been taken.',
            ]);
        }
    }
}
