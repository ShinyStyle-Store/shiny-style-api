<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_all_canonical_sections_have_the_paginated_product_contract(): void
    {
        foreach (['featured', 'newest', 'best-selling', 'offers'] as $section) {
            $response = $this->getJson('/api/v1/products/sections/'.$section.'?page=1&per_page=1');

            $response->assertOk()
                ->assertJsonStructure([
                    'data',
                    'links' => ['first', 'last', 'prev', 'next'],
                    'meta' => ['current_page', 'per_page', 'total'],
                ])
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.per_page', 1);
        }
    }

    public function test_featured_section_uses_visibility_and_featured_rules(): void
    {
        $response = $this->getJson('/api/v1/products/sections/featured?per_page=100')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'soft-sofa-throw-blanket');
    }

    public function test_newest_section_orders_by_published_at_then_id(): void
    {
        $blanket = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $coffee = Product::query()->where('slug', 'espresso-coffee-machine')->firstOrFail();

        $blanket->update(['published_at' => '2026-01-01 00:00:00']);
        $coffee->update(['published_at' => '2026-02-01 00:00:00']);

        $this->getJson('/api/v1/products/sections/newest?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine')
            ->assertJsonPath('data.1.slug', 'soft-sofa-throw-blanket');
    }

    public function test_best_selling_aggregates_delivered_sales_and_paginates_products(): void
    {
        $blanket = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $coffee = Product::query()->where('slug', 'espresso-coffee-machine')->firstOrFail();

        $first = Order::factory()->create(['status' => OrderStatus::Delivered]);
        OrderItem::factory()->create(['order_id' => $first->id, 'product_id' => $blanket->id, 'quantity' => 2]);
        OrderItem::factory()->create(['order_id' => $first->id, 'product_id' => $coffee->id, 'quantity' => 1]);

        $second = Order::factory()->create(['status' => OrderStatus::Delivered]);
        OrderItem::factory()->create(['order_id' => $second->id, 'product_id' => $blanket->id, 'quantity' => 3]);
        OrderItem::factory()->create(['order_id' => $second->id, 'product_id' => null, 'quantity' => 100]);

        $cancelled = Order::factory()->create(['status' => OrderStatus::Cancelled]);
        OrderItem::factory()->create(['order_id' => $cancelled->id, 'product_id' => $coffee->id, 'quantity' => 100]);

        $response = $this->getJson('/api/v1/products/sections/best-selling?page=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.slug', 'soft-sofa-throw-blanket');

        $this->assertArrayNotHasKey('soldQuantity', $response->json('data.0'));

        $this->getJson('/api/v1/products/sections/best-selling?page=2&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'espresso-coffee-machine');
    }

    public function test_offers_is_an_empty_paginated_placeholder(): void
    {
        $this->getJson('/api/v1/products/sections/offers?per_page=7')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 7)
            ->assertJsonPath('links.next', null);
    }

    public function test_section_parameters_and_route_values_are_strict(): void
    {
        foreach (['random', 'FEATURED', 'best_selling'] as $section) {
            $this->getJson('/api/v1/products/sections/'.$section)->assertNotFound();
        }

        foreach (['page=0', 'page=1.5', 'page[]=1', 'per_page=0', 'per_page=101', 'per_page=1.5', 'per_page[]=1', 'unknown=1'] as $query) {
            $this->getJson('/api/v1/products/sections/featured?'.$query)->assertUnprocessable();
        }

        $this->getJson('/api/v1/products/featured')->assertNotFound();
    }

    public function test_section_localization_and_product_detail_routes_remain_available(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products/sections/featured')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Soft Sofa Throw Blanket');

        $this->getJson('/api/v1/products/soft-sofa-throw-blanket')
            ->assertOk()
            ->assertJsonPath('data.slug', 'soft-sofa-throw-blanket');
    }
}
