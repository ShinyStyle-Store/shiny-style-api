<?php

namespace Tests\Feature\Services;

use App\Exceptions\OfferPricingInvariantException;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellableItem;
use App\Services\OfferPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_no_qualifying_offer_states_leave_price_unchanged(): void
    {
        $item = $this->item('10.00');
        $this->offer($item->product_id, '10.00', false, '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z');
        $this->offer($item->product_id, '20.00', true, '2026-01-12T00:00:00Z', '2026-01-13T00:00:00Z');
        $this->offer($item->product_id, '30.00', true, '2026-01-01T00:00:00Z', '2026-01-09T00:00:00Z');

        $result = app(OfferPricingService::class)->calculate($item, CarbonImmutable::parse('2026-01-10T00:00:00Z'));

        $this->assertSame('10.00', $result['basePrice']);
        $this->assertSame('10.00', $result['effectivePrice']);
        $this->assertSame('0.00', $result['discountAmount']);
        $this->assertFalse($result['offerApplied']);
    }

    public function test_exact_start_is_inclusive_and_exact_end_is_exclusive_with_timezone_normalization(): void
    {
        $item = $this->item('100.00');
        $offer = $this->offer($item->product_id, '12.50', true, '2026-01-10T12:00:00Z', '2026-01-10T13:00:00Z');
        $service = app(OfferPricingService::class);

        $atStart = $service->calculate($item, CarbonImmutable::parse('2026-01-10T14:00:00+02:00'));
        $atEnd = $service->calculate($item, CarbonImmutable::parse('2026-01-10T15:00:00+02:00'));

        $this->assertTrue($atStart['offerApplied']);
        $this->assertSame($offer->id, $atStart['offerId']);
        $this->assertFalse($atEnd['offerApplied']);
        $this->assertSame('100.00', $atEnd['effectivePrice']);
    }

    public function test_batch_applies_same_offer_to_different_base_prices_and_avoids_n_plus_one(): void
    {
        $first = $this->item('10.01');
        $second = $this->item('20.00', $first->product_id, ['is_default' => false]);
        $this->offer($first->product_id, '12.34', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
        $service = app(OfferPricingService::class);

        DB::enableQueryLog();
        $results = $service->calculateMany(new \Illuminate\Database\Eloquent\Collection([$first, $second]), CarbonImmutable::parse('2026-01-10T00:00:00Z'));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('10.01', $results[0]['basePrice']);
        $this->assertSame('8.77', $results[0]['effectivePrice']);
        $this->assertSame('1.24', $results[0]['discountAmount']);
        $this->assertSame('17.53', $results[1]['effectivePrice']);
        $this->assertLessThanOrEqual(3, $queryCount);
    }

    public function test_base_price_changes_are_reflected_without_changing_offer_data(): void
    {
        $item = $this->item('10.00', null, ['original_price' => '25.00']);
        $offer = $this->offer($item->product_id, '20.00', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
        $service = app(OfferPricingService::class);

        $item->forceFill(['price' => '20.00'])->save();
        $result = $service->calculate($item->fresh(), CarbonImmutable::parse('2026-01-10T00:00:00Z'));

        $this->assertSame('20.00', $result['basePrice']);
        $this->assertSame('16.00', $result['effectivePrice']);
        $this->assertSame('20.00', (string) $offer->fresh()->discount_percentage);
        $this->assertSame('25.00', (string) $item->fresh()->original_price);
    }

    public function test_inactive_variants_and_archived_or_nonvisible_basic_products_do_not_receive_offers(): void
    {
        $inactive = $this->item('10.00', null, ['status' => 'inactive']);
        $archivedProduct = $this->product('Archived', ['status' => 'active']);
        $archivedItem = $this->item('10.00', $archivedProduct->id);
        $archivedProduct->delete();
        $inactiveProduct = $this->product('Inactive', ['status' => 'inactive']);
        $inactiveProductItem = $this->item('10.00', $inactiveProduct->id);

        $this->offer($inactive->product_id, '10.00', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
        $this->offer($archivedItem->product_id, '10.00', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
        $this->offer($inactiveProductItem->product_id, '10.00', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');

        $results = app(OfferPricingService::class)->calculateMany(
            new \Illuminate\Database\Eloquent\Collection([$inactive, $archivedItem, $inactiveProductItem]),
            CarbonImmutable::parse('2026-01-10T00:00:00Z'),
        );

        foreach ($results as $result) {
            $this->assertFalse($result['eligible']);
            $this->assertFalse($result['offerApplied']);
            $this->assertSame($result['basePrice'], $result['effectivePrice']);
        }
    }

    public function test_positive_price_that_rounds_to_zero_is_clamped_to_one_piaster(): void
    {
        $item = $this->item('0.01');
        $this->offer($item->product_id, '99.99', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');

        $result = app(OfferPricingService::class)->calculate($item, CarbonImmutable::parse('2026-01-10T00:00:00Z'));

        $this->assertSame('0.01', $result['effectivePrice']);
        $this->assertSame('0.00', $result['discountAmount']);
    }

    public function test_multiple_qualifying_offers_fail_and_are_logged(): void
    {
        $item = $this->item('10.00');
        $this->offer($item->product_id, '10.00', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
        $second = $this->offer($item->product_id, '20.00', true, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
        Log::spy();

        $this->expectException(OfferPricingInvariantException::class);
        try {
            app(OfferPricingService::class)->calculate($item, CarbonImmutable::parse('2026-01-10T00:00:00Z'));
        } finally {
            Log::shouldHaveReceived('error')->once();
        }

        $this->assertNotNull($second->id);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'slug' => Str::slug($name).'-'.Str::random(6),
            'name_ar' => $name,
            'name_en' => $name,
            'status' => 'active',
            'published_at' => CarbonImmutable::parse('2025-01-01T00:00:00Z'),
        ], $attributes));
    }

    private function item(string $price, ?int $productId = null, array $attributes = []): SellableItem
    {
        $productId ??= $this->product('Product')->id;

        return SellableItem::query()->create(array_merge([
            'product_id' => $productId,
            'sku' => 'SKU-'.Str::random(8),
            'price' => $price,
            'stock_quantity' => 10,
            'status' => 'active',
            'is_default' => true,
            'sort_order' => 1,
        ], $attributes));
    }

    private function offer(int $productId, string $percentage, bool $enabled, string $startsAt, string $endsAt): Offer
    {
        $offer = Offer::query()->create([
            'name' => 'Offer '.Str::random(8),
            'discount_percentage' => $percentage,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_enabled' => $enabled,
        ]);
        $offer->products()->attach($productId);

        return $offer;
    }
}
