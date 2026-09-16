<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_non_deleted_default_variant_for_a_product_is_rejected(): void
    {
        $product = $this->createProduct();
        $this->createVariant($product, ['is_default' => true]);

        $this->expectException(QueryException::class);

        $this->createVariant($product, ['is_default' => true]);
    }

    public function test_different_products_may_each_have_a_default_variant(): void
    {
        $this->createVariant($this->createProduct(), ['is_default' => true]);
        $this->createVariant($this->createProduct(), ['is_default' => true]);

        $this->assertSame(2, SellableItem::where('is_default', true)->count());
    }

    public function test_a_soft_deleted_default_variant_allows_another_default_for_the_same_product(): void
    {
        $product = $this->createProduct();
        $deletedDefault = $this->createVariant($product, ['is_default' => true]);
        $deletedDefault->delete();

        $replacementDefault = $this->createVariant($product, ['is_default' => true]);

        $this->assertTrue($replacementDefault->exists);
    }

    public function test_a_second_primary_category_for_a_product_is_rejected(): void
    {
        $product = $this->createProduct();
        $firstCategory = $this->createCategory();
        $secondCategory = $this->createCategory();
        $product->categories()->attach($firstCategory, ['is_primary' => true]);

        $this->expectException(QueryException::class);

        $product->categories()->attach($secondCategory, ['is_primary' => true]);
    }

    public function test_different_products_may_each_have_a_primary_category(): void
    {
        $firstProduct = $this->createProduct();
        $secondProduct = $this->createProduct();
        $firstCategory = $this->createCategory();
        $secondCategory = $this->createCategory();

        $firstProduct->categories()->attach($firstCategory, ['is_primary' => true]);
        $secondProduct->categories()->attach($secondCategory, ['is_primary' => true]);

        $this->assertSame(2, DB::table('category_product')->where('is_primary', true)->count());
    }

    private function createProduct(): Product
    {
        return Product::create([
            'slug' => 'integrity-product-'.uniqid(),
            'name_ar' => 'منتج اختبار',
            'name_en' => 'Integrity Test Product',
            'status' => 'active',
            'published_at' => now()->subDay(),
        ]);
    }

    private function createVariant(Product $product, array $attributes = []): SellableItem
    {
        return SellableItem::create(array_merge([
            'product_id' => $product->getKey(),
            'sku' => 'INTEGRITY-'.strtoupper(uniqid()),
            'price' => 100,
            'stock_quantity' => 1,
            'status' => 'active',
            'is_default' => false,
        ], $attributes));
    }

    private function createCategory(): Category
    {
        return Category::create([
            'slug' => 'integrity-category-'.uniqid(),
            'name_ar' => 'تصنيف اختبار',
            'name_en' => 'Integrity Test Category',
            'status' => 'active',
        ]);
    }
}
