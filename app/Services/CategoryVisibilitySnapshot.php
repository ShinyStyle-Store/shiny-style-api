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

    /**
     * @param  array<int, int|null>  $parents
     * @param  array<int, string>  $statuses
     * @param  array<int, bool>  $deleted
     */
    public function __construct(array $parents, array $statuses, array $deleted)
    {
        foreach ($parents as $id => $parentId) {
            $this->children[$id] = [];

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
