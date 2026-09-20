<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_listing_returns_only_active_categories_in_deterministic_order(): void
    {
        $later = $this->createCategory(['slug' => 'later', 'sort_order' => 2]);
        $earlier = $this->createCategory(['slug' => 'earlier', 'sort_order' => 1]);
        $inactive = $this->createCategory(['slug' => 'inactive', 'status' => 'inactive']);
        $deleted = $this->createCategory(['slug' => 'deleted']);
        $deleted->delete();

        $response = $this->getJson('/api/v1/categories');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', $earlier->slug)
            ->assertJsonPath('data.1.slug', $later->slug)
            ->assertJsonMissing(['slug' => $inactive->slug])
            ->assertJsonMissing(['slug' => $deleted->slug])
            ->assertJsonMissingPath('data.0.children')
            ->assertJsonMissingPath('data.0.products');

        $this->assertArrayNotHasKey('status', $response->json('data.0'));
        $this->assertArrayNotHasKey('deleted_at', $response->json('data.0'));
        $this->assertArrayNotHasKey('name_ar', $response->json('data.0'));
    }

    public function test_public_list_includes_flat_hierarchy_metadata_and_effective_direct_child_counts(): void
    {
        $root = $this->createCategory([
            'slug' => 'tree-root', 'name_ar' => 'Ø§Ù„Ø¬Ø°Ø±', 'name_en' => 'Root', 'sort_order' => 1,
        ]);
        $child = $this->createCategory([
            'slug' => 'tree-child', 'parent_id' => $root->id, 'name_ar' => 'Ø§Ù„ÙØ±Ø¹', 'name_en' => 'Child',
        ]);
        $grandchild = $this->createCategory(['slug' => 'tree-grandchild', 'parent_id' => $child->id]);
        $inactiveChild = $this->createCategory([
            'slug' => 'tree-inactive-child', 'parent_id' => $root->id, 'status' => 'inactive',
        ]);
        $hiddenBelowInactive = $this->createCategory([
            'slug' => 'tree-hidden-grandchild', 'parent_id' => $inactiveChild->id,
        ]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $root->id)
            ->assertJsonPath('data.0.name', 'Root')
            ->assertJsonPath('data.0.parent', null)
            ->assertJsonPath('data.0.depth', 1)
            ->assertJsonPath('data.0.hasChildren', true)
            ->assertJsonPath('data.0.childrenCount', 1)
            ->assertJsonPath('data.1.id', $child->id)
            ->assertJsonPath('data.1.parent.id', $root->id)
            ->assertJsonPath('data.1.parent.name', 'Root')
            ->assertJsonPath('data.1.depth', 2)
            ->assertJsonPath('data.2.id', $grandchild->id)
            ->assertJsonPath('data.2.depth', 3)
            ->assertJsonMissing(['slug' => $inactiveChild->slug])
            ->assertJsonMissing(['slug' => $hiddenBelowInactive->slug]);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/categories')
            ->assertOk()->assertJsonPath('data.1.parent.name', 'Ø§Ù„Ø¬Ø°Ø±');
    }

    public function test_public_detail_includes_ordered_breadcrumbs_and_one_level_of_visible_children(): void
    {
        $root = $this->createCategory(['slug' => 'detail-tree-root', 'sort_order' => 3]);
        $category = $this->createCategory(['slug' => 'detail-tree-current', 'parent_id' => $root->id]);
        $child = $this->createCategory(['slug' => 'detail-tree-child', 'parent_id' => $category->id, 'sort_order' => 2]);
        $visibleGrandchild = $this->createCategory(['slug' => 'detail-tree-grandchild', 'parent_id' => $child->id]);
        $hiddenChild = $this->createCategory([
            'slug' => 'detail-tree-hidden', 'parent_id' => $category->id, 'status' => 'inactive',
        ]);

        $this->getJson('/api/v1/categories/'.$category->slug)
            ->assertOk()
            ->assertJsonPath('data.depth', 2)
            ->assertJsonPath('data.parent.id', $root->id)
            ->assertJsonPath('data.breadcrumbs.0.id', $root->id)
            ->assertJsonPath('data.breadcrumbs.1.id', $category->id)
            ->assertJsonPath('data.children.0.id', $child->id)
            ->assertJsonPath('data.children.0.depth', 3)
            ->assertJsonPath('data.children.0.hasChildren', true)
            ->assertJsonPath('data.children.0.childrenCount', 1)
            ->assertJsonMissing(['slug' => $hiddenChild->slug])
            ->assertJsonMissingPath('data.children.0.children')
            ->assertJsonMissing(['slug' => $visibleGrandchild->slug]);

        $this->getJson('/api/v1/categories/'.$root->slug)
            ->assertOk()->assertJsonCount(1, 'data.breadcrumbs')
            ->assertJsonPath('data.breadcrumbs.0.id', $root->id);
    }

    public function test_public_hierarchy_metadata_and_grandchild_details_ignore_sort_order(): void
    {
        $root = $this->createCategory(['slug' => 'unordered-public-root', 'sort_order' => 2]);
        $child = $this->createCategory([
            'slug' => 'unordered-public-child', 'parent_id' => $root->id, 'sort_order' => 3,
        ]);
        $grandchild = $this->createCategory([
            'slug' => 'unordered-public-grandchild', 'parent_id' => $child->id, 'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/v1/categories')->assertOk();
        $items = collect($response->json('data'))->keyBy('slug');

        $this->assertSame(1, $items[$root->slug]['childrenCount']);
        $this->assertTrue($items[$root->slug]['hasChildren']);
        $this->assertSame(1, $items[$root->slug]['depth']);
        $this->assertSame(1, $items[$child->slug]['childrenCount']);
        $this->assertTrue($items[$child->slug]['hasChildren']);
        $this->assertSame(2, $items[$child->slug]['depth']);
        $this->assertSame(0, $items[$grandchild->slug]['childrenCount']);
        $this->assertFalse($items[$grandchild->slug]['hasChildren']);
        $this->assertSame(3, $items[$grandchild->slug]['depth']);

        $this->getJson('/api/v1/categories/'.$grandchild->slug)
            ->assertOk()
            ->assertJsonPath('data.id', $grandchild->id)
            ->assertJsonPath('data.breadcrumbs.0.id', $root->id)
            ->assertJsonPath('data.breadcrumbs.1.id', $child->id)
            ->assertJsonPath('data.breadcrumbs.2.id', $grandchild->id);
    }

    public function test_public_category_cycles_are_not_exposed_or_followed_indefinitely(): void
    {
        $first = $this->createCategory(['slug' => 'cyclic-first']);
        $second = $this->createCategory(['slug' => 'cyclic-second', 'parent_id' => $first->id]);
        DB::table('categories')->where('id', $first->id)->update(['parent_id' => $second->id]);

        $this->getJson('/api/v1/categories')->assertOk()
            ->assertJsonMissing(['slug' => $first->slug])->assertJsonMissing(['slug' => $second->slug]);
        $this->getJson('/api/v1/categories/'.$first->slug)->assertNotFound();
    }

    public function test_category_localization_and_fallback_are_applied(): void
    {
        $category = $this->createCategory([
            'slug' => 'localized-category',
            'name_ar' => 'تصنيف عربي',
            'name_en' => 'English Category',
            'description_ar' => 'وصف عربي',
            'description_en' => 'English description',
            'parent_id' => null,
            'sort_order' => 4,
        ]);
        $arabicFallback = $this->createCategory([
            'slug' => 'arabic-category-fallback',
            'name_ar' => 'اسم عربي',
            'name_en' => '',
        ]);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/categories/'.$category->slug)
            ->assertOk()
            ->assertJsonPath('data.id', $category->getKey())
            ->assertJsonPath('data.name', 'تصنيف عربي')
            ->assertJsonPath('data.description', 'وصف عربي')
            ->assertJsonPath('data.parentId', null)
            ->assertJsonPath('data.sortOrder', 4);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/categories/'.$category->slug)
            ->assertOk()
            ->assertJsonPath('data.name', 'English Category')
            ->assertJsonPath('data.description', 'English description');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/categories/'.$arabicFallback->slug)
            ->assertOk()
            ->assertJsonPath('data.name', 'اسم عربي');
    }

    public function test_category_details_return_metadata_only_and_hide_unavailable_categories(): void
    {
        $active = $this->createCategory(['slug' => 'active-category']);
        $inactive = $this->createCategory(['slug' => 'inactive-category', 'status' => 'inactive']);
        $deleted = $this->createCategory(['slug' => 'deleted-category']);
        $deleted->delete();

        $this->getJson('/api/v1/categories/'.$active->slug)
            ->assertOk()
            ->assertJsonPath('data.children', [])
            ->assertJsonMissingPath('data.products');

        $this->getJson('/api/v1/categories/missing-category')->assertNotFound();
        $this->getJson('/api/v1/categories/'.$inactive->slug)->assertNotFound();
        $this->getJson('/api/v1/categories/'.$deleted->slug)->assertNotFound();
    }

    public function test_public_category_visibility_requires_every_ancestor_to_be_active_and_available(): void
    {
        $root = $this->createCategory(['slug' => 'visible-root']);
        $child = $this->createCategory(['slug' => 'visible-child', 'parent_id' => $root->id]);
        $grandchild = $this->createCategory(['slug' => 'visible-grandchild', 'parent_id' => $child->id]);

        $inactiveChild = $this->createCategory([
            'slug' => 'inactive-child', 'parent_id' => $root->id, 'status' => 'inactive',
        ]);
        $this->createCategory(['slug' => 'behind-inactive-child', 'parent_id' => $inactiveChild->id]);

        $inactiveRoot = $this->createCategory(['slug' => 'inactive-root', 'status' => 'inactive']);
        $this->createCategory(['slug' => 'behind-inactive-root', 'parent_id' => $inactiveRoot->id]);

        $deletedRoot = $this->createCategory(['slug' => 'deleted-root']);
        $deletedChild = $this->createCategory(['slug' => 'behind-deleted-root', 'parent_id' => $deletedRoot->id]);
        $this->createCategory(['slug' => 'behind-deleted-child', 'parent_id' => $deletedChild->id]);
        $deletedRoot->delete();

        $response = $this->getJson('/api/v1/categories')->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertEqualsCanonicalizing([$root->slug, $child->slug, $grandchild->slug], $slugs);
        $this->assertArrayNotHasKey('children', $response->json('data.0'));
    }

    public function test_reactivating_a_parent_restores_visibility_of_active_descendants(): void
    {
        $root = $this->createCategory(['slug' => 'reactivating-root', 'status' => 'inactive']);
        $child = $this->createCategory(['slug' => 'reactivating-child', 'parent_id' => $root->id]);
        $grandchild = $this->createCategory(['slug' => 'reactivating-grandchild', 'parent_id' => $child->id]);

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonMissing(['slug' => $child->slug]);

        $root->update(['status' => 'active']);

        $this->getJson('/api/v1/categories')->assertOk()
            ->assertJsonFragment(['slug' => $root->slug])
            ->assertJsonFragment(['slug' => $child->slug])
            ->assertJsonFragment(['slug' => $grandchild->slug]);
    }

    public function test_category_detail_hides_active_categories_behind_inactive_or_deleted_ancestors(): void
    {
        $inactiveParent = $this->createCategory(['slug' => 'hidden-detail-parent', 'status' => 'inactive']);
        $hiddenChild = $this->createCategory(['slug' => 'hidden-detail-child', 'parent_id' => $inactiveParent->id]);
        $deletedParent = $this->createCategory(['slug' => 'deleted-detail-parent']);
        $deletedChild = $this->createCategory(['slug' => 'deleted-detail-child', 'parent_id' => $deletedParent->id]);
        $deletedParent->delete();

        $this->getJson('/api/v1/categories/'.$hiddenChild->slug)->assertNotFound();
        $this->getJson('/api/v1/categories/'.$deletedChild->slug)->assertNotFound();
    }

    public function test_products_require_an_active_category_for_public_visibility(): void
    {
        $withoutCategories = $this->createProduct('without-categories');
        $inactiveCategory = $this->createCategory(['slug' => 'inactive-product-category', 'status' => 'inactive']);
        $inactiveOnly = $this->createProduct('inactive-only-category', $inactiveCategory);
        $deletedCategory = $this->createCategory(['slug' => 'deleted-product-category']);
        $deletedCategory->delete();
        $deletedOnly = $this->createProduct('deleted-only-category', $deletedCategory);
        $activeCategory = $this->createCategory(['slug' => 'active-product-category']);
        $visible = $this->createProduct('active-category-product', $activeCategory);

        foreach ([$withoutCategories, $inactiveOnly, $deletedOnly] as $product) {
            $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
        }

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonMissing(['slug' => $withoutCategories->slug])
            ->assertJsonMissing(['slug' => $inactiveOnly->slug])
            ->assertJsonMissing(['slug' => $deletedOnly->slug])
            ->assertJsonFragment(['slug' => $visible->slug]);
    }

    public function test_a_product_with_an_active_secondary_category_uses_it_when_primary_is_inactive(): void
    {
        $inactivePrimary = $this->createCategory([
            'slug' => 'inactive-primary',
            'status' => 'inactive',
            'sort_order' => 1,
        ]);
        $activeSecondary = $this->createCategory([
            'slug' => 'active-secondary',
            'name_en' => 'Active Secondary',
            'sort_order' => 2,
        ]);
        $product = $this->createProduct('mixed-category-product');
        $product->categories()->attach($inactivePrimary, ['is_primary' => true]);
        $product->categories()->attach($activeSecondary, ['is_primary' => false]);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.category.slug', $activeSecondary->slug)
            ->assertJsonPath('data.category.name', 'Active Secondary');
    }

    public function test_category_filter_uses_direct_active_category_membership(): void
    {
        $category = $this->createCategory(['slug' => 'filtered-category']);
        $included = $this->createProduct('included-filter-product', $category);
        $excluded = $this->createProduct('excluded-filter-product');

        $this->getJson('/api/v1/products?category='.$category->slug)
            ->assertOk()
            ->assertJsonFragment(['slug' => $included->slug])
            ->assertJsonMissing(['slug' => $excluded->slug]);
    }

    public function test_category_filter_returns_empty_paginated_results_for_unavailable_categories(): void
    {
        $inactive = $this->createCategory(['slug' => 'filter-inactive', 'status' => 'inactive']);
        $deleted = $this->createCategory(['slug' => 'filter-deleted']);
        $deleted->delete();

        foreach (['missing-filter', $inactive->slug, $deleted->slug] as $slug) {
            $this->getJson('/api/v1/products?category='.$slug.'&per_page=1')
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonStructure(['data', 'links', 'meta'])
                ->assertJsonPath('meta.per_page', 1);
        }
    }

    public function test_empty_category_filter_does_not_filter_products(): void
    {
        $category = $this->createCategory(['slug' => 'empty-category-filter-category']);
        $product = $this->createProduct('empty-category-filter-product', $category);

        $this->getJson('/api/v1/products?category=')
            ->assertOk()
            ->assertJsonFragment(['slug' => $product->slug]);
    }

    public function test_category_filter_composes_with_search_and_pagination(): void
    {
        $category = $this->createCategory(['slug' => 'search-filter-category']);
        $included = $this->createProduct('search-filter-coffee', $category);
        $this->createProduct('search-filter-blanket', $category);
        $this->createProduct('other-category-coffee');

        $this->getJson('/api/v1/products?category='.$category->slug.'&q=coffee&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['slug' => $included->slug])
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_category_filter_rejects_invalid_values(): void
    {
        $this->getJson('/api/v1/products?category='.str_repeat('a', 256))
            ->assertUnprocessable();

        $this->getJson('/api/v1/products?category%5B%5D=category')
            ->assertUnprocessable();
    }

    public function test_parent_category_filter_includes_descendant_products(): void
    {
        $parent = $this->createCategory(['slug' => 'parent-category']);
        $child = $this->createCategory(['slug' => 'child-category', 'parent_id' => $parent->getKey()]);
        $childProduct = $this->createProduct('child-category-product', $child);

        $this->getJson('/api/v1/products?category='.$parent->slug)
            ->assertOk()
            ->assertJsonFragment(['slug' => $childProduct->slug]);
    }

    public function test_category_filter_includes_visible_descendants_once_and_excludes_inactive_branches(): void
    {
        $root = $this->createCategory(['slug' => 'filter-tree-root']);
        $child = $this->createCategory(['slug' => 'filter-tree-child', 'parent_id' => $root->id]);
        $grandchild = $this->createCategory(['slug' => 'filter-tree-grandchild', 'parent_id' => $child->id]);
        $inactiveChild = $this->createCategory([
            'slug' => 'filter-tree-inactive-child', 'parent_id' => $root->id, 'status' => 'inactive',
        ]);
        $this->createCategory(['slug' => 'filter-tree-hidden-grandchild', 'parent_id' => $inactiveChild->id]);

        $rootProduct = $this->createProduct('filter-root-product', $root);
        $childProduct = $this->createProduct('filter-child-product', $child);
        $grandchildProduct = $this->createProduct('filter-grandchild-product', $grandchild);
        $multiCategoryProduct = $this->createProduct('filter-multi-product', $root);
        $multiCategoryProduct->categories()->attach($child, ['is_primary' => false]);
        $this->createProduct('filter-inactive-product', $inactiveChild);

        $response = $this->getJson('/api/v1/products?category='.$root->slug.'&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonCount(4, 'data');
        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertEqualsCanonicalizing([
            $rootProduct->slug,
            $childProduct->slug,
            $grandchildProduct->slug,
            $multiCategoryProduct->slug,
        ], $slugs);
        $this->assertCount(count(array_unique($slugs)), $slugs);

        $this->getJson('/api/v1/products?category='.$grandchild->slug)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonFragment(['slug' => $grandchildProduct->slug]);
    }

    public function test_category_filter_is_empty_when_category_is_hidden_behind_an_inactive_ancestor(): void
    {
        $parent = $this->createCategory(['slug' => 'unavailable-filter-parent', 'status' => 'inactive']);
        $child = $this->createCategory(['slug' => 'unavailable-filter-child', 'parent_id' => $parent->id]);
        $this->createProduct('unavailable-filter-product', $child);

        $this->getJson('/api/v1/products?category='.$child->slug.'&per_page=1')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_product_resource_uses_only_effectively_visible_categories(): void
    {
        $inactiveParent = $this->createCategory(['slug' => 'hidden-primary-parent', 'status' => 'inactive']);
        $hiddenPrimary = $this->createCategory([
            'slug' => 'hidden-primary-category', 'parent_id' => $inactiveParent->id,
        ]);
        $visibleSecondary = $this->createCategory([
            'slug' => 'visible-secondary-category', 'name_en' => 'Visible Secondary',
        ]);
        $product = $this->createProduct('visible-through-secondary', $hiddenPrimary);
        $product->categories()->attach($visibleSecondary, ['is_primary' => false]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.category.slug', $visibleSecondary->slug)
            ->assertJsonPath('data.category.name', 'Visible Secondary');
    }

    public function test_products_linked_only_behind_an_inactive_ancestor_are_hidden_everywhere(): void
    {
        $inactiveParent = $this->createCategory(['slug' => 'hidden-catalog-parent', 'status' => 'inactive']);
        $hiddenChild = $this->createCategory(['slug' => 'hidden-catalog-child', 'parent_id' => $inactiveParent->id]);
        $hiddenProduct = $this->createProduct('hidden-catalog-product', $hiddenChild, true);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonMissing(['slug' => $hiddenProduct->slug]);
        $this->getJson('/api/v1/products?q=hidden-catalog-product')->assertOk()
            ->assertJsonMissing(['slug' => $hiddenProduct->slug]);
        $this->getJson('/api/v1/products/featured')->assertOk()
            ->assertJsonMissing(['slug' => $hiddenProduct->slug]);
        $this->getJson('/api/v1/products/'.$hiddenProduct->slug)->assertNotFound();

        $visibleCategory = $this->createCategory(['slug' => 'also-visible-category']);
        $multiCategoryProduct = $this->createProduct('multi-visible-catalog-product', $hiddenChild, true);
        $multiCategoryProduct->categories()->attach($visibleCategory, ['is_primary' => false]);

        $this->getJson('/api/v1/products?q=multi-visible-catalog-product')->assertOk()
            ->assertJsonFragment(['slug' => $multiCategoryProduct->slug]);
        $this->getJson('/api/v1/products/'.$multiCategoryProduct->slug)->assertOk()
            ->assertJsonPath('data.category.slug', $visibleCategory->slug);
    }

    public function test_category_visibility_snapshot_uses_a_constant_number_of_queries(): void
    {
        $root = $this->createCategory(['slug' => 'visibility-query-root']);
        foreach (range(1, 8) as $index) {
            $this->createCategory([
                'slug' => 'visibility-query-'.$index,
                'parent_id' => $root->id,
            ]);
        }

        $categoryQueries = [];
        DB::listen(function ($query) use (&$categoryQueries): void {
            if (str_contains(strtolower($query->sql), 'categories')) {
                $categoryQueries[] = $query->sql;
            }
        });

        $this->getJson('/api/v1/categories')->assertOk();

        $this->assertCount(1, $categoryQueries);
    }

    public function test_featured_products_and_search_apply_active_category_visibility(): void
    {
        $inactive = $this->createCategory(['slug' => 'inactive-featured-category', 'status' => 'inactive']);
        $hidden = $this->createProduct('hidden-featured-product', $inactive, true);
        $active = $this->createCategory(['slug' => 'active-featured-category']);
        $visible = $this->createProduct('visible-featured-product', $active, true);

        $this->getJson('/api/v1/products/featured')
            ->assertOk()
            ->assertJsonFragment(['slug' => $visible->slug])
            ->assertJsonMissing(['slug' => $hidden->slug]);

        $this->getJson('/api/v1/products?q=featured-product')
            ->assertOk()
            ->assertJsonFragment(['slug' => $visible->slug])
            ->assertJsonMissing(['slug' => $hidden->slug]);
    }

    private function createCategory(array $attributes = []): Category
    {
        return Category::create(array_merge([
            'slug' => 'category-'.uniqid(),
            'name_ar' => 'تصنيف',
            'name_en' => 'Category',
            'status' => 'active',
            'sort_order' => 1,
        ], $attributes));
    }

    private function createProduct(string $slug, ?Category $category = null, bool $featured = false): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name_ar' => 'منتج',
            'name_en' => 'Product '.$slug,
            'status' => 'active',
            'is_featured' => $featured,
            'published_at' => now()->subDay(),
        ]);
        SellableItem::create([
            'product_id' => $product->getKey(),
            'sku' => 'CATEGORY-'.strtoupper(uniqid()),
            'price' => 100,
            'stock_quantity' => 1,
            'status' => 'active',
            'is_default' => true,
        ]);

        if ($category !== null) {
            $product->categories()->attach($category, ['is_primary' => true]);
        }

        return $product;
    }
}
