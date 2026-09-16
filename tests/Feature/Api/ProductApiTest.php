<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
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

    public function test_empty_and_unsupported_locale_headers_fall_back_to_arabic(): void
    {
        $this->withHeader('Accept-Language', '')
            ->getJson('/api/v1/products/soft-sofa-throw-blanket')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.name', 'بطانية كنبة ناعمة');

        $this->withHeader('Accept-Language', 'fr-FR')
            ->getJson('/api/v1/products/soft-sofa-throw-blanket')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.name', 'بطانية كنبة ناعمة');
    }

    public function test_english_and_regional_comma_separated_headers_select_english(): void
    {
        foreach (['en', 'en-US', 'en-US,en;q=0.9'] as $header) {
            $response = $this->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/products/espresso-coffee-machine');

            $response->assertOk()
                ->assertHeader('Content-Language', 'en')
                ->assertJsonPath('data.name', 'Espresso Coffee Machine')
                ->assertJsonPath('data.description', 'A compact espresso coffee machine for rich coffee at home.');

            $varyTokens = array_map('trim', explode(',', (string) $response->headers->get('Vary')));

            $this->assertContains('Accept-Language', $varyTokens);
        }
    }

    public function test_arabic_regional_and_comma_separated_headers_select_arabic(): void
    {
        foreach (['ar-EG', 'ar-EG,ar;q=0.9'] as $header) {
            $this->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/products/espresso-coffee-machine')
                ->assertOk()
                ->assertHeader('Content-Language', 'ar')
                ->assertJsonPath('data.name', 'ماكينة قهوة إسبريسو');
        }
    }

    public function test_public_resources_return_single_localized_values(): void
    {
        $response = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/products/soft-sofa-throw-blanket')
            ->assertOk()
            ->assertJsonPath('data.name', 'بطانية كنبة ناعمة')
            ->assertJsonPath('data.description', 'بطانية ناعمة تضيف الدفء والراحة إلى أي كنبة.')
            ->assertJsonPath('data.category.name', 'منسوجات منزلية')
            ->assertJsonPath('data.options.0.name', 'اللون')
            ->assertJsonPath('data.options.0.values.0.label', 'بيج');

        $this->assertIsString($response->json('data.name'));
        $this->assertIsString($response->json('data.description'));

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products/soft-sofa-throw-blanket')
            ->assertJsonPath('data.category.name', 'Home Textiles')
            ->assertJsonPath('data.options.0.name', 'Color')
            ->assertJsonPath('data.options.0.values.0.label', 'Beige');
    }

    public function test_localized_fields_fall_back_without_changing_legacy_json(): void
    {
        $arabicFallback = $this->createProductWithVariant([
            'slug' => 'arabic-fallback-product',
            'name_ar' => 'اسم عربي',
            'name_en' => '',
            'description_ar' => 'وصف عربي',
            'description_en' => '',
        ]);
        $englishFallback = $this->createProductWithVariant([
            'slug' => 'english-fallback-product',
            'name_ar' => '',
            'name_en' => 'English Name',
            'description_ar' => '',
            'description_en' => 'English Description',
        ]);
        $bothMissing = $this->createProductWithVariant([
            'slug' => 'missing-localized-product',
            'name_ar' => '',
            'name_en' => '',
            'description_ar' => null,
            'description_en' => null,
        ]);
        $bilingualJson = $this->createProductWithVariant([
            'slug' => 'bilingual-json-product',
            'features' => ['ar' => ['ميزة عربية'], 'en' => ['English feature']],
            'specifications' => ['ar' => ['اللون' => 'أحمر'], 'en' => ['color' => 'Red']],
        ]);
        $legacyJson = $this->createProductWithVariant([
            'slug' => 'legacy-json-product',
            'features' => ['Legacy feature'],
            'specifications' => ['material' => 'Cotton'],
        ]);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products/'.$arabicFallback->slug)
            ->assertJsonPath('data.name', 'اسم عربي')
            ->assertJsonPath('data.description', 'وصف عربي');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/products/'.$englishFallback->slug)
            ->assertJsonPath('data.name', 'English Name')
            ->assertJsonPath('data.description', 'English Description');

        $this->getJson('/api/v1/products/'.$bothMissing->slug)
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.description', null);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products/'.$bilingualJson->slug)
            ->assertJsonPath('data.features', ['English feature'])
            ->assertJsonPath('data.specifications', ['color' => 'Red']);

        $this->getJson('/api/v1/products/'.$legacyJson->slug)
            ->assertJsonPath('data.features', ['Legacy feature'])
            ->assertJsonPath('data.specifications', ['material' => 'Cotton']);
    }

    public function test_unversioned_catalog_routes_are_not_available(): void
    {
        $this->getJson('/api/products')->assertNotFound();
        $this->getJson('/api/products/featured')->assertNotFound();
        $this->getJson('/api/products/soft-sofa-throw-blanket')->assertNotFound();
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

        $response = $this->getJson('/api/v1/products?per_page=1&page=1');

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

    public function test_product_pagination_links_preserve_filters_and_page_changes(): void
    {
        $searchProduct = $this->createProductWithVariant([
            'slug' => 'second-coffee-product',
            'name_en' => 'Second Coffee Product',
        ]);
        $category = Category::where('slug', 'home-textiles')->firstOrFail();
        $searchProduct->categories()->attach($category, ['is_primary' => false]);

        $response = $this->getJson('/api/v1/products?per_page=1&page=1');
        $nextQuery = [];
        parse_str((string) parse_url((string) $response->json('links.next'), PHP_URL_QUERY), $nextQuery);

        $this->assertSame('1', (string) $nextQuery['per_page']);
        $this->assertSame('2', (string) $nextQuery['page']);

        $response = $this->getJson('/api/v1/products?q=coffee&per_page=1&page=1');
        $nextQuery = [];
        parse_str((string) parse_url((string) $response->json('links.next'), PHP_URL_QUERY), $nextQuery);

        $this->assertSame('coffee', $nextQuery['q']);
        $this->assertSame('1', (string) $nextQuery['per_page']);
        $this->assertSame('2', (string) $nextQuery['page']);

        $response = $this->getJson('/api/v1/products?category=home-textiles&per_page=1&page=1');
        $nextQuery = [];
        parse_str((string) parse_url((string) $response->json('links.next'), PHP_URL_QUERY), $nextQuery);

        $this->assertSame('home-textiles', $nextQuery['category']);
        $this->assertSame('1', (string) $nextQuery['per_page']);
        $this->assertSame('2', (string) $nextQuery['page']);
    }

    public function test_listing_uses_default_sellable_item_and_primary_image(): void
    {
        $response = $this->getJson('/api/v1/products?per_page=100');
        $product = collect($response->json('data'))->firstWhere('slug', 'soft-sofa-throw-blanket');

        $this->assertSame(650, $product['price']);
        $this->assertSame(750, $product['originalPrice']);
        $this->assertSame('https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg', $product['image']);
    }

    public function test_featured_endpoint_returns_only_visible_featured_products(): void
    {
        $response = $this->getJson('/api/v1/products/featured');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('soft-sofa-throw-blanket', $response->json('data.0.slug'));
    }

    public function test_product_details_return_options_combinations_and_default_item(): void
    {
        $response = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products/soft-sofa-throw-blanket');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Soft Sofa Throw Blanket')
            ->assertJsonPath('data.description', 'A soft throw blanket that adds warmth and comfort to any sofa.')
            ->assertJsonCount(2, 'data.options')
            ->assertJsonCount(4, 'data.sellableItems')
            ->assertJsonPath('data.sellableItems.0.optionValues.color', 'beige')
            ->assertJsonPath('data.sellableItems.0.optionValues.size', 'medium')
            ->assertJsonPath('data.gallery.0', 'https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg');

        $this->assertIsString($response->json('data.defaultSellableItemId'));
        $this->assertArrayNotHasKey('stock_quantity', $response->json('data.sellableItems.0'));
        $this->assertArrayNotHasKey('created_at', $response->json('data'));

        $payload = json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class, $payload->data->sellableItems[0]->optionValues);
        $this->assertSame('beige', $payload->data->sellableItems[0]->optionValues->color);
        $this->assertSame('medium', $payload->data->sellableItems[0]->optionValues->size);
    }

    public function test_coffee_machine_has_no_options_or_option_values(): void
    {
        $response = $this->getJson('/api/v1/products/espresso-coffee-machine');

        $response->assertOk()
            ->assertJsonCount(0, 'data.options')
            ->assertJsonCount(1, 'data.sellableItems')
            ->assertJsonPath('data.sellableItems.0.sku', 'COFFEE-MACHINE-001');

        $payload = json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class, $payload->data->sellableItems[0]->optionValues);
        $this->assertSame([], (array) $payload->data->sellableItems[0]->optionValues);
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

        $this->getJson('/api/v1/products/missing-product')->assertNotFound();
        $this->getJson('/api/v1/products/inactive-product')->assertNotFound();
        $this->getJson('/api/v1/products/future-product')->assertNotFound();
    }

    public function test_unpublished_products_are_hidden_from_listing_search_and_featured(): void
    {
        $nullPublished = $this->createProductWithVariant([
            'slug' => 'null-published-product',
            'name_en' => 'Null Published Coffee',
            'is_featured' => true,
            'published_at' => null,
        ]);
        $futurePublished = $this->createProductWithVariant([
            'slug' => 'future-published-product',
            'name_en' => 'Future Published Coffee',
            'is_featured' => true,
            'published_at' => now()->addDay(),
        ]);

        foreach ([$nullPublished, $futurePublished] as $product) {
            $this->getJson('/api/v1/products')
                ->assertOk()
                ->assertJsonMissing(['slug' => $product->slug]);

            $this->getJson('/api/v1/products?q='.urlencode($product->name_en))
                ->assertOk()
                ->assertJsonMissing(['slug' => $product->slug]);

            $this->getJson('/api/v1/products/featured')
                ->assertOk()
                ->assertJsonMissing(['slug' => $product->slug]);

            $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
        }
    }

    public function test_products_without_active_variants_are_hidden_from_all_public_endpoints(): void
    {
        $withoutVariants = Product::create([
            'slug' => 'without-variants',
            'name_ar' => 'بدون متغيرات',
            'name_en' => 'Without Variants',
            'status' => 'active',
            'is_featured' => true,
            'published_at' => now()->subDay(),
        ]);

        $inactiveVariantProduct = $this->createProductWithVariant([
            'slug' => 'inactive-only-variant',
            'name_en' => 'Inactive Only Variant',
            'is_featured' => true,
        ], ['status' => 'inactive']);

        $softDeletedVariantProduct = $this->createProductWithVariant([
            'slug' => 'deleted-only-variant',
            'name_en' => 'Deleted Only Variant',
            'is_featured' => true,
        ]);
        $softDeletedVariantProduct->sellableItems()->firstOrFail()->delete();

        foreach ([$withoutVariants, $inactiveVariantProduct, $softDeletedVariantProduct] as $product) {
            $this->getJson('/api/v1/products')
                ->assertOk()
                ->assertJsonMissing(['slug' => $product->slug]);

            $this->getJson('/api/v1/products?q='.urlencode($product->name_en))
                ->assertOk()
                ->assertJsonMissing(['slug' => $product->slug]);

            $this->getJson('/api/v1/products/featured')
                ->assertOk()
                ->assertJsonMissing(['slug' => $product->slug]);

            $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
        }
    }

    public function test_active_out_of_stock_variants_remain_public(): void
    {
        $product = $this->createProductWithVariant([
            'slug' => 'out-of-stock-product',
            'name_en' => 'Out of Stock Product',
            'is_featured' => true,
        ], ['stock_quantity' => 0]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonFragment(['slug' => $product->slug]);

        $this->getJson('/api/v1/products?q=out-of-stock-product')
            ->assertOk()
            ->assertJsonFragment(['slug' => $product->slug]);

        $this->getJson('/api/v1/products/featured')
            ->assertOk()
            ->assertJsonFragment(['slug' => $product->slug]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.inStock', false);
    }

    public function test_product_details_exclude_inactive_and_soft_deleted_variants(): void
    {
        $product = $this->createProductWithVariant(['slug' => 'variant-filtering-product']);
        $activeVariant = $product->sellableItems()->firstOrFail();
        $inactiveVariant = $this->addVariant($product, [
            'status' => 'inactive',
            'sku' => 'INACTIVE-'.strtoupper(uniqid()),
            'is_default' => false,
        ]);
        $deletedVariant = $this->addVariant($product, [
            'sku' => 'DELETED-'.strtoupper(uniqid()),
            'is_default' => false,
        ]);
        $deletedVariant->delete();

        $response = $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonCount(1, 'data.sellableItems');

        $this->assertSame(
            [(string) $activeVariant->getKey()],
            $response->json('data.sellableItems.*.id'),
        );
        $this->assertNotSame((string) $inactiveVariant->getKey(), $response->json('data.sellableItems.0.id'));
        $this->assertNotSame((string) $deletedVariant->getKey(), $response->json('data.sellableItems.0.id'));
    }

    public function test_active_default_variant_supplies_display_fields_even_when_out_of_stock(): void
    {
        $product = $this->createProductWithVariant(
            ['slug' => 'default-out-of-stock-product'],
            ['price' => 125, 'original_price' => 150, 'stock_quantity' => 0],
        );
        $this->addVariant($product, [
            'sku' => 'IN-STOCK-'.strtoupper(uniqid()),
            'price' => 200,
            'original_price' => 225,
            'stock_quantity' => 5,
            'is_default' => false,
            'sort_order' => 2,
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.price', 125)
            ->assertJsonPath('data.originalPrice', 150)
            ->assertJsonPath('data.inStock', true)
            ->assertJsonPath('data.defaultSellableItemId', (string) $product->sellableItems()->orderBy('id')->firstOrFail()->getKey());
    }

    public function test_inactive_default_falls_back_to_first_active_in_stock_variant_by_id(): void
    {
        $product = $this->createProductWithVariant(
            ['slug' => 'inactive-default-product'],
            ['status' => 'inactive', 'price' => 50, 'original_price' => 60, 'stock_quantity' => 10],
        );
        $firstActiveInStock = $this->addVariant($product, [
            'sku' => 'ACTIVE-FIRST-'.strtoupper(uniqid()),
            'price' => 175,
            'original_price' => 200,
            'stock_quantity' => 3,
            'is_default' => false,
        ]);
        $this->addVariant($product, [
            'sku' => 'ACTIVE-SECOND-'.strtoupper(uniqid()),
            'price' => 250,
            'original_price' => 275,
            'stock_quantity' => 8,
            'is_default' => false,
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.price', 175)
            ->assertJsonPath('data.originalPrice', 200)
            ->assertJsonPath('data.defaultSellableItemId', (string) $firstActiveInStock->getKey())
            ->assertJsonPath('data.inStock', true);
    }

    public function test_missing_default_uses_first_active_in_stock_variant_by_id(): void
    {
        $product = Product::create([
            'slug' => 'missing-default-product',
            'name_ar' => 'منتج بدون افتراضي',
            'name_en' => 'Missing Default Product',
            'status' => 'active',
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create([
            'slug' => 'missing-default-category-'.uniqid(),
            'name_ar' => 'تصنيف بدون افتراضي',
            'name_en' => 'Missing Default Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $this->addVariant($product, [
            'sku' => 'OUT-OF-STOCK-'.strtoupper(uniqid()),
            'stock_quantity' => 0,
            'is_default' => false,
        ]);
        $firstActiveInStock = $this->addVariant($product, [
            'sku' => 'FIRST-IN-STOCK-'.strtoupper(uniqid()),
            'price' => 300,
            'stock_quantity' => 4,
            'is_default' => false,
        ]);
        $this->addVariant($product, [
            'sku' => 'SECOND-IN-STOCK-'.strtoupper(uniqid()),
            'price' => 400,
            'stock_quantity' => 6,
            'is_default' => false,
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.price', 300)
            ->assertJsonPath('data.defaultSellableItemId', (string) $firstActiveInStock->getKey());
    }

    public function test_all_out_of_stock_variants_use_first_active_variant_and_report_unavailable(): void
    {
        $product = Product::create([
            'slug' => 'all-out-of-stock-product',
            'name_ar' => 'منتج نافد المخزون',
            'name_en' => 'All Out Of Stock Product',
            'status' => 'active',
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create([
            'slug' => 'all-out-of-stock-category-'.uniqid(),
            'name_ar' => 'تصنيف نافد المخزون',
            'name_en' => 'All Out Of Stock Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $firstVariant = $this->addVariant($product, [
            'sku' => 'OUT-FIRST-'.strtoupper(uniqid()),
            'price' => 325,
            'stock_quantity' => 0,
            'is_default' => false,
        ]);
        $this->addVariant($product, [
            'sku' => 'OUT-SECOND-'.strtoupper(uniqid()),
            'price' => 425,
            'stock_quantity' => 0,
            'is_default' => false,
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.price', 325)
            ->assertJsonPath('data.defaultSellableItemId', (string) $firstVariant->getKey())
            ->assertJsonPath('data.inStock', false);
    }

    public function test_inactive_variant_stock_does_not_make_product_available(): void
    {
        $product = $this->createProductWithVariant(
            ['slug' => 'inactive-stock-product'],
            ['stock_quantity' => 0],
        );
        $this->addVariant($product, [
            'sku' => 'INACTIVE-STOCK-'.strtoupper(uniqid()),
            'status' => 'inactive',
            'stock_quantity' => 10,
            'is_default' => false,
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.inStock', false)
            ->assertJsonCount(1, 'data.sellableItems');
    }

    public function test_inactive_only_option_values_and_variant_media_are_hidden(): void
    {
        $product = $this->createProductWithVariant(['slug' => 'related-data-filtering-product']);
        $activeVariant = $product->sellableItems()->firstOrFail();
        $inactiveVariant = $this->addVariant($product, [
            'status' => 'inactive',
            'sku' => 'INACTIVE-RELATED-'.strtoupper(uniqid()),
            'is_default' => false,
        ]);
        $option = ProductOption::create([
            'product_id' => $product->getKey(),
            'code' => 'color',
            'name_ar' => 'اللون',
            'name_en' => 'Color',
        ]);
        $activeValue = ProductOptionValue::create([
            'product_option_id' => $option->getKey(),
            'code' => 'red',
            'value_ar' => 'أحمر',
            'value_en' => 'Red',
        ]);
        $inactiveValue = ProductOptionValue::create([
            'product_option_id' => $option->getKey(),
            'code' => 'blue',
            'value_ar' => 'أزرق',
            'value_en' => 'Blue',
        ]);
        $activeVariant->optionValues()->sync([$activeValue->getKey()]);
        $inactiveVariant->optionValues()->sync([$inactiveValue->getKey()]);
        ProductMedia::create([
            'product_id' => $product->getKey(),
            'sellable_item_id' => $inactiveVariant->getKey(),
            'provider' => 'cloudinary',
            'type' => 'image',
            'public_id' => 'inactive-only-media-'.uniqid(),
            'secure_url' => 'https://example.com/inactive-only.jpg',
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.options.0.values.0.code', 'red')
            ->assertJsonMissing(['code' => 'blue'])
            ->assertJsonMissing(['secure_url' => 'https://example.com/inactive-only.jpg'])
            ->assertJsonMissing(['gallery.0' => 'https://example.com/inactive-only.jpg']);
    }

    private function createProductWithVariant(array $productAttributes = [], array $variantAttributes = []): Product
    {
        $product = Product::create(array_merge([
            'slug' => 'test-product-'.uniqid(),
            'name_ar' => 'منتج اختباري',
            'name_en' => 'Test Product',
            'status' => 'active',
            'is_featured' => false,
            'published_at' => now()->subDay(),
        ], $productAttributes));
        $category = Category::create([
            'slug' => 'product-test-category-'.uniqid(),
            'name_ar' => 'تصنيف اختبار المنتج',
            'name_en' => 'Product Test Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);

        $this->addVariant($product, $variantAttributes);

        return $product;
    }

    private function addVariant(Product $product, array $variantAttributes = []): SellableItem
    {
        return SellableItem::create(array_merge([
            'product_id' => $product->getKey(),
            'sku' => 'TEST-'.strtoupper(uniqid()),
            'price' => 100,
            'stock_quantity' => 1,
            'status' => 'active',
            'is_default' => true,
            'sort_order' => 1,
        ], $variantAttributes));
    }

    public function test_listing_without_search_returns_the_normal_product_listing(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_search_matches_english_terms_case_insensitively_and_partially(): void
    {
        $this->getJson('/api/v1/products?q=coffee')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/v1/products?q=COFFEE')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/v1/products?q=coff')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');
    }

    public function test_search_matches_arabic_terms_and_slug_fragments(): void
    {
        $this->getJson('/api/v1/products?q=ماكينة')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/v1/products?q=بطانية')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'soft-sofa-throw-blanket');

        $this->getJson('/api/v1/products?q=espresso-coffee')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');
    }

    public function test_multiple_search_words_and_whitespace_are_normalized(): void
    {
        $this->getJson('/api/v1/products?q=coffee%20machine')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');

        $this->getJson('/api/v1/products?q=%20%20coffee%20%20%20machine%20')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');
    }

    public function test_search_returns_empty_results_and_preserves_pagination_metadata(): void
    {
        $response = $this->getJson('/api/v1/products?q=does-not-exist&per_page=1');

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

        $response = $this->getJson('/api/v1/products?q=coffee');

        $response->assertOk();
        $slugs = $response->json('data.*.slug');
        $this->assertSame(['espresso-coffee-machine'], $slugs);
    }

    public function test_search_rejects_long_and_non_string_queries(): void
    {
        $this->getJson('/api/v1/products?q='.str_repeat('a', 101))
            ->assertUnprocessable();

        $this->getJson('/api/v1/products?q%5B%5D=coffee')
            ->assertUnprocessable();
    }

    public function test_search_treats_sql_wildcards_as_literal_characters(): void
    {
        $this->getJson('/api/v1/products?q=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/products?q=_')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/products?q=%5C')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
