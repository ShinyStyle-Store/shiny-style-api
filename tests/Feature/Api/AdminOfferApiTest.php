<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\Offer;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOfferApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_offer_routes_require_admin_access(): void
    {
        $offer = Offer::query()->create([
            'name' => 'Auth offer',
            'discount_percentage' => '10.00',
            'starts_at' => '2026-01-01T00:00:00Z',
            'ends_at' => '2026-01-20T00:00:00Z',
            'is_enabled' => false,
        ]);

        $this->getJson('/api/v1/admin/offers')->assertUnauthorized();
        $this->postJson('/api/v1/admin/offers', $this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/admin/offers/'.$offer->id)->assertUnauthorized();
        $this->patchJson('/api/v1/admin/offers/'.$offer->id, ['name' => 'Changed'])->assertUnauthorized();
        $this->postJson('/api/v1/admin/offers/'.$offer->id.'/deactivate')->assertUnauthorized();

        $customer = \App\Models\User::factory()->create();
        $token = $customer->createToken('customer')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/offers')->assertForbidden();
    }

    public function test_admin_can_create_list_and_show_offer_with_product_summaries_and_derived_state(): void
    {
        CarbonImmutable::setTestNow('2026-01-10T11:00:00Z');
        $product = $this->product('Visible later', ['status' => 'draft']);

        $response = $this->admin()->postJson('/api/v1/admin/offers', $this->payload([
            'name' => 'January sale',
            'productIds' => [$product->id],
            'startsAt' => '2026-01-10T14:00:00+02:00',
            'endsAt' => '2026-01-20T14:00:00+02:00',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'January sale')
            ->assertJsonPath('data.discountPercentage', '15.00')
            ->assertJsonPath('data.isEnabled', true)
            ->assertJsonPath('data.state', 'scheduled')
            ->assertJsonPath('data.productIds.0', $product->id)
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonPath('data.products.0.status', 'draft')
            ->assertJsonPath('data.startsAt', '2026-01-10T12:00:00.000000Z');

        $id = $response->json('data.id');
        $this->admin()->getJson('/api/v1/admin/offers')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('meta.total', 1);
        $this->admin()->getJson('/api/v1/admin/offers/'.$id)->assertOk()
            ->assertJsonPath('data.productIds.0', $product->id);
    }

    public function test_product_assignment_is_a_complete_replacement_and_empty_is_allowed_when_disabled(): void
    {
        $first = $this->product('First');
        $second = $this->product('Second');
        $offer = $this->createOffer([$first->id, $second->id]);

        $this->admin()->patchJson('/api/v1/admin/offers/'.$offer->id, [
            'productIds' => [$second->id],
        ])->assertOk()->assertJsonPath('data.productIds', [$second->id]);

        $this->admin()->patchJson('/api/v1/admin/offers/'.$offer->id, [
            'isEnabled' => false,
            'productIds' => [],
        ])->assertOk()
            ->assertJsonPath('data.isEnabled', false)
            ->assertJsonPath('data.state', 'disabled')
            ->assertJsonPath('data.productIds', []);

        $this->assertDatabaseMissing('offer_product', ['offer_id' => $offer->id, 'product_id' => $first->id]);
    }

    public function test_partial_update_and_deactivate_preserve_omitted_values(): void
    {
        $product = $this->product('Product');
        $offer = $this->createOffer([$product->id]);

        $this->admin()->patchJson('/api/v1/admin/offers/'.$offer->id, [
            'discountPercentage' => '25.00',
        ])->assertOk()
            ->assertJsonPath('data.discountPercentage', '25.00')
            ->assertJsonPath('data.productIds', [$product->id]);

        $this->admin()->postJson('/api/v1/admin/offers/'.$offer->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.isEnabled', false)
            ->assertJsonPath('data.state', 'disabled');
    }

    public function test_validation_rejects_invalid_discount_dates_duplicates_unknown_fields_and_empty_enabled_selection(): void
    {
        $product = $this->product('Product');

        $this->admin()->postJson('/api/v1/admin/offers', [
            ...$this->payload(['discountPercentage' => '100.00', 'productIds' => [$product->id, $product->id]]),
            'legacy_field' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'discountPercentage', 'productIds.1', 'legacy_field',
        ]);

        $this->admin()->postJson('/api/v1/admin/offers', $this->payload([
            'isEnabled' => true,
            'productIds' => [],
            'startsAt' => '2026-01-20T12:00:00Z',
            'endsAt' => '2026-01-10T12:00:00Z',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['endsAt', 'productIds']);

        $this->admin()->postJson('/api/v1/admin/offers', $this->payload([
            'startsAt' => '2026-01-10 12:00:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('startsAt');
    }

    public function test_archived_products_are_rejected_but_unpublished_products_are_allowed(): void
    {
        $archived = $this->product('Archived');
        $archived->delete();
        $unpublished = $this->product('Unpublished', ['status' => 'inactive', 'published_at' => null]);

        $this->admin()->postJson('/api/v1/admin/offers', $this->payload([
            'productIds' => [$archived->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('productIds.0');

        $this->admin()->postJson('/api/v1/admin/offers', $this->payload([
            'productIds' => [$unpublished->id],
        ]))->assertCreated()->assertJsonPath('data.productIds', [$unpublished->id]);
    }

    public function test_state_uses_disabled_scheduled_active_and_expired_with_end_exclusive_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-01-10T11:00:00Z');
        $product = $this->product('Product');
        $offer = $this->createOffer([$product->id], [
            'startsAt' => '2026-01-10T12:00:00Z',
            'endsAt' => '2026-01-20T12:00:00Z',
        ]);

        $this->admin()->patchJson('/api/v1/admin/offers/'.$offer->id, ['isEnabled' => false])
            ->assertJsonPath('data.state', 'disabled');

        $this->admin()->patchJson('/api/v1/admin/offers/'.$offer->id, ['isEnabled' => true])
            ->assertJsonPath('data.state', 'scheduled');

        CarbonImmutable::setTestNow('2026-01-10T12:00:00Z');
        $this->admin()->getJson('/api/v1/admin/offers/'.$offer->id)
            ->assertJsonPath('data.state', 'active');

        CarbonImmutable::setTestNow('2026-01-20T12:00:00Z');
        $this->admin()->getJson('/api/v1/admin/offers/'.$offer->id)
            ->assertJsonPath('data.state', 'expired');
    }

    public function test_adjacent_enabled_windows_are_allowed_but_overlapping_windows_are_rejected_on_create(): void
    {
        $product = $this->product('Product');
        $this->createOffer([$product->id], [
            'startsAt' => '2026-01-01T00:00:00Z',
            'endsAt' => '2026-01-10T00:00:00Z',
        ]);

        $this->admin()->postJson('/api/v1/admin/offers', $this->payload([
            'productIds' => [$product->id],
            'startsAt' => '2026-01-10T00:00:00Z',
            'endsAt' => '2026-01-20T00:00:00Z',
        ]))->assertCreated();

        $this->admin()->postJson('/api/v1/admin/offers', $this->payload([
            'productIds' => [$product->id],
            'startsAt' => '2026-01-09T23:59:59Z',
            'endsAt' => '2026-01-20T00:00:00Z',
        ]))->assertConflict()
            ->assertJsonPath('code', 'offer_product_overlap')
            ->assertJsonPath('conflict.productId', $product->id);
    }

    public function test_activation_product_reassignment_and_date_changes_apply_overlap_protection(): void
    {
        $first = $this->product('First');
        $second = $this->product('Second');
        $active = $this->createOffer([$first->id]);
        $disabled = $this->createOffer([$first->id], ['isEnabled' => false]);

        $this->admin()->patchJson('/api/v1/admin/offers/'.$disabled->id, ['isEnabled' => true])
            ->assertConflict()->assertJsonPath('conflict.offerId', $active->id);

        $other = $this->createOffer([$second->id], [
            'startsAt' => '2026-01-05T00:00:00Z',
            'endsAt' => '2026-01-15T00:00:00Z',
        ]);
        $this->admin()->patchJson('/api/v1/admin/offers/'.$other->id, [
            'productIds' => [$first->id],
        ])->assertConflict()->assertJsonPath('conflict.offerId', $active->id);

        $dateCandidate = $this->createOffer([$first->id], [
            'isEnabled' => false,
            'startsAt' => '2026-03-01T00:00:00Z',
            'endsAt' => '2026-03-10T00:00:00Z',
        ]);
        $this->admin()->patchJson('/api/v1/admin/offers/'.$dateCandidate->id, [
            'startsAt' => '2026-01-05T00:00:00Z',
            'endsAt' => '2026-01-15T00:00:00Z',
            'isEnabled' => true,
        ])->assertConflict()->assertJsonPath('conflict.offerId', $active->id);
    }

    public function test_offer_product_pivot_rejects_duplicate_assignments_at_database_level(): void
    {
        $product = $this->product('Product');
        $offer = $this->createOffer([$product->id], ['isEnabled' => false]);

        $this->expectException(QueryException::class);
        $offer->products()->attach($product->id);
    }

    private function admin()
    {
        return $this->withToken($this->adminToken);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test offer',
            'discountPercentage' => '15.00',
            'startsAt' => '2026-01-10T12:00:00Z',
            'endsAt' => '2026-01-20T12:00:00Z',
            'isEnabled' => true,
            'productIds' => [],
        ], $overrides);
    }

    private function createOffer(array $productIds, array $overrides = []): Offer
    {
        $offer = Offer::query()->create([
            'name' => 'Existing offer '.Str::random(6),
            'discount_percentage' => '10.00',
            'starts_at' => $overrides['startsAt'] ?? '2026-01-01T00:00:00Z',
            'ends_at' => $overrides['endsAt'] ?? '2026-01-20T00:00:00Z',
            'is_enabled' => $overrides['isEnabled'] ?? true,
        ]);
        $offer->products()->sync($productIds);

        return $offer;
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'slug' => Str::slug($name).'-'.Str::random(6),
            'name_ar' => $name,
            'name_en' => $name,
            'status' => 'active',
            'published_at' => now()->subDay(),
        ], $attributes));
    }
}
