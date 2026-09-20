<?php

namespace App\Services;

use App\Exceptions\InvalidCategoryHierarchyException;
use App\Models\Category;

class CategoryHierarchyService
{
    /** Build a single category hierarchy and status snapshot for public visibility checks. */
    public function visibilitySnapshot(): CategoryVisibilitySnapshot
    {
        $rows = Category::withTrashed()->get(['id', 'parent_id', 'status', 'deleted_at']);
        $parents = [];
        $statuses = [];
        $deleted = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $parents[$id] = $row->parent_id === null ? null : (int) $row->parent_id;
            $statuses[$id] = (string) $row->status;
            $deleted[$id] = $row->deleted_at !== null;
        }

        return new CategoryVisibilitySnapshot($parents, $statuses, $deleted);
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
        $graph = $this->graph();
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
    private function graph(): array
    {
        $rows = Category::query()->get(['id', 'parent_id']);
        $parents = [];
        $children = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $parents[$id] = $row->parent_id === null ? null : (int) $row->parent_id;
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
