<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeProductsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_home_products_returns_the_stable_section_contract_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/home/products');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'featured' => [['id', 'slug', 'name', 'category', 'price', 'originalPrice', 'badge', 'inStock', 'image']],
                    'bestSelling',
                    'newest',
                    'offers',
                ],
            ])
            ->assertJsonPath('data.offers', []);

        $this->assertLessThanOrEqual(8, count($response->json('data.featured')));
        $this->assertLessThanOrEqual(8, count($response->json('data.bestSelling')));
        $this->assertLessThanOrEqual(8, count($response->json('data.newest')));
    }

    public function test_limit_is_bounded_and_unknown_query_parameters_are_rejected(): void
    {
        $limited = $this->getJson('/api/v1/home/products?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.featured')
            ->assertJsonCount(1, 'data.newest');

        $this->assertLessThanOrEqual(1, count($limited->json('data.bestSelling')));

        foreach (['limit=0', 'limit=21', 'limit=1.5', 'limit[]=1', 'unexpected=1'] as $query) {
            $this->getJson('/api/v1/home/products?'.$query)
                ->assertUnprocessable();
        }
    }

    public function test_featured_and_newest_use_existing_visibility_and_deterministic_ordering(): void
    {
        $hidden = Product::create([
            'slug' => 'hidden-home-product',
            'name_ar' => 'Hidden',
            'name_en' => 'Hidden',
            'status' => 'inactive',
            'is_featured' => true,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/home/products?limit=20')->assertOk();
        $allSlugs = collect($response->json('data.featured'))
            ->merge($response->json('data.newest'))
            ->pluck('slug');

        $this->assertNotContains($hidden->slug, $allSlugs->all());
        $this->assertSame(['soft-sofa-throw-blanket'], collect($response->json('data.featured'))->pluck('slug')->all());
    }

    public function test_best_selling_uses_delivered_product_sales_and_excludes_zero_sales_products(): void
    {
        $blanket = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $coffee = Product::query()->where('slug', 'espresso-coffee-machine')->firstOrFail();

        $delivered = Order::factory()->create(['status' => OrderStatus::Delivered]);
        OrderItem::factory()->create([
            'order_id' => $delivered->id,
            'product_id' => $blanket->id,
            'quantity' => 4,
        ]);
        OrderItem::factory()->create([
            'order_id' => $delivered->id,
            'product_id' => $coffee->id,
            'quantity' => 2,
        ]);

        $pending = Order::factory()->create(['status' => OrderStatus::PendingConfirmation]);
        OrderItem::factory()->create([
            'order_id' => $pending->id,
            'product_id' => $coffee->id,
            'quantity' => 100,
        ]);

        $slugs = collect($this->getJson('/api/v1/home/products?limit=20')->assertOk()->json('data.bestSelling'))
            ->pluck('slug')->all();

        $this->assertSame(['soft-sofa-throw-blanket', 'espresso-coffee-machine'], $slugs);
    }

    public function test_home_sections_keep_localized_product_card_values_and_use_bounded_queries(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $response = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/home/products?limit=8')
            ->assertOk();

        $this->assertSame('Soft Sofa Throw Blanket', $response->json('data.featured.0.name'));
        $this->assertLessThan(30, $queries);
    }
}
