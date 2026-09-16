<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonMissingPath('data.children')
            ->assertJsonMissingPath('data.products');

        $this->getJson('/api/v1/categories/missing-category')->assertNotFound();
        $this->getJson('/api/v1/categories/'.$inactive->slug)->assertNotFound();
        $this->getJson('/api/v1/categories/'.$deleted->slug)->assertNotFound();
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

    public function test_parent_category_filter_does_not_include_descendant_products(): void
    {
        $parent = $this->createCategory(['slug' => 'parent-category']);
        $child = $this->createCategory(['slug' => 'child-category', 'parent_id' => $parent->getKey()]);
        $childProduct = $this->createProduct('child-category-product', $child);

        $this->getJson('/api/v1/products?category='.$parent->slug)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['slug' => $childProduct->slug]);
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
