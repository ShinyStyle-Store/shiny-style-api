<?php

namespace Tests\Feature\Api;

use App\Models\Offer;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductOffersPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-10T12:00:00Z'));
        $this->seed(ProductCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_listing_and_detail_use_offer_price_and_base_price_as_comparison_price(): void
    {
        $product = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $offer = $this->offer($product, '10.00');

        $listing = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products?per_page=100')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->json('data');
        $card = collect($listing)->firstWhere('slug', $product->slug);

        $this->assertSame(585, $card['price']);
        $this->assertSame(650, $card['originalPrice']);
        $this->assertTrue($card['offerApplied']);
        $this->assertSame((string) $offer->id, $card['offerId']);
        $this->assertSame(10, $card['discountPercentage']);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.sellableItems.0.price', 585)
            ->assertJsonPath('data.sellableItems.0.originalPrice', 650)
            ->assertJsonPath('data.sellableItems.0.offerId', (string) $offer->id)
            ->assertJsonPath('data.sellableItems.0.discountPercentage', 10);
    }

    public function test_non_offered_product_preserves_existing_comparison_price_contract(): void
    {
        $response = $this->getJson('/api/v1/products/espresso-coffee-machine')
            ->assertOk();

        $response->assertJsonPath('data.price', 8500)
            ->assertJsonPath('data.originalPrice', 9500)
            ->assertJsonPath('data.offerApplied', false)
            ->assertJsonPath('data.offerId', null)
            ->assertJsonPath('data.discountPercentage', null);
    }

    public function test_offers_section_and_home_offers_include_each_visible_product_once(): void
    {
        $product = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $this->offer($product, '10.00');

        $section = $this->getJson('/api/v1/products/sections/offers?per_page=100')
            ->assertOk();
        $section->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $product->slug)
            ->assertJsonPath('data.0.price', 585)
            ->assertJsonPath('meta.total', 1);

        $home = $this->getJson('/api/v1/home/products?limit=20')->assertOk();
        $home->assertJsonCount(1, 'data.offers')
            ->assertJsonPath('data.offers.0.slug', $product->slug)
            ->assertJsonPath('data.offers.0.price', 585);
    }

    public function test_offer_section_respects_exact_start_and_exclusive_end(): void
    {
        $product = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $this->offer($product, '10.00', '2026-01-10T12:00:00Z', '2026-01-10T13:00:00Z');

        $this->getJson('/api/v1/products/sections/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-10T13:00:00Z'));

        $this->getJson('/api/v1/products/sections/offers')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_out_of_stock_active_variants_remain_visible_but_are_not_marked_in_stock(): void
    {
        $product = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $product->sellableItems()->update(['stock_quantity' => 0]);
        $this->offer($product, '10.00');

        $this->getJson('/api/v1/products/sections/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inStock', false)
            ->assertJsonPath('data.0.price', 585);
    }

    public function test_disabled_and_unpublished_products_do_not_enter_offers_section(): void
    {
        $published = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $this->offer($published, '10.00', '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z', false);

        $unpublished = Product::query()->where('slug', 'espresso-coffee-machine')->firstOrFail();
        $unpublished->update(['published_at' => CarbonImmutable::now()->addDay()]);
        $this->offer($unpublished, '10.00');

        $this->getJson('/api/v1/products/sections/offers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_public_catalog_pricing_uses_a_bounded_batch_of_offer_queries(): void
    {
        $product = Product::query()->where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $this->offer($product, '10.00');

        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/products?per_page=100')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertJsonCount(2, 'data');
        $this->assertLessThan(15, $queries);
    }

    private function offer(Product $product, string $percentage, string $startsAt = '2026-01-01T00:00:00Z', string $endsAt = '2026-02-01T00:00:00Z', bool $enabled = true): Offer
    {
        $offer = Offer::query()->create([
            'name' => 'Catalog offer',
            'discount_percentage' => $percentage,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_enabled' => $enabled,
        ]);
        $offer->products()->attach($product->getKey());

        return $offer;
    }
}
