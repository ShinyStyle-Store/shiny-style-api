<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_product_listing_returns_visible_products_with_pagination(): void
    {
        Product::create([
            'slug' => 'hidden-product',
            'name_ar' => 'منتج مخفي',
            'name_en' => 'Hidden Product',
            'status' => 'inactive',
            'is_featured' => false,
            'published_at' => now()->subDay(),
        ]);

        Product::create([
            'slug' => 'unpublished-product',
            'name_ar' => 'منتج غير منشور',
            'name_en' => 'Unpublished Product',
            'status' => 'active',
            'is_featured' => false,
            'published_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/products?per_page=1&page=1');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'slug', 'name', 'category', 'price', 'originalPrice', 'badge', 'inStock', 'image']],
                'links',
                'meta',
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);

        $this->assertNotContains('hidden-product', $response->json('data.*.slug'));
        $this->assertNotContains('unpublished-product', $response->json('data.*.slug'));
    }

    public function test_listing_uses_default_sellable_item_and_primary_image(): void
    {
        $response = $this->getJson('/api/products?per_page=100');
        $product = collect($response->json('data'))->firstWhere('slug', 'soft-sofa-throw-blanket');

        $this->assertSame(650, $product['price']);
        $this->assertSame(750, $product['originalPrice']);
        $this->assertSame('https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg', $product['image']);
    }

    public function test_featured_endpoint_returns_only_visible_featured_products(): void
    {
        $response = $this->getJson('/api/products/featured');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('soft-sofa-throw-blanket', $response->json('data.0.slug'));
    }

    public function test_product_details_return_options_combinations_and_default_item(): void
    {
        $response = $this->getJson('/api/products/soft-sofa-throw-blanket');

        $response->assertOk()
            ->assertJsonPath('data.name.en', 'Soft Sofa Throw Blanket')
            ->assertJsonPath('data.description.en', 'A soft throw blanket that adds warmth and comfort to any sofa.')
            ->assertJsonCount(2, 'data.options')
            ->assertJsonCount(4, 'data.sellableItems')
            ->assertJsonPath('data.sellableItems.0.optionValues.color', 'beige')
            ->assertJsonPath('data.sellableItems.0.optionValues.size', 'medium')
            ->assertJsonPath('data.gallery.0', 'https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg');

        $this->assertIsString($response->json('data.defaultSellableItemId'));
        $this->assertArrayNotHasKey('stock_quantity', $response->json('data.sellableItems.0'));
        $this->assertArrayNotHasKey('created_at', $response->json('data'));
    }

    public function test_coffee_machine_has_no_options_or_option_values(): void
    {
        $response = $this->getJson('/api/products/espresso-coffee-machine');

        $response->assertOk()
            ->assertJsonCount(0, 'data.options')
            ->assertJsonCount(1, 'data.sellableItems')
            ->assertJsonPath('data.sellableItems.0.optionValues', [])
            ->assertJsonPath('data.sellableItems.0.sku', 'COFFEE-MACHINE-001');
    }

    public function test_invisible_and_missing_products_return_not_found(): void
    {
        Product::create([
            'slug' => 'inactive-product',
            'name_ar' => 'منتج غير نشط',
            'name_en' => 'Inactive Product',
            'status' => 'inactive',
            'published_at' => now()->subDay(),
        ]);

        Product::create([
            'slug' => 'future-product',
            'name_ar' => 'منتج مستقبلي',
            'name_en' => 'Future Product',
            'status' => 'active',
            'published_at' => now()->addDay(),
        ]);

        $this->getJson('/api/products/missing-product')->assertNotFound();
        $this->getJson('/api/products/inactive-product')->assertNotFound();
        $this->getJson('/api/products/future-product')->assertNotFound();
    }

    public function test_listing_without_search_returns_the_normal_product_listing(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_search_matches_english_terms_case_insensitively_and_partially(): void
    {
        $this->getJson('/api/products?q=coffee')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/products?q=COFFEE')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/products?q=coff')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');
    }

    public function test_search_matches_arabic_terms_and_slug_fragments(): void
    {
        $this->getJson('/api/products?q=ماكينة')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/products?q=بطانية')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'soft-sofa-throw-blanket');

        $this->getJson('/api/products?q=espresso-coffee')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');
    }

    public function test_multiple_search_words_and_whitespace_are_normalized(): void
    {
        $this->getJson('/api/products?q=coffee%20machine')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/products?q=%20%20coffee%20%20%20machine%20')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');
    }

    public function test_search_returns_empty_results_and_preserves_pagination_metadata(): void
    {
        $response = $this->getJson('/api/products?q=does-not-exist&per_page=1');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_search_excludes_inactive_and_unpublished_matching_products(): void
    {
        Product::create([
            'slug' => 'inactive-coffee',
            'name_ar' => 'قهوة مخفية',
            'name_en' => 'Inactive Coffee',
            'status' => 'inactive',
            'published_at' => now()->subDay(),
        ]);

        Product::create([
            'slug' => 'future-coffee',
            'name_ar' => 'قهوة مستقبلية',
            'name_en' => 'Future Coffee',
            'status' => 'active',
            'published_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/products?q=coffee');

        $response->assertOk();
        $slugs = $response->json('data.*.slug');
        $this->assertSame(['espresso-coffee-machine'], $slugs);
    }

    public function test_search_rejects_long_and_non_string_queries(): void
    {
        $this->getJson('/api/products?q='.str_repeat('a', 101))
            ->assertUnprocessable();

        $this->getJson('/api/products?q%5B%5D=coffee')
            ->assertUnprocessable();
    }

    public function test_search_treats_sql_wildcards_as_literal_characters(): void
    {
        $this->getJson('/api/products?q=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/products?q=_')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/products?q=%5C')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
