<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidCategoryHierarchyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminCategoryRequest;
use App\Http\Resources\AdminCategoryResource;
use App\Http\Resources\ArchivedCategoryResource;
use App\Models\Category;
use App\Services\CategoryArchiveService;
use App\Services\CategoryCoverService;
use App\Services\CategoryHierarchyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminCategoryController extends Controller
{
    public function index(CategoryHierarchyService $hierarchy)
    {
        $categories = Category::query()->ordered()->get();
        $hierarchy->prepareAdminCategories($categories);

        return AdminCategoryResource::collection($categories);
    }

    public function archived(CategoryHierarchyService $hierarchy): AnonymousResourceCollection
    {
        $categories = Category::onlyTrashed()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $hierarchy->prepareAdminCategories($categories);

        return ArchivedCategoryResource::collection($categories);
    }

    public function show(Category $category, CategoryHierarchyService $hierarchy): AdminCategoryResource
    {
        $hierarchy->prepareAdminCategories([$category]);

        return new AdminCategoryResource($category);
    }

    public function store(
        AdminCategoryRequest $request,
        CategoryHierarchyService $hierarchy,
        CategoryCoverService $covers,
    ): JsonResponse {
        $data = $request->validated();
        $image = $data['cover_image'] ?? null;
        unset($data['_method'], $data['cover_image'], $data['remove_cover_image']);
        if (isset($data['parent_id'])) {
            $preflightParent = Category::query()->whereKey($data['parent_id'])->first();
            if ($preflightParent === null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent category is unavailable.',
                ]);
            }
            try {
                $hierarchy->assertCanAssignToParent(null, $preflightParent);
            } catch (InvalidCategoryHierarchyException) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent is not valid for this category.',
                ]);
            }
        }
        $asset = $covers->upload($image, $request->user());

        try {
            $category = DB::transaction(function () use ($data, $hierarchy, $covers, $asset): Category {
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

                $category = Category::query()->create($data);
                if ($asset !== null) {
                    $covers->changeInTransaction($category, $asset, remove: false);
                }

                return $category;
            });
        } catch (Throwable $exception) {
            $covers->discardNewAsset($asset);
            if ($exception instanceof QueryException) {
                $this->convertSlugCollision($exception);
            }
            throw $exception;
        }

        $this->loadResourceFields($category, $hierarchy);

        return (new AdminCategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(
        AdminCategoryRequest $request,
        Category $category,
        CategoryHierarchyService $hierarchy,
        CategoryCoverService $covers,
    ): AdminCategoryResource {
        $validated = $request->validated();
        $image = $validated['cover_image'] ?? null;
        $remove = $request->boolean('remove_cover_image');
        unset($validated['_method'], $validated['cover_image'], $validated['remove_cover_image']);
        if (array_key_exists('parent_id', $validated)) {
            $preflightParent = $validated['parent_id'] === null
                ? null
                : Category::query()->whereKey($validated['parent_id'])->first();
            if ($validated['parent_id'] !== null && $preflightParent === null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent category is unavailable.',
                ]);
            }
            try {
                $hierarchy->assertCanAssignToParent($category, $preflightParent);
            } catch (InvalidCategoryHierarchyException) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent would create an invalid category hierarchy.',
                ]);
            }
        }
        $asset = $covers->upload($image, $request->user());
        $formerAssetIds = [];

        try {
            $category = DB::transaction(function () use (
                $category, $validated, $hierarchy, $covers, $asset, $remove, &$formerAssetIds,
            ): Category {
                $locked = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

                if (array_key_exists('parent_id', $validated)) {
                    $parent = $validated['parent_id'] === null
                        ? null
                        : Category::query()->whereKey($validated['parent_id'])->lockForUpdate()->first();

                    if ($validated['parent_id'] !== null && $parent === null) {
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

                $locked->fill($validated);
                $locked->save();

                if ($asset !== null || $remove) {
                    $formerAssetIds = $covers->changeInTransaction($locked, $asset, $remove);
                }

                return $locked;
            });
        } catch (Throwable $exception) {
            $covers->discardNewAsset($asset);
            if ($exception instanceof QueryException) {
                $this->convertSlugCollision($exception);
            }
            throw $exception;
        }

        $covers->cleanupFormerAssets($formerAssetIds);

        $this->loadResourceFields($category, $hierarchy);

        return new AdminCategoryResource($category);
    }

    public function destroy(Category $category, CategoryArchiveService $archive): JsonResponse|Response
    {
        $conflict = $archive->archive($category);

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

    public function restore(
        int $category,
        CategoryArchiveService $archive,
        CategoryHierarchyService $hierarchy,
    ): JsonResponse|AdminCategoryResource {
        try {
            $result = $archive->restore($category, $hierarchy);
        } catch (InvalidCategoryHierarchyException) {
            throw ValidationException::withMessages([
                'parent_id' => 'The category cannot be restored under its current parent.',
            ]);
        }

        if (is_string($result)) {
            $messages = [
                'category_not_archived' => 'The category is not archived.',
                'category_parent_unavailable' => 'The category parent is unavailable.',
                'category_slug_conflict' => 'The category slug is already in use.',
            ];

            return response()->json([
                'code' => $result,
                'message' => $messages[$result],
            ], 409);
        }

        $this->loadResourceFields($result, $hierarchy);

        return new AdminCategoryResource($result);
    }

    private function loadResourceFields(Category $category, CategoryHierarchyService $hierarchy): void
    {
        $hierarchy->prepareAdminCategories([$category]);
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
