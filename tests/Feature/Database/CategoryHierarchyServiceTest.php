<?php

namespace Tests\Feature\Database;

use App\Exceptions\InvalidCategoryHierarchyException;
use App\Models\Category;
use App\Services\CategoryHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryHierarchyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_child_and_grandchild_depths_are_calculated_from_one(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', $root);
        $grandchild = $this->category('grandchild', $child);
        $service = app(CategoryHierarchyService::class);

        $this->assertSame(1, $service->depth($root));
        $this->assertSame(2, $service->depth($child));
        $this->assertSame(3, $service->depth($grandchild));
    }

    public function test_descendant_ids_include_every_level_without_duplicates(): void
    {
        $root = $this->category('root');
        $firstChild = $this->category('first-child', $root);
        $secondChild = $this->category('second-child', $root);
        $grandchild = $this->category('grandchild', $firstChild);
        $service = app(CategoryHierarchyService::class);

        $ids = $service->descendantIds($root);

        $this->assertEqualsCanonicalizing([
            $firstChild->getKey(),
            $secondChild->getKey(),
            $grandchild->getKey(),
        ], $ids);
        $this->assertCount(count(array_unique($ids)), $ids);
    }

    public function test_subtree_height_is_the_longest_path_including_the_category(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', $root);
        $grandchild = $this->category('grandchild', $child);
        $this->category('sibling', $root);
        $service = app(CategoryHierarchyService::class);

        $this->assertSame(3, $service->subtreeHeight($root));
        $this->assertSame(2, $service->subtreeHeight($child));
        $this->assertSame(1, $service->subtreeHeight($grandchild));
    }

    public function test_self_parenting_is_rejected(): void
    {
        $category = $this->category('category');

        $this->assertFalse(app(CategoryHierarchyService::class)->canAssignToParent($category, $category));
        $this->expectException(InvalidCategoryHierarchyException::class);
        app(CategoryHierarchyService::class)->assertCanAssignToParent($category, $category);
    }

    public function test_moving_a_category_under_a_direct_child_is_rejected(): void
    {
        $parent = $this->category('parent');
        $child = $this->category('child', $parent);

        $this->assertFalse(app(CategoryHierarchyService::class)->canAssignToParent($parent, $child));
    }

    public function test_moving_a_category_under_an_indirect_descendant_is_rejected(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', $root);
        $grandchild = $this->category('grandchild', $child);

        $this->assertFalse(app(CategoryHierarchyService::class)->canAssignToParent($root, $grandchild));
    }

    public function test_valid_move_within_the_configured_depth_is_accepted(): void
    {
        $root = $this->category('root');
        $branch = $this->category('branch');
        $moving = $this->category('moving', $branch);
        $leaf = $this->category('leaf', $moving);

        $this->assertTrue(app(CategoryHierarchyService::class)->canAssignToParent($moving, $root));
        $this->assertSame(3, app(CategoryHierarchyService::class)->depth($leaf));
    }

    public function test_creating_a_category_at_a_fourth_level_is_rejected(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', $root);
        $grandchild = $this->category('grandchild', $child);

        $this->assertFalse(app(CategoryHierarchyService::class)->canAssignToParent(null, $grandchild));
    }

    public function test_move_is_rejected_if_any_descendant_would_exceed_the_limit(): void
    {
        $root = $this->category('root');
        $moving = $this->category('moving');
        $child = $this->category('child', $moving);
        $this->category('grandchild', $child);

        $this->assertFalse(app(CategoryHierarchyService::class)->canAssignToParent($moving, $root));
    }

    public function test_corrupted_cycle_is_reported_and_traversal_terminates(): void
    {
        $first = $this->category('first');
        $second = $this->category('second', $first);
        DB::table('categories')->where('id', $first->getKey())->update(['parent_id' => $second->getKey()]);

        $this->expectException(InvalidCategoryHierarchyException::class);
        app(CategoryHierarchyService::class)->descendantIds($first);
    }

    public function test_maximum_depth_is_read_from_configuration_at_validation_time(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', $root);
        $service = app(CategoryHierarchyService::class);

        config(['catalog.max_category_depth' => 2]);
        $this->assertFalse($service->canAssignToParent(null, $child));

        config(['catalog.max_category_depth' => 3]);
        $this->assertTrue($service->canAssignToParent(null, $child));
    }

    public function test_soft_deleted_categories_are_excluded_from_descendant_queries(): void
    {
        $root = $this->category('root');
        $activeChild = $this->category('active-child', $root);
        $deletedChild = $this->category('deleted-child', $root);
        $deletedChild->delete();
        $service = app(CategoryHierarchyService::class);

        $this->assertSame([$activeChild->getKey()], $service->descendantIds($root));
        $this->assertFalse($service->canAssignToParent(null, $deletedChild));
    }

    public function test_effective_visibility_walks_active_ancestor_paths_and_restores_descendants(): void
    {
        $root = $this->category('visible-root');
        $child = $this->category('visible-child', $root);
        $grandchild = $this->category('visible-grandchild', $child);
        $inactiveRoot = $this->category('inactive-root', null, ['status' => 'inactive']);
        $hiddenChild = $this->category('hidden-child', $inactiveRoot);
        $hiddenGrandchild = $this->category('hidden-grandchild', $hiddenChild);
        $service = app(CategoryHierarchyService::class);

        $visibleIds = $service->effectiveVisibleIds();
        $this->assertContains($root->getKey(), $visibleIds);
        $this->assertContains($child->getKey(), $visibleIds);
        $this->assertContains($grandchild->getKey(), $visibleIds);
        $this->assertNotContains($inactiveRoot->getKey(), $visibleIds);
        $this->assertNotContains($hiddenChild->getKey(), $visibleIds);
        $this->assertNotContains($hiddenGrandchild->getKey(), $visibleIds);

        $inactiveRoot->update(['status' => 'active']);

        $this->assertContains($hiddenChild->getKey(), $service->effectiveVisibleIds());
        $this->assertContains($hiddenGrandchild->getKey(), $service->effectiveVisibleIds());
    }

    public function test_effective_visibility_excludes_soft_deleted_ancestors_and_cycles(): void
    {
        $deletedRoot = $this->category('deleted-root');
        $child = $this->category('deleted-child', $deletedRoot);
        $deletedRoot->delete();

        $first = $this->category('cycle-first');
        $second = $this->category('cycle-second', $first);
        DB::table('categories')->where('id', $first->getKey())->update(['parent_id' => $second->getKey()]);

        $visibleIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();

        $this->assertNotContains($deletedRoot->getKey(), $visibleIds);
        $this->assertNotContains($child->getKey(), $visibleIds);
        $this->assertNotContains($first->getKey(), $visibleIds);
        $this->assertNotContains($second->getKey(), $visibleIds);
    }

    public function test_visibility_snapshot_returns_only_the_requested_visible_subtree(): void
    {
        $root = $this->category('subtree-root');
        $child = $this->category('subtree-child', $root);
        $grandchild = $this->category('subtree-grandchild', $child);
        $inactiveChild = $this->category('subtree-inactive-child', $root, ['status' => 'inactive']);
        $hiddenGrandchild = $this->category('subtree-hidden-grandchild', $inactiveChild);
        $snapshot = app(CategoryHierarchyService::class)->visibilitySnapshot();

        $this->assertEqualsCanonicalizing(
            [$root->getKey(), $child->getKey(), $grandchild->getKey()],
            $snapshot->visibleSubtreeIds($root),
        );
        $this->assertSame([], $snapshot->visibleSubtreeIds($inactiveChild));
        $this->assertNotContains($hiddenGrandchild->getKey(), $snapshot->visibleIds());
    }

    private function category(string $slug, ?Category $parent = null, array $attributes = []): Category
    {
        return Category::create(array_merge([
            'parent_id' => $parent?->getKey(),
            'slug' => $slug.'-'.uniqid(),
            'name_ar' => 'تصنيف',
            'name_en' => ucfirst($slug),
            'status' => 'active',
        ], $attributes));
    }
}
