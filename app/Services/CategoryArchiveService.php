<?php

namespace App\Services;

use App\Exceptions\InvalidCategoryHierarchyException;
use App\Models\Category;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CategoryArchiveService
{
    public function archive(Category $category): ?string
    {
        return DB::transaction(function () use ($category): ?string {
            $locked = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->children()->exists()) {
                return 'category_has_children';
            }

            if (DB::table('category_product')->where('category_id', $locked->getKey())->exists()) {
                return 'category_has_products';
            }

            $locked->status = 'inactive';
            $locked->save();
            $locked->delete();

            return null;
        });
    }

    public function restore(int $categoryId, CategoryHierarchyService $hierarchy): Category|string
    {
        try {
            return DB::transaction(function () use ($categoryId, $hierarchy): Category|string {
                $category = Category::withTrashed()
                    ->whereKey($categoryId)
                    ->lockForUpdate()
                    ->first();

                if ($category === null) {
                    throw (new ModelNotFoundException)->setModel(Category::class, [$categoryId]);
                }

                if (! $category->trashed()) {
                    return 'category_not_archived';
                }

                $parent = null;
                if ($category->parent_id !== null) {
                    $parent = Category::query()
                        ->whereKey($category->parent_id)
                        ->lockForUpdate()
                        ->first();

                    if ($parent === null) {
                        return 'category_parent_unavailable';
                    }
                }

                $hierarchy->assertCanRestore($category, $parent);

                $category->status = 'inactive';
                $category->restore();

                return $category;
            });
        } catch (QueryException $exception) {
            if ($this->isSlugConflict($exception)) {
                return 'category_slug_conflict';
            }

            throw $exception;
        }
    }

    private function isSlugConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'categories.slug')
            || (str_contains($message, 'categories_slug_unique') && str_contains($message, 'unique'));
    }
}
