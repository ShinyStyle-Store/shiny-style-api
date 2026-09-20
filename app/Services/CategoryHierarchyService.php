<?php

namespace App\Services;

use App\Exceptions\InvalidCategoryHierarchyException;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategoryHierarchyService
{
    /** Build a single category hierarchy and status snapshot for visibility checks. */
    public function visibilitySnapshot(bool $includePresentationData = false): CategoryVisibilitySnapshot
    {
        $columns = [
            'id', 'parent_id', 'status', 'deleted_at',
        ];

        if ($includePresentationData) {
            $columns = [
                'id', 'parent_id', 'slug', 'name_ar', 'name_en', 'description_ar', 'description_en',
                'status', 'sort_order', 'deleted_at', 'created_at', 'updated_at',
            ];
        }

        $query = Category::withTrashed();
        if ($includePresentationData) {
            $query->with('coverImageAttachment.mediaAsset');
        }
        $rows = $query->get($columns);
        $parents = [];
        $statuses = [];
        $deleted = [];
        $categories = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $parents[$id] = $row->parent_id === null ? null : (int) $row->parent_id;
            $statuses[$id] = (string) $row->status;
            $deleted[$id] = $row->deleted_at !== null;
            $categories[$id] = $row;
        }

        return new CategoryVisibilitySnapshot($parents, $statuses, $deleted, $categories);
    }

    /** Prepare a flat, ordered public category collection and its display metadata. */
    public function publicList(): Collection
    {
        $snapshot = $this->visibilitySnapshot(true);
        $categories = collect($snapshot->visibleIds())
            ->map(fn (int $id): ?Category => $snapshot->category($id))
            ->filter()
            ->sort(fn (Category $left, Category $right): int =>
                [$left->sort_order, $left->getKey()] <=> [$right->sort_order, $right->getKey()]
            )
            ->values();

        foreach ($categories as $category) {
            $this->preparePublicCategory($category, $snapshot);
        }

        return $categories;
    }

    /** Prepare a visible category detail with breadcrumbs and direct visible children. */
    public function publicDetail(string $slug): ?Category
    {
        $snapshot = $this->visibilitySnapshot(true);
        $category = $snapshot->visibleCategoryBySlug((string) $slug);

        if (! $category instanceof Category) {
            return null;
        }

        $this->preparePublicCategory($category, $snapshot);
        $breadcrumbs = $snapshot->breadcrumbs($category);
        foreach ($breadcrumbs as $breadcrumb) {
            $this->preparePublicCategory($breadcrumb, $snapshot);
        }
        $category->setAttribute('category_breadcrumbs', $breadcrumbs);

        $children = collect($snapshot->directChildren($category, true));
        foreach ($children as $child) {
            $this->preparePublicCategory($child, $snapshot);
        }
        $category->setAttribute('category_detail_children', $children);
        $category->setAttribute('category_detail', true);

        return $category;
    }

    /** Prepare administrative fields and counts using one hierarchy snapshot and one pivot aggregate. */
    public function prepareAdminCategories(iterable $categories): void
    {
        $categories = collect($categories);
        if ($categories->isEmpty()) {
            return;
        }

        (new EloquentCollection($categories->all()))->loadMissing('coverImageAttachment.mediaAsset');

        $snapshot = $this->visibilitySnapshot(true);
        $ids = $categories->map(fn (Category $category): int => (int) $category->getKey())->all();
        $productCounts = DB::table('category_product')
            ->select('category_id')
            ->selectRaw('COUNT(DISTINCT product_id) AS aggregate_count')
            ->whereIn('category_id', $ids)
            ->groupBy('category_id')
            ->pluck('aggregate_count', 'category_id');

        $prepared = [];
        foreach ($categories as $category) {
            $id = (int) $category->getKey();
            $parent = $snapshot->parent($id);
            $reason = $snapshot->visibilityReason($category);
            $childrenCount = $snapshot->directChildCount($id, excludeArchived: true);

            $prepared[$id] = [
                'category_parent_summary' => $parent === null ? null : [
                    'id' => $parent->getKey(),
                    'nameAr' => $parent->name_ar,
                    'nameEn' => $parent->name_en,
                    'slug' => $parent->slug,
                    'status' => $parent->status,
                    'archived' => $parent->trashed(),
                ],
                'hierarchy_depth' => $snapshot->depth($id),
                'children_count' => $childrenCount,
                'has_children' => $childrenCount > 0,
                'products_count' => (int) ($productCounts[$id] ?? 0),
                'is_effectively_visible' => $reason === null,
                'visibility_reason' => $reason,
                'archived_at' => $category->deleted_at,
            ];
        }

        foreach ($categories as $category) {
            foreach ($prepared[(int) $category->getKey()] as $attribute => $value) {
                $category->setAttribute($attribute, $value);
            }
        }
    }

    private function preparePublicCategory(Category $category, CategoryVisibilitySnapshot $snapshot): void
    {
        $id = (int) $category->getKey();
        $parent = $snapshot->parent($id);
        $childrenCount = $snapshot->directChildCount($category, visibleOnly: true);

        $category->setAttribute('category_parent_summary', $parent === null ? null : [
            'id' => $parent->getKey(),
            'name' => $this->localizedValue($parent->name_ar, $parent->name_en),
            'slug' => $parent->slug,
        ]);
        $category->setAttribute('hierarchy_depth', $snapshot->depth($category));
        $category->setAttribute('children_count', $childrenCount);
        $category->setAttribute('has_children', $childrenCount > 0);
    }

    private function localizedValue(?string $arabic, ?string $english): ?string
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== '' ? $english : null);
    }

    /** @return list<int> IDs of every effectively visible non-deleted category. */
    public function effectiveVisibleIds(): array
    {
        return $this->visibilitySnapshot()->visibleIds();
    }

    /**
     * Return the depth of a category, with root categories at depth one.
     */
    public function depth(Category|int $category): int
    {
        $graph = $this->graph();
        $categoryId = $this->id($category);

        return $this->depthFromGraph($categoryId, $graph['parents']);
    }

    /**
     * Calculate depths for multiple categories from a single hierarchy snapshot.
     *
     * @param  iterable<Category>  $categories
     * @return array<int, int> Category ID to depth
     */
    public function depths(iterable $categories): array
    {
        $graph = $this->graph();
        $depths = [];

        foreach ($categories as $category) {
            $categoryId = $this->id($category);
            $depths[$categoryId] = $this->depthFromGraph($categoryId, $graph['parents']);
        }

        return $depths;
    }

    /** @return list<int> All non-deleted descendant IDs, in breadth-first order. */
    public function descendantIds(Category|int $category): array
    {
        $graph = $this->graph();
        $categoryId = $this->id($category);
        $this->depthFromGraph($categoryId, $graph['parents']);

        return $this->descendantsFromGraph($categoryId, $graph['children']);
    }

    /** Return the number of edges on the longest path from this category to a descendant. */
    public function subtreeHeight(Category|int $category): int
    {
        $graph = $this->graph();
        $categoryId = $this->id($category);
        $this->depthFromGraph($categoryId, $graph['parents']);

        return $this->heightFromGraph($categoryId, $graph['children']);
    }

    /**
     * Check a proposed parent assignment. A null category represents a new category.
     */
    public function canAssignToParent(Category|int|null $category, Category|int|null $proposedParent): bool
    {
        try {
            $this->assertCanAssignToParent($category, $proposedParent);
        } catch (InvalidCategoryHierarchyException) {
            return false;
        }

        return true;
    }

    /** Validate an assignment or throw with the reason it is invalid. */
    public function assertCanAssignToParent(Category|int|null $category, Category|int|null $proposedParent): void
    {
        $this->assertAssignment($this->graph(), $category, $proposedParent);
    }

    /** Validate hierarchy constraints while temporarily including a soft-deleted category for restore. */
    public function assertCanRestore(Category $category, ?Category $proposedParent): void
    {
        if (! $category->trashed()) {
            throw new InvalidCategoryHierarchyException('Only archived categories may be restored.');
        }

        $this->assertAssignment($this->graph($category), $category, $proposedParent);
    }

    /** @param array{parents: array<int, int|null>, children: array<int, list<int>>} $graph */
    private function assertAssignment(array $graph, Category|int|null $category, Category|int|null $proposedParent): void
    {
        $categoryId = $category === null ? null : $this->id($category);
        $parentId = $proposedParent === null ? null : $this->id($proposedParent);

        if ($categoryId !== null) {
            $this->assertCategoryExists($categoryId, $graph['parents']);
        }

        if ($parentId !== null) {
            $this->assertCategoryExists($parentId, $graph['parents']);
        }

        if ($categoryId !== null && $parentId === $categoryId) {
            throw new InvalidCategoryHierarchyException('A category cannot be its own parent.');
        }

        $subtreeIds = $categoryId === null ? [] : $this->descendantsFromGraph($categoryId, $graph['children']);

        if ($categoryId !== null && $parentId !== null && in_array($parentId, $subtreeIds, true)) {
            throw new InvalidCategoryHierarchyException('A category cannot be moved beneath one of its descendants.');
        }

        $parentDepth = $parentId === null ? 0 : $this->depthFromGraph($parentId, $graph['parents']);
        $subtreeHeight = $categoryId === null
            ? 1
            : $this->heightFromGraph($categoryId, $graph['children']);
        $maxDepth = (int) config('catalog.max_category_depth');

        if ($parentDepth + $subtreeHeight > $maxDepth) {
            throw new InvalidCategoryHierarchyException(
                "The category assignment would exceed the maximum depth of {$maxDepth}.",
            );
        }
    }

    /** @return array{parents: array<int, int|null>, children: array<int, list<int>>} */
    private function graph(?Category $includeTrashed = null): array
    {
        $rows = Category::query()->get(['id', 'parent_id']);
        $parents = [];
        $children = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $parents[$id] = $row->parent_id === null ? null : (int) $row->parent_id;
            $children[$id] = [];
        }

        if ($includeTrashed !== null && ! array_key_exists((int) $includeTrashed->getKey(), $parents)) {
            $id = (int) $includeTrashed->getKey();
            $parents[$id] = $includeTrashed->parent_id === null ? null : (int) $includeTrashed->parent_id;
            $children[$id] = [];
        }

        foreach ($parents as $id => $parentId) {
            if ($parentId !== null && array_key_exists($parentId, $parents)) {
                $children[$parentId][] = $id;
            }
        }

        return ['parents' => $parents, 'children' => $children];
    }

    /** @param array<int, int|null> $parents */
    private function depthFromGraph(int $categoryId, array $parents): int
    {
        $this->assertCategoryExists($categoryId, $parents);
        $depth = 1;
        $visited = [$categoryId => true];
        $parentId = $parents[$categoryId];

        while ($parentId !== null) {
            if (isset($visited[$parentId])) {
                throw new InvalidCategoryHierarchyException('A cycle exists in the category hierarchy.');
            }

            $this->assertCategoryExists($parentId, $parents);
            $visited[$parentId] = true;
            $depth++;
            $parentId = $parents[$parentId];
        }

        return $depth;
    }

    /** @param array<int, list<int>> $children
     * @return list<int>
     */
    private function descendantsFromGraph(int $categoryId, array $children): array
    {
        $visited = [$categoryId => true];
        $descendants = [];
        $queue = $children[$categoryId] ?? [];
        $queueIndex = 0;

        while (isset($queue[$queueIndex])) {
            $currentId = $queue[$queueIndex++];

            if (isset($visited[$currentId])) {
                throw new InvalidCategoryHierarchyException('A cycle exists in the category hierarchy.');
            }

            $visited[$currentId] = true;
            $descendants[] = $currentId;
            foreach ($children[$currentId] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return $descendants;
    }

    /** @param array<int, list<int>> $children */
    private function heightFromGraph(int $categoryId, array $children): int
    {
        $visited = [$categoryId => true];
        $height = 1;
        $queue = [[$categoryId, 1]];
        $queueIndex = 0;

        while (isset($queue[$queueIndex])) {
            [$currentId, $currentDepth] = $queue[$queueIndex++];
            $height = max($height, $currentDepth);

            foreach ($children[$currentId] ?? [] as $childId) {
                if (isset($visited[$childId])) {
                    throw new InvalidCategoryHierarchyException('A cycle exists in the category hierarchy.');
                }

                $visited[$childId] = true;
                $queue[] = [$childId, $currentDepth + 1];
            }
        }

        return $height;
    }

    /** @param array<int, int|null> $parents */
    private function assertCategoryExists(int $categoryId, array $parents): void
    {
        if (! array_key_exists($categoryId, $parents)) {
            throw new InvalidCategoryHierarchyException(
                "Category {$categoryId} does not exist in the active hierarchy.",
            );
        }
    }

    private function id(Category|int $category): int
    {
        return $category instanceof Category ? (int) $category->getKey() : $category;
    }
}
