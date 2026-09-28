<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminShippingAreaApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_routes_require_admin_authentication_and_authorization(): void
    {
        $area = $this->area('auth-area');

        $this->getJson('/api/v1/admin/shipping-areas')->assertUnauthorized();
        $this->getJson('/api/v1/admin/shipping-areas/'.$area->id)->assertUnauthorized();
        $this->postJson('/api/v1/admin/shipping-areas', $this->payload())->assertUnauthorized();
        $this->patchJson('/api/v1/admin/shipping-areas/'.$area->id, ['nameEn' => 'Changed'])
            ->assertUnauthorized();

        $customer = User::factory()->create();
        $token = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/shipping-areas')->assertForbidden();
    }

    public function test_admin_list_and_detail_are_governorate_only_and_include_disabled_rows(): void
    {
        $first = $this->area('first', ['sort_order' => 1, 'is_active' => false]);
        $second = $this->area('second', ['sort_order' => 2]);
        $parent = $this->area('parent', ['sort_order' => 3]);
        $child = $this->area('child', [
            'parent_id' => $parent->id,
            'type' => 'city',
        ]);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/shipping-areas')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.isActive', false)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonMissing(['id' => $child->id]);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/shipping-areas/'.$child->id)
            ->assertNotFound();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/shipping-areas/'.$first->id)
            ->assertOk()->assertJsonPath('data.type', ShippingArea::TYPE_GOVERNORATE);
    }

    public function test_create_sets_server_owned_governorate_fields_and_rejects_unknown_fields(): void
    {
        $response = $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/shipping-areas', $this->payload([
                'code' => 'new-area',
                'parentId' => 999,
                'type' => 'city',
                'isSelectable' => false,
                'name_en' => 'Legacy field',
            ]));

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'parentId', 'type', 'isSelectable', 'name_en',
        ]);

        $response = $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/shipping-areas', $this->payload(['code' => 'new-area']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'new-area')
            ->assertJsonPath('data.type', ShippingArea::TYPE_GOVERNORATE)
            ->assertJsonPath('data.parentId', null)
            ->assertJsonPath('data.isSelectable', true);

        $this->assertDatabaseHas('shipping_areas', [
            'id' => $response->json('data.id'),
            'parent_id' => null,
            'type' => ShippingArea::TYPE_GOVERNORATE,
            'is_selectable' => true,
        ]);
    }

    public function test_validation_rejects_duplicate_code_invalid_money_negative_sort_and_empty_patch(): void
    {
        $area = $this->area('existing');

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/shipping-areas', $this->payload([
            'code' => $area->code,
            'shippingFee' => '-1.00',
            'sortOrder' => -1,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['code', 'shippingFee', 'sortOrder']);

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/shipping-areas', $this->payload([
            'shippingFee' => '10.999',
        ]))->assertUnprocessable()->assertJsonValidationErrors('shippingFee');

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/shipping-areas/'.$area->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors('shipping_area');
    }

    public function test_disable_is_visible_in_admin_but_hidden_from_public_and_reenable_works(): void
    {
        $area = $this->area('visibility');

        $this->withToken($this->adminToken)
            ->patchJson('/api/v1/admin/shipping-areas/'.$area->id, ['isActive' => false])
            ->assertOk()->assertJsonPath('data.isActive', false);
        $this->getJson('/api/v1/shipping-areas')->assertOk()->assertJsonMissing(['id' => $area->id]);

        $this->withToken($this->adminToken)
            ->patchJson('/api/v1/admin/shipping-areas/'.$area->id, ['isActive' => true])
            ->assertOk();
        $this->getJson('/api/v1/shipping-areas')->assertOk()->assertJsonFragment(['id' => $area->id]);
    }

    public function test_disabling_a_governorate_with_children_returns_conflict_without_cascading(): void
    {
        $parent = $this->area('parent');
        $child = $this->area('child', ['parent_id' => $parent->id, 'type' => 'city']);

        $this->withToken($this->adminToken)
            ->patchJson('/api/v1/admin/shipping-areas/'.$parent->id, ['isActive' => false])
            ->assertStatus(409)->assertJsonPath('code', 'governorate_has_children');

        $this->assertTrue($parent->refresh()->is_active);
        $this->assertTrue($child->refresh()->is_active);
    }

    public function test_fee_changes_apply_to_new_quotes_and_orders_but_not_historical_order_snapshots(): void
    {
        $area = $this->area('fees', ['shipping_fee' => '10.00']);
        $item = $this->sellableItem();

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.shipping_fee', '10.00');

        $oldOrder = $this->createOrder($area, $item);
        $this->withToken($this->adminToken)
            ->patchJson('/api/v1/admin/shipping-areas/'.$area->id, ['shippingFee' => '25.50'])
            ->assertOk()->assertJsonPath('data.shippingFee', '25.50');

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.shipping_fee', '25.50');

        $newOrder = $this->createOrder($area, $item);
        $this->assertSame('10.00', (string) $oldOrder->refresh()->shipping_fee);
        $this->assertSame('25.50', (string) $newOrder->refresh()->shipping_fee);
        $this->assertSame('fees', $oldOrder->shipping_area_code);
        $this->assertSame('fees', $newOrder->shipping_area_code);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'cairo',
            'nameAr' => 'القاهرة',
            'nameEn' => 'Cairo',
            'shippingFee' => '25.50',
            'isActive' => true,
            'sortOrder' => 1,
        ], $overrides);
    }

    private function area(string $code, array $attributes = []): ShippingArea
    {
        return ShippingArea::factory()->create(array_merge([
            'code' => $code,
            'name_ar' => 'محافظة '.$code,
            'name_en' => ucfirst($code),
            'shipping_fee' => '10.00',
        ], $attributes));
    }

    private function createOrder(ShippingArea $area, SellableItem $item): Order
    {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/orders', [
                'customer' => [
                    'name' => 'Customer Name',
                    'phone' => '01110007513',
                    'alternate_phone' => null,
                ],
                'shipping' => [
                    'shipping_area_id' => $area->id,
                    'address' => 'Full address',
                    'landmark' => null,
                ],
                'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
                'payment_method' => 'cash_on_delivery',
                'order_note' => null,
            ])->assertCreated();

        return Order::query()->where('public_id', $response->json('data.public_id'))->firstOrFail();
    }

    private function sellableItem(): SellableItem
    {
        $product = Product::create([
            'slug' => 'admin-shipping-product-'.uniqid(),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $category = Category::create([
            'slug' => 'admin-shipping-category-'.uniqid(),
            'name_ar' => 'تصنيف',
            'name_en' => 'Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);

        return SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'ADMIN-SHIPPING-'.strtoupper(uniqid()),
            'price' => '100.00',
            'stock_quantity' => 10,
            'status' => 'active',
            'is_default' => true,
            'sort_order' => 1,
        ]);
    }
}
