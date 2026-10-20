<?php

namespace Tests\Feature\Api;

use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
use App\Services\OfferPricingService;
use App\Support\ExactMoney;
use Carbon\CarbonImmutable;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductCatalogFilteringTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $instant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instant = CarbonImmutable::parse('2026-01-15T12:00:00Z');
        $this->travelTo($this->instant);
        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_search_filters_the_catalog_before_pagination(): void
    {
        $matching = $this->createCatalogProduct('older-needle-match', '125.00', $this->instant->subDays(3));
        $this->createCatalogProduct('newer-unrelated-one', '100.00', $this->instant->subDay());
        $this->createCatalogProduct('newer-unrelated-two', '150.00', $this->instant->subHours(1));

        $unfilteredIds = $this->getJson('/api/v1/products?per_page=1')->assertOk()->json('data.*.id');
        $response = $this->getJson('/api/v1/products?q=Needle&per_page=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotContains((string) $matching->getKey(), $unfilteredIds);
        $this->assertSame((string) $matching->getKey(), $response->json('data.0.id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_price_bounds_filter_before_pagination_and_page_two_is_stable(): void
    {
        $this->createCatalogProduct('below-price-range', '199.99', $this->instant->subHours(4));
        $firstMatch = $this->createCatalogProduct('first-price-match', '200.00', $this->instant->subHours(3));
        $secondMatch = $this->createCatalogProduct('second-price-match', '300.00', $this->instant->subHours(2));
        $this->createCatalogProduct('above-price-range', '300.01', $this->instant->subHour());
        $unfilteredFirstId = $this->getJson('/api/v1/products?per_page=1')->assertOk()->json('data.0.id');

        $firstPage = $this->getJson('/api/v1/products?min_price=200.00&max_price=300&sort=price_asc&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1);
        $secondPage = $this->getJson('/api/v1/products?min_price=200.00&max_price=300&sort=price_asc&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 2);

        $this->assertSame((string) $firstMatch->getKey(), $firstPage->json('data.0.id'));
        $this->assertNotSame((string) $firstMatch->getKey(), $unfilteredFirstId);
        $this->assertSame((string) $secondMatch->getKey(), $secondPage->json('data.0.id'));
        $this->getJson('/api/v1/products?min_price=200&max_price=300&sort=price_asc&per_page=1&page=3')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_color_filter_uses_matching_variant_for_price_offer_sku_and_out_of_stock_state(): void
    {
        $product = $this->createCatalogProduct('color-variant-match', '200.00', $this->instant->subHours(2));
        $defaultVariant = $product->sellableItems()->firstOrFail();
        $beige = $this->colorValue($product, 'navy-blue', 'كحلي', 'Navy blue');
        $grey = ProductOptionValue::query()->where('product_option_id', $beige->product_option_id)
            ->where('code', 'grey')->first();
        if ($grey === null) {
            $grey = ProductOptionValue::query()->create([
                'product_option_id' => $beige->product_option_id,
                'code' => 'grey', 'value_ar' => 'رمادي', 'value_en' => 'Grey',
            ]);
        }
        $defaultVariant->optionValues()->attach($grey->getKey());
        $matchingVariant = $this->addVariant($product, [
            'sku' => 'COLOR-BEIGE-OUT', 'price' => '45.00', 'stock_quantity' => 0,
            'reserved_quantity' => 0, 'is_default' => false,
        ]);
        $matchingVariant->optionValues()->attach($beige->getKey());
        $image = MediaAsset::query()->create([
            'disk' => 'public', 'path' => 'color-filter/beige-variant.jpg',
            'media_type' => 'image', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        $matchingVariant->mediaAttachments()->create([
            'media_asset_id' => $image->getKey(), 'role' => MediaRole::VARIANT_IMAGE,
            'sort_order' => 0, 'is_primary' => true,
        ]);
        $this->attachOffer($product, '20.00', $this->instant->subHour(), $this->instant->addHour());

        $response = $this->getJson('/api/v1/products?colors%5B%5D=navy-blue&min_price=36&max_price=36&offer=active')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.displayVariantId', (string) $matchingVariant->getKey())
            ->assertJsonPath('data.0.sku', 'COLOR-BEIGE-OUT')
            ->assertJsonPath('data.0.price', 36)
            ->assertJsonPath('data.0.originalPrice', 45)
            ->assertJsonPath('data.0.offerApplied', true)
            ->assertJsonPath('data.0.inStock', false)
            ->assertJsonPath('data.0.image', Storage::disk('public')->url('color-filter/beige-variant.jpg'));
        $this->assertSame((string) $product->getKey(), $response->json('data.0.id'));

        $lowerPrice = $this->createCatalogProduct('color-variant-lower-price', '30.00', $this->instant->subHours(3));
        $lowerPriceVariant = $lowerPrice->sellableItems()->firstOrFail();
        $lowerPriceVariant->optionValues()->attach(
            $this->colorValue($lowerPrice, 'navy-blue', 'كحلي', 'Navy blue')->getKey(),
        );
        $sorted = $this->getJson('/api/v1/products?colors%5B%5D=navy-blue&sort=price_asc&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->assertSame((string) $lowerPrice->getKey(), $sorted->json('data.0.id'));
        $this->assertSame(30, $sorted->json('data.0.price'));
        $this->assertSame((string) $matchingVariant->getKey(), $sorted->json('data.1.displayVariantId'));
        $this->assertSame(36, $sorted->json('data.1.price'));
    }

    public function test_color_filters_use_or_semantics_before_pagination_and_unknown_codes_return_empty(): void
    {
        $beigeProduct = $this->createCatalogProduct('color-filter-beige', '100.00', $this->instant->subHours(3));
        $greyProduct = $this->createCatalogProduct('color-filter-grey', '110.00', $this->instant->subHours(2));
        $beigeVariant = $beigeProduct->sellableItems()->firstOrFail();
        $greyVariant = $greyProduct->sellableItems()->firstOrFail();
        $beigeVariant->optionValues()->attach($this->colorValue($beigeProduct, 'rose-red', 'أحمر وردي', 'Rose red')->getKey());
        $greyVariant->optionValues()->attach($this->colorValue($greyProduct, 'forest-green', 'أخضر غامق', 'Forest green')->getKey());

        $first = $this->getJson('/api/v1/products?colors%5B%5D=rose-red&colors%5B%5D=forest-green&sort=newest&per_page=1&page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 1);
        $second = $this->getJson('/api/v1/products?colors%5B%5D=rose-red&colors%5B%5D=forest-green&sort=newest&per_page=1&page=2')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2);

        $this->assertSame((string) $greyProduct->getKey(), $first->json('data.0.id'));
        $this->assertSame((string) $beigeProduct->getKey(), $second->json('data.0.id'));
        $this->getJson('/api/v1/products?colors%5B%5D=unknown-valid-color')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products?colors%5B%5D=rose-red&colors%5B%5D=rose-red')->assertUnprocessable();
        $this->getJson('/api/v1/products?colors%5B%5D=not_valid')->assertUnprocessable();
        $this->getJson('/api/v1/products?colors=rose-red')->assertUnprocessable();
        $tooManyColors = http_build_query(['colors' => array_map(
            static fn (int $index): string => 'color-'.$index,
            range(1, 21),
        )]);
        $this->getJson('/api/v1/products?'.$tooManyColors)->assertUnprocessable();
        $this->getJson('/api/v1/products?colors%5B%5D=rose-red&collection=summer')->assertUnprocessable();
    }

    public function test_public_color_filter_options_localize_labels_and_exclude_nonvisible_products(): void
    {
        $visible = $this->createCatalogProduct('color-options-visible', '100.00', $this->instant->subHours(1));
        $visibleVariant = $visible->sellableItems()->firstOrFail();
        $navy = $this->colorValue($visible, 'navy-blue', 'كحلي', 'Navy blue');
        $visibleVariant->optionValues()->attach($navy->getKey());
        $secondVisible = $this->createCatalogProduct('color-options-visible-second', '120.00', $this->instant->subHours(1));
        $secondVisibleVariant = $secondVisible->sellableItems()->firstOrFail();
        $secondVisibleVariant->optionValues()->attach(
            $this->colorValue($secondVisible, 'navy-blue', 'كحلي', 'Navy blue')->getKey(),
        );
        $orphan = $this->colorValue($visible, 'orphan-purple', 'بنفسجي', 'Purple');
        $deletedValue = $this->colorValue($visible, 'deleted-rose', 'وردي', 'Rose');
        $deletedVariant = $this->addVariant($visible, ['is_default' => false]);
        $deletedVariant->optionValues()->attach($deletedValue->getKey());
        $deletedValue->delete();
        $supersededValue = $this->colorValue($visible, 'old-teal', 'أزرق مخضر', 'Teal');
        $supersededVariant = $this->addVariant($visible, ['is_default' => false]);
        $supersededVariant->optionValues()->attach($supersededValue->getKey());
        $supersededVariant->superseded_at = $this->instant;
        $supersededVariant->save();
        $hidden = $this->createCatalogProduct('color-options-hidden', '100.00', $this->instant->subHours(1));
        $hidden->update(['status' => 'inactive']);
        $hidden->categories()->firstOrFail()->update(['status' => 'inactive']);
        $hiddenVariant = $hidden->sellableItems()->firstOrFail();
        $hiddenVariant->optionValues()->attach($this->colorValue($hidden, 'hidden-pink', 'وردي', 'Pink')->getKey());

        $before = [];
        foreach (['products', 'categories', 'product_options', 'product_option_values', 'seo_records', 'seo_settings', 'seo_audits'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }
        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/products/filter-options')->assertOk();
        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/filter-options')->assertOk();

        $this->assertContains(['code' => 'navy-blue', 'name' => 'كحلي'], $arabic->json('data.colors'));
        $this->assertContains(['code' => 'navy-blue', 'name' => 'Navy blue'], $english->json('data.colors'));
        $this->assertSame(1, count(array_filter(
            $arabic->json('data.colors'),
            static fn (array $color): bool => $color['code'] === 'navy-blue',
        )));
        $this->assertNotContains('hidden-pink', array_column($arabic->json('data.colors'), 'code'));
        $returnedCodes = array_column($arabic->json('data.colors'), 'code');
        $this->assertNotContains($orphan->code, $returnedCodes);
        $this->assertNotContains('deleted-rose', $returnedCodes);
        $this->assertNotContains('old-teal', $returnedCodes);
        $this->assertArrayNotHasKey('id', $arabic->json('data.colors.0'));
        $this->getJson('/api/v1/products/filter-options?category=home')->assertUnprocessable();
        $after = [];
        foreach (array_keys($before) as $table) {
            $after[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }
        $this->assertSame($before, $after);
    }

    public function test_price_sort_uses_published_at_and_id_as_deterministic_tie_breakers(): void
    {
        $publishedAt = $this->instant->subDay();
        $olderId = $this->createCatalogProduct('equal-price-older-id', '250.00', $publishedAt);
        $newerId = $this->createCatalogProduct('equal-price-newer-id', '250.00', $publishedAt);

        foreach (['price_asc', 'price_desc'] as $sort) {
            $response = $this->getJson('/api/v1/products?min_price=250&max_price=250&sort='.$sort.'&per_page=1')
                ->assertOk()
                ->assertJsonPath('meta.total', 2);

            $this->assertSame((string) $newerId->getKey(), $response->json('data.0.id'));
            $next = $this->getJson('/api/v1/products?min_price=250&max_price=250&sort='.$sort.'&per_page=1&page=2')
                ->assertOk();
            $this->assertSame((string) $olderId->getKey(), $next->json('data.0.id'));
        }
    }

    public function test_price_ascending_and_descending_use_effective_displayed_prices(): void
    {
        $low = $this->createCatalogProduct('price-sort-low', '100.00', $this->instant->subHours(3));
        $middle = $this->createCatalogProduct('price-sort-middle', '200.00', $this->instant->subHours(2));
        $high = $this->createCatalogProduct('price-sort-high', '300.00', $this->instant->subHour());

        $ascending = $this->getJson('/api/v1/products?min_price=100&max_price=300&sort=price_asc&per_page=100')
            ->assertOk()
            ->json('data.*.id');
        $descending = $this->getJson('/api/v1/products?min_price=100&max_price=300&sort=price_desc&per_page=100')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([
            (string) $low->getKey(),
            (string) $middle->getKey(),
            (string) $high->getKey(),
        ], $ascending);
        $this->assertSame([
            (string) $high->getKey(),
            (string) $middle->getKey(),
            (string) $low->getKey(),
        ], $descending);
    }

    public function test_price_sort_uses_the_active_offer_price_instead_of_base_price(): void
    {
        $basePriceFirst = $this->createCatalogProduct('price-sort-base-150', '150.00', $this->instant->subHours(2));
        $offerPriceFirst = $this->createCatalogProduct('price-sort-offer-200', '200.00', $this->instant->subHour());
        $this->attachOffer($offerPriceFirst, '50.00', $this->instant->subMinute(), $this->instant->addHour());

        $ascending = $this->getJson('/api/v1/products?min_price=100&max_price=200&sort=price_asc&per_page=100')
            ->assertOk()
            ->json('data.*.id');
        $descending = $this->getJson('/api/v1/products?min_price=100&max_price=200&sort=price_desc&per_page=100')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([
            (string) $offerPriceFirst->getKey(),
            (string) $basePriceFirst->getKey(),
        ], $ascending);
        $this->assertSame([
            (string) $basePriceFirst->getKey(),
            (string) $offerPriceFirst->getKey(),
        ], $descending);
    }

    public function test_active_offer_filter_uses_enabled_start_inclusive_and_end_exclusive_window(): void
    {
        $active = $this->createCatalogProduct('catalog-active-offer', '400.00', $this->instant->subDay());
        $atStart = $this->createCatalogProduct('catalog-offer-at-start', '400.00', $this->instant->subHours(2));
        $scheduled = $this->createCatalogProduct('catalog-scheduled-offer', '400.00', $this->instant->subHours(3));
        $expired = $this->createCatalogProduct('catalog-expired-offer', '400.00', $this->instant->subHours(4));
        $disabled = $this->createCatalogProduct('catalog-disabled-offer', '400.00', $this->instant->subHours(5));

        $this->attachOffer($active, '10.00', $this->instant->subHour(), $this->instant->addHour());
        $this->attachOffer($atStart, '10.00', $this->instant, $this->instant->addHour());
        $this->attachOffer($scheduled, '10.00', $this->instant->addSecond(), $this->instant->addHour());
        $this->attachOffer($expired, '10.00', $this->instant->subHour(), $this->instant);
        $this->attachOffer($disabled, '10.00', $this->instant->subHour(), $this->instant->addHour(), false);

        $response = $this->getJson('/api/v1/products?offer=active&per_page=100')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains((string) $active->getKey(), $ids);
        $this->assertContains((string) $atStart->getKey(), $ids);
        $this->assertNotContains((string) $scheduled->getKey(), $ids);
        $this->assertNotContains((string) $expired->getKey(), $ids);
        $this->assertNotContains((string) $disabled->getKey(), $ids);
    }

    public function test_offer_price_filter_and_resource_share_the_selected_variant_and_money_rounding(): void
    {
        $product = $this->createCatalogProduct('reserved-display-variant', '900.00', $this->instant->subDay());
        $default = $product->sellableItems()->firstOrFail();
        $default->update(['stock_quantity' => 5, 'reserved_quantity' => 5]);
        $displayVariant = $this->addVariant($product, [
            'sku' => 'DISPLAY-RESERVED-'.$product->getKey(),
            'price' => '0.50',
            'stock_quantity' => 7,
            'reserved_quantity' => 2,
            'is_default' => false,
        ]);
        $superseded = $this->addVariant($product, [
            'sku' => 'SUPERSEDED-RESERVED-'.$product->getKey(),
            'price' => '1.00',
            'stock_quantity' => 99,
            'is_default' => false,
        ]);
        $superseded->forceFill(['superseded_at' => $this->instant])->save();
        $inactive = $this->addVariant($product, [
            'sku' => 'INACTIVE-RESERVED-'.$product->getKey(),
            'price' => '2.00',
            'stock_quantity' => 99,
            'status' => 'inactive',
            'is_default' => false,
        ]);
        $deleted = $this->addVariant($product, [
            'sku' => 'DELETED-RESERVED-'.$product->getKey(),
            'price' => '3.00',
            'stock_quantity' => 99,
            'is_default' => false,
        ]);
        $deleted->delete();
        $this->attachOffer($product, '1.00', $this->instant->subMinute(), $this->instant->addHour());

        $freshProduct = $product->fresh()->load('sellableItems');
        $expectedDisplayVariant = $freshProduct->displaySellableItem(
            $freshProduct->publicSellableItems($freshProduct->sellableItems),
        );
        $this->assertSame((string) $displayVariant->getKey(), (string) $expectedDisplayVariant->getKey());

        $response = $this->getJson('/api/v1/products?min_price=0.49&max_price=0.49&sort=price_asc')->assertOk();
        $card = collect($response->json('data'))->firstWhere('id', (string) $product->getKey());

        $this->assertNotNull($card);
        $this->assertSame((string) $displayVariant->getKey(), $card['displayVariantId']);
        $this->assertSame(0.49, $card['price']);
        $pricing = app(OfferPricingService::class)->calculate($displayVariant->fresh(), $this->instant);
        $this->assertSame('0.49', $pricing['effectivePrice']);
        $this->assertSame(ExactMoney::toMinorUnitInteger($pricing['effectivePrice']), ExactMoney::toMinorUnitInteger((string) number_format($card['price'], 2, '.', '')));
        $this->assertNotContains((string) $superseded->getKey(), $response->json('data.*.displayVariantId'));
        $this->assertNotContains((string) $inactive->getKey(), $response->json('data.*.displayVariantId'));
        $this->assertNotContains((string) $deleted->getKey(), $response->json('data.*.displayVariantId'));
    }

    public function test_out_of_stock_products_remain_filterable_and_category_descendants_are_included(): void
    {
        $parent = Category::create([
            'slug' => 'catalog-filter-parent',
            'name_ar' => 'تصنيف أب',
            'name_en' => 'Catalog parent',
            'status' => 'active',
        ]);
        $child = Category::create([
            'slug' => 'catalog-filter-child',
            'name_ar' => 'تصنيف فرعي',
            'name_en' => 'Catalog child',
            'status' => 'active',
            'parent_id' => $parent->getKey(),
        ]);
        $product = $this->createCatalogProduct('out-of-stock-child-product', '225.00', $this->instant->subDay(), 0);
        $product->categories()->detach();
        $product->categories()->attach($child, ['is_primary' => true]);

        $this->getJson('/api/v1/products?category='.$parent->slug.'&min_price=225&max_price=225')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $product->getKey())
            ->assertJsonPath('data.0.inStock', false);
    }

    public function test_category_listing_is_paginated_and_localized_without_deduplicating_product_columns(): void
    {
        $category = Category::query()->where('slug', 'home-textiles')->firstOrFail();
        $product = $this->createCatalogProduct('category-locale-product', '225.00', $this->instant->subDay());
        $product->categories()->attach($category, ['is_primary' => false]);

        foreach (['ar', 'en'] as $locale) {
            $localizedName = $locale === 'ar' ? $product->name_ar : $product->name_en;
            $response = $this->withHeader('Accept-Language', $locale)
                ->getJson('/api/v1/products?category=home-textiles&page=1&per_page=24')
                ->assertOk()
                ->assertHeader('Content-Language', $locale)
                ->assertJsonStructure([
                    'data' => [['id', 'slug', 'name', 'category', 'price', 'originalPrice', 'badge', 'inStock', 'image']],
                    'links' => ['first', 'last', 'prev', 'next'],
                    'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
                ])
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.per_page', 24)
                ->assertJsonPath('data.0.name', $localizedName);

            $productCards = collect($response->json('data'))->filter(
                fn (array $card): bool => $card['id'] === (string) $product->getKey(),
            );

            $this->assertCount(1, $productCards);
        }
    }

    public function test_locales_keep_identical_catalog_order_and_unknown_filters_are_rejected(): void
    {
        $this->createCatalogProduct('locale-order-one', '333.00', $this->instant->subHours(2));
        $this->createCatalogProduct('locale-order-two', '111.00', $this->instant->subHour());

        $arabicIds = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/products?sort=price_asc&per_page=100')
            ->assertOk()
            ->json('data.*.id');
        $englishIds = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products?sort=price_asc&per_page=100')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame($arabicIds, $englishIds);

        foreach (['color=red', 'collection=summer', 'sort=popular', 'unexpected=value', 'offer=scheduled'] as $query) {
            $this->getJson('/api/v1/products?'.$query)->assertUnprocessable();
        }
    }

    public function test_invalid_catalog_values_return_validation_errors_and_empty_filters_remain_successful(): void
    {
        foreach ([
            'min_price=-1',
            'min_price=1.001',
            'max_price=10000000000',
            'min_price=20&max_price=19.99',
            'page=0',
            'page=1.5',
            'per_page=101',
            'sort=popular',
            'offer=scheduled',
        ] as $query) {
            $this->getJson('/api/v1/products?'.$query)->assertUnprocessable();
        }

        $this->getJson('/api/v1/products?min_price=999999&max_price=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    private function createCatalogProduct(string $slug, string $price, CarbonImmutable $publishedAt, int $stock = 5): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name_ar' => 'منتج '.$slug,
            'name_en' => 'Catalog '.$slug,
            'status' => 'active',
            'published_at' => $publishedAt,
        ]);
        $category = Category::create([
            'slug' => 'category-'.$slug,
            'name_ar' => 'تصنيف '.$slug,
            'name_en' => 'Category '.$slug,
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $this->addVariant($product, [
            'sku' => 'SKU-'.$slug,
            'price' => $price,
            'stock_quantity' => $stock,
        ]);

        return $product;
    }

    /** @param array<string, mixed> $attributes */
    private function addVariant(Product $product, array $attributes = []): SellableItem
    {
        return SellableItem::create(array_merge([
            'product_id' => $product->getKey(),
            'sku' => 'SKU-'.$product->getKey().'-'.uniqid(),
            'price' => '100.00',
            'stock_quantity' => 5,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ], $attributes));
    }

    private function colorValue(Product $product, string $code, string $nameAr, string $nameEn): ProductOptionValue
    {
        $option = ProductOption::query()->firstOrCreate(
            ['product_id' => $product->getKey(), 'code' => 'color'],
            ['name_ar' => 'اللون', 'name_en' => 'Color', 'sort_order' => 0],
        );

        return ProductOptionValue::query()->firstOrCreate(
            ['product_option_id' => $option->getKey(), 'code' => $code],
            ['value_ar' => $nameAr, 'value_en' => $nameEn, 'sort_order' => 0],
        );
    }

    private function attachOffer(
        Product $product,
        string $percentage,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $enabled = true,
    ): Offer {
        $offer = Offer::query()->create([
            'name' => 'Catalog offer '.$product->getKey(),
            'discount_percentage' => $percentage,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_enabled' => $enabled,
        ]);
        $offer->products()->attach($product->getKey());

        return $offer;
    }
}
