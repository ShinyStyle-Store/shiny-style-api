<?php

namespace Tests\Feature\Api;

use App\Models\ShippingArea;
use Database\Seeders\ShippingAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingAreaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipping_area_listing_is_public_and_returns_only_selectable_active_areas(): void
    {
        ShippingArea::factory()->create(['code' => 'active']);
        ShippingArea::factory()->create(['code' => 'inactive', 'is_active' => false]);
        ShippingArea::factory()->create(['code' => 'group', 'is_selectable' => false, 'shipping_fee' => null]);

        $response = $this->getJson('/api/v1/shipping-areas');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'type', 'shipping_fee']]])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'active');

        $this->assertSame(
            ['id', 'code', 'name', 'type', 'shipping_fee'],
            array_keys($response->json('data.0')),
        );
        $this->assertGuest();
    }

    public function test_localization_accepts_regional_locales_and_uses_the_api_fallback(): void
    {
        $area = ShippingArea::factory()->create([
            'name_ar' => 'القاهرة',
            'name_en' => 'Cairo',
        ]);

        foreach (['ar', 'ar-EG'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->getJson('/api/v1/shipping-areas')
                ->assertJsonPath('data.0.name', 'القاهرة');
        }

        foreach (['en', 'en-US'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->getJson('/api/v1/shipping-areas')
                ->assertJsonPath('data.0.name', 'Cairo');
        }

        $this->withHeader('Accept-Language', 'fr-FR, en;q=0.8')
            ->getJson('/api/v1/shipping-areas')
            ->assertJsonPath('data.0.name', 'القاهرة');

        $this->assertNotNull($area->getKey());
    }

    public function test_areas_are_ordered_by_sort_order_then_id_and_zero_fee_is_formatted(): void
    {
        $second = ShippingArea::factory()->create(['code' => 'second', 'sort_order' => 2]);
        $zero = ShippingArea::factory()->create(['code' => 'zero', 'sort_order' => 1, 'shipping_fee' => '0.00']);
        $first = ShippingArea::factory()->create(['code' => 'first', 'sort_order' => 1, 'shipping_fee' => '125.5']);

        $response = $this->getJson('/api/v1/shipping-areas')->assertOk();

        $response->assertJsonPath('data.0.code', $zero->code)
            ->assertJsonPath('data.0.shipping_fee', '0.00')
            ->assertJsonPath('data.1.code', $first->code)
            ->assertJsonPath('data.1.shipping_fee', '125.50')
            ->assertJsonPath('data.2.code', $second->code);
    }

    public function test_parent_and_children_relationships_work(): void
    {
        $parent = ShippingArea::factory()->create(['code' => 'parent', 'is_selectable' => false, 'shipping_fee' => null]);
        $child = ShippingArea::factory()->create(['code' => 'child', 'parent_id' => $parent->getKey()]);

        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->contains(fn (ShippingArea $area): bool => $area->is($child)));
    }

    public function test_development_seeder_is_idempotent_and_preserves_existing_fees(): void
    {
        $area = ShippingArea::factory()->create(['code' => 'cairo', 'shipping_fee' => '99.00']);

        $this->seed(ShippingAreaSeeder::class);
        $this->seed(ShippingAreaSeeder::class);

        $this->assertDatabaseCount('shipping_areas', 3);
        $this->assertDatabaseHas('shipping_areas', ['code' => 'cairo', 'shipping_fee' => '99.00']);
        $this->assertDatabaseHas('shipping_areas', ['code' => 'giza', 'shipping_fee' => '60.00']);
        $this->assertDatabaseHas('shipping_areas', ['code' => 'alexandria', 'shipping_fee' => '80.00']);
        $this->assertNotNull($area->refresh()->getKey());
    }
}
