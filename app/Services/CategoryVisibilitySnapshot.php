<?php

namespace App\Services;

use App\Models\Category;

/** A consistent, in-memory view of effective category visibility for one operation. */
class CategoryVisibilitySnapshot
{
    /** @var list<int> */
    private array $visibleIds;

    /** @var array<int, list<int>> */
    private array $children = [];

    /** @var array<int, int|null> */
    private array $parents;

    /** @var array<int, string> */
    private array $statuses;

    /** @var array<int, bool> */
    private array $deleted;

    /** @var array<int, Category> */
    private array $categories;

    /**
     * @param  array<int, int|null>  $parents
     * @param  array<int, string>  $statuses
     * @param  array<int, bool>  $deleted
     * @param  array<int, Category>  $categories
     */
    public function __construct(array $parents, array $statuses, array $deleted, array $categories = [])
    {
        $this->parents = $parents;
        $this->statuses = $statuses;
        $this->deleted = $deleted;
        $this->categories = $categories;

        foreach ($parents as $id => $parentId) {
            $this->children[$id] = [];
        }

        foreach ($parents as $id => $parentId) {
            if ($parentId !== null && array_key_exists($parentId, $parents)) {
                $this->children[$parentId][] = $id;
            }
        }

        $this->visibleIds = $this->calculateVisibleIds($parents, $statuses, $deleted);
    }

    /** @return list<int> */
    public function visibleIds(): array
    {
        return $this->visibleIds;
    }

    public function contains(Category|int $category): bool
    {
        return in_array($category instanceof Category ? (int) $category->getKey() : $category, $this->visibleIds, true);
    }

    public function category(int $id): ?Category
    {
        return $this->categories[$id] ?? null;
    }

    public function visibleCategoryBySlug(string $slug): ?Category
    {
        foreach ($this->visibleIds as $visibleId) {
            $category = $this->category((int) $visibleId);

            if ($category !== null && (string) $category->slug === (string) $slug) {
                return $category;
            }
        }

        return null;
    }

    public function parent(int $id): ?Category
    {
        $parentId = $this->parents[$id] ?? null;

        return $parentId === null ? null : $this->category($parentId);
    }

    public function depth(Category|int $category): ?int
    {
        $id = $category instanceof Category ? (int) $category->getKey() : $category;
        if (! array_key_exists($id, $this->parents)) {
            return null;
        }

        $visited = [];
        $depth = 0;
        while (true) {
            if (isset($visited[$id])) {
                return null;
            }

            $visited[$id] = true;
            $depth++;
            $parentId = $this->parents[$id];
            if ($parentId === null) {
                return $depth;
            }
            if (! array_key_exists($parentId, $this->parents)) {
                return null;
            }
            $id = $parentId;
        }
    }

    /** @return list<Category> */
    public function breadcrumbs(Category|int $category): array
    {
        $id = $category instanceof Category ? (int) $category->getKey() : $category;
        $path = [];
        $visited = [];
        while (true) {
            if (isset($visited[$id]) || ! isset($this->categories[$id])) {
                return [];
            }

            $visited[$id] = true;
            $path[] = $this->categories[$id];
            $parentId = $this->parents[$id];
            if ($parentId === null) {
                return array_reverse($path);
            }
            if (! array_key_exists($parentId, $this->parents)) {
                return [];
            }
            $id = $parentId;
        }
    }

    /** @return list<Category> */
    public function directChildren(Category|int $category, bool $visibleOnly = false): array
    {
        $id = $category instanceof Category ? (int) $category->getKey() : $category;
        $visible = $visibleOnly ? array_fill_keys($this->visibleIds, true) : null;
        $children = [];

        foreach ($this->children[$id] ?? [] as $childId) {
            if (($visible === null || isset($visible[$childId])) && isset($this->categories[$childId])) {
                $children[] = $this->categories[$childId];
            }
        }

        usort($children, fn (Category $left, Category $right): int =>
            [$left->sort_order, $left->getKey()] <=> [$right->sort_order, $right->getKey()]
        );

        return $children;
    }

    public function directChildCount(
        Category|int $category,
        bool $visibleOnly = false,
        bool $excludeArchived = false,
    ): int {
        $id = $category instanceof Category ? (int) $category->getKey() : $category;
        $visible = $visibleOnly ? array_fill_keys(array_map('intval', $this->visibleIds), true) : null;
        $count = 0;

        foreach ($this->children[$id] ?? [] as $childId) {
            $child = $this->category((int) $childId);

            if ($child === null
                || ($visible !== null && ! isset($visible[(int) $childId]))
                || ($excludeArchived && $child->trashed())) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    public function visibilityReason(Category|int $category): ?string
    {
        $id = $category instanceof Category ? (int) $category->getKey() : $category;
        if (! array_key_exists($id, $this->parents)) {
            return 'invalid_hierarchy';
        }

        $visited = [];
        $isSelf = true;
        while (true) {
            if (isset($visited[$id])) {
                return 'invalid_hierarchy';
            }
            $visited[$id] = true;

            if (! array_key_exists($id, $this->categories)) {
                return 'invalid_hierarchy';
            }
            if ($this->deleted[$id]) {
                return $isSelf ? 'self_archived' : 'archived_ancestor';
            }
            if (($this->statuses[$id] ?? null) !== 'active') {
                return $isSelf ? 'self_inactive' : 'inactive_ancestor';
            }

            $parentId = $this->parents[$id];
            if ($parentId === null) {
                return null;
            }
            if (! array_key_exists($parentId, $this->parents)) {
                return 'invalid_hierarchy';
            }
            $id = $parentId;
            $isSelf = false;
        }
    }

    /** @return list<int> The category ID and all effectively visible descendants. */
    public function visibleSubtreeIds(Category|int $category): array
    {
        $rootId = $category instanceof Category ? (int) $category->getKey() : $category;

        if (! $this->contains($rootId)) {
            return [];
        }

        $visible = array_fill_keys($this->visibleIds, true);
        $result = [];
        $visited = [$rootId => true];
        $queue = [$rootId];
        $queueIndex = 0;

        while (isset($queue[$queueIndex])) {
            $currentId = $queue[$queueIndex++];
            $result[] = $currentId;

            foreach ($this->children[$currentId] ?? [] as $childId) {
                if (! isset($visited[$childId]) && isset($visible[$childId])) {
                    $visited[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int|null>  $parents
     * @param  array<int, string>  $statuses
     * @param  array<int, bool>  $deleted
     * @return list<int>
     */
    private function calculateVisibleIds(array $parents, array $statuses, array $deleted): array
    {
        $visibleIds = [];
        $visited = [];
        $roots = [];

        foreach ($parents as $id => $parentId) {
            if ($parentId === null) {
                $roots[] = $id;
            }
        }

        $queue = $roots;
        $queueIndex = 0;

        while (isset($queue[$queueIndex])) {
            $categoryId = $queue[$queueIndex++];

            if (isset($visited[$categoryId])) {
                continue;
            }

            $visited[$categoryId] = true;

            if (($statuses[$categoryId] ?? null) !== 'active' || ($deleted[$categoryId] ?? true)) {
                continue;
            }

            $visibleIds[] = $categoryId;

            foreach ($this->children[$categoryId] ?? [] as $childId) {
                if (! isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return $visibleIds;
    }
}
