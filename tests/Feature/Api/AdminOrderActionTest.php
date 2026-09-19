<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminOrderActionTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_all_action_routes_require_admin_bearer_authentication(): void
    {
        $order = Order::factory()->create();
        $routes = $this->actionRoutes($order->public_id);

        foreach ($routes as [$method, $uri, $body]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri, $body)->assertUnauthorized();
        }
        foreach ($routes as [$method, $uri, $body]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri, $body, ['Authorization' => 'Bearer invalid-token'])->assertUnauthorized();
        }

        $customer = User::factory()->create();
        $customerToken = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        foreach ($routes as [$method, $uri, $body]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$customerToken])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/confirm', [])
            ->assertUnauthorized();
    }

    public function test_suspended_and_revoked_memberships_cannot_perform_order_actions(): void
    {
        foreach ([AdminMembershipStatus::Suspended, AdminMembershipStatus::Revoked] as $status) {
            $admin = User::factory()->create(['is_active' => true]);
            AdminMembership::factory()->create(['user_id' => $admin->id, 'status' => $status]);
            $token = $admin->createToken('admin', ['admin-access'])->plainTextToken;
            $this->app['auth']->forgetGuards();

            $this->json('POST', '/api/v1/admin/orders/'.Order::factory()->create()->public_id.'/confirm', [], [
                'Authorization' => 'Bearer '.$token,
            ])->assertForbidden();
        }
    }

    public function test_action_routes_resolve_only_valid_public_ids(): void
    {
        $validMissingId = '01J00000000000000000000000';

        foreach ($this->actionRoutes($validMissingId) as [$method, $uri, $body]) {
            $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$this->adminToken])->assertNotFound();
        }

        foreach (['12', 'malformed-ulid'] as $badId) {
            foreach ($this->actionRoutes($badId) as [$method, $uri, $body]) {
                $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$this->adminToken])->assertNotFound();
            }
        }
    }

    public function test_bodyless_actions_accept_empty_payloads_and_reject_all_supplied_fields(): void
    {
        $order = Order::factory()->create();
        $emptyObjectResponse = $this->call(
            'POST',
            '/api/v1/admin/orders/'.$order->public_id.'/confirm',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken,
            ],
            '{}',
        );
        $emptyObjectResponse->assertOk()->assertJsonPath('data.status', 'confirmed');

        foreach (['confirm', 'prepare', 'ship', 'deliver'] as $action) {
            $order = Order::factory()->create();
            $response = $this->withToken($this->adminToken)->postJson(
                '/api/v1/admin/orders/'.$order->public_id.'/'.$action,
                ['status' => 'shipped', 'shipped_at' => now()->toISOString(), 'stock_quantity' => 1, 'reserved_quantity' => 1],
            );
            $response->assertUnprocessable()->assertJsonValidationErrors([
                'status', 'shipped_at', 'stock_quantity', 'reserved_quantity',
            ]);
        }
    }

    public function test_lifecycle_actions_follow_allowed_graph_and_repeated_calls_are_no_ops(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');
        [$order, $sellable] = $this->orderWithReservation(stock: 12, reserved: 3, quantity: 3);

        $confirmed = $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'confirm'), [])
            ->assertOk()->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.contact_status', 'responded')
            ->assertJsonPath('data.payment_status', PaymentStatus::Unpaid->value);
        $confirmedAt = $confirmed->json('data.confirmed_at');
        $contactedAt = $confirmed->json('data.last_contacted_at');
        $this->assertSame($contactedAt, $confirmedAt);
        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'confirm'), [])
            ->assertOk()->assertJsonPath('data.confirmed_at', $confirmedAt);

        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'prepare'), [])
            ->assertOk()->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.payment_status', PaymentStatus::Unpaid->value);
        $prepared = $order->fresh()->preparing_at?->toISOString();
        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'prepare'), [])
            ->assertOk()->assertJsonPath('data.preparing_at', $prepared);

        $shipped = $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'ship'), [])
            ->assertOk()->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.payment_status', PaymentStatus::Unpaid->value);
        $shippedAt = $shipped->json('data.shipped_at');
        $this->assertInventory($sellable, 9, 0);
        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'ship'), [])
            ->assertOk()->assertJsonPath('data.shipped_at', $shippedAt);
        $this->assertInventory($sellable, 9, 0);

        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'deliver'), [])
            ->assertOk()->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.payment_status', PaymentStatus::Paid->value)
            ->assertJsonStructure(['data' => ['public_id', 'items', 'shipping', 'confirmed_at', 'shipped_at', 'delivered_at']]);
        $this->assertInventory($sellable, 9, 0);
        $deliveredAt = $order->fresh()->delivered_at?->toISOString();
        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'deliver'), [])
            ->assertOk()->assertJsonPath('data.delivered_at', $deliveredAt)
            ->assertJsonPath('data.payment_status', PaymentStatus::Paid->value);
        $this->assertInventory($sellable, 9, 0);

        [$confirmedOrder, $confirmedItem] = $this->orderWithReservation(
            stock: 8,
            reserved: 2,
            quantity: 2,
            status: OrderStatus::Confirmed,
        );
        $this->withToken($this->adminToken)->postJson($this->actionUrl($confirmedOrder, 'ship'), [])
            ->assertOk()->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.payment_status', PaymentStatus::Unpaid->value);
        $this->assertInventory($confirmedItem, 6, 0);
    }

    public function test_deliver_api_maps_incompatible_cod_payment_states_to_conflict_without_mutation(): void
    {
        foreach ([PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Refunded] as $paymentStatus) {
            [$order, $sellable] = $this->orderWithReservation(
                stock: 7,
                reserved: 0,
                quantity: 3,
                status: OrderStatus::Shipped,
            );
            $order->update([
                'payment_method' => PaymentMethod::CashOnDelivery,
                'payment_status' => $paymentStatus,
                'shipped_at' => '2026-09-19 14:00:00',
            ]);

            $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'deliver'), [])
                ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
            $unchanged = $order->fresh();
            $this->assertSame(OrderStatus::Shipped, $unchanged->status);
            $this->assertSame($paymentStatus, $unchanged->payment_status);
            $this->assertNull($unchanged->delivered_at);
            $this->assertInventory($sellable, 7, 0);
        }

        [$historical, $historicalItem] = $this->orderWithReservation(
            stock: 7,
            reserved: 0,
            quantity: 3,
            status: OrderStatus::Delivered,
        );
        $historical->update([
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
            'delivered_at' => '2026-09-19 13:00:00',
        ]);
        $deliveredAt = $historical->fresh()->delivered_at?->toISOString();

        $this->withToken($this->adminToken)->postJson($this->actionUrl($historical, 'deliver'), [])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        $this->assertSame(PaymentStatus::Unpaid, $historical->fresh()->payment_status);
        $this->assertSame($deliveredAt, $historical->fresh()->delivered_at?->toISOString());
        $this->assertInventory($historicalItem, 7, 0);
    }

    public function test_invalid_lifecycle_transitions_return_stable_conflict_response(): void
    {
        $pending = Order::factory()->create();
        $response = $this->withToken($this->adminToken)->postJson($this->actionUrl($pending, 'prepare'), []);
        $response->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition')
            ->assertJsonPath('message', 'The requested order action is not allowed.');
        $this->assertSame(OrderStatus::PendingConfirmation, $pending->fresh()->status);

        foreach ([OrderStatus::Delivered, OrderStatus::Cancelled] as $terminal) {
            $terminalOrder = Order::factory()->create(['status' => $terminal]);
            $this->withToken($this->adminToken)->postJson($this->actionUrl($terminalOrder, 'confirm'), [])
                ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        }
    }

    public function test_cancel_validates_metadata_trims_notes_releases_reservation_once_and_is_idempotent(): void
    {
        [$order, $sellable] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3);
        $url = $this->actionUrl($order, 'cancel');

        $this->withToken($this->adminToken)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($this->adminToken)->postJson($url, ['reason' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($this->adminToken)->postJson($url, ['reason' => 'other', 'note' => '  '])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->withToken($this->adminToken)->postJson($url, [
            'reason' => 'customer_cancelled', 'note' => 'reason', 'status' => 'cancelled', 'payment_status' => 'paid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status', 'payment_status']);

        $note = '  Ø§Ù„Ø¹Ù…ÙŠÙ„ Ø·Ù„Ø¨ Ø§Ù„Ø¥Ù„ØºØ§Ø¡  ';
        $cancelled = $this->withToken($this->adminToken)->postJson($url, ['reason' => 'other', 'note' => $note]);
        $cancelled->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'other')
            ->assertJsonPath('data.cancellation_note', trim($note))
            ->assertJsonPath('data.payment_status', 'unpaid');
        $cancelledAt = $cancelled->json('data.cancelled_at');
        $this->assertInventory($sellable, 10, 0);

        $this->withToken($this->adminToken)->postJson($url, ['reason' => 'other', 'note' => trim($note)])
            ->assertOk()->assertJsonPath('data.cancelled_at', $cancelledAt);
        $this->assertInventory($sellable, 10, 0);
        $this->withToken($this->adminToken)->postJson($url, ['reason' => 'customer_cancelled'])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
    }

    public function test_cancel_rejects_shipped_orders_and_insufficient_inventory_rolls_back(): void
    {
        foreach ([OrderStatus::Shipped, OrderStatus::Delivered] as $terminalStatus) {
            [$terminal] = $this->orderWithReservation(stock: 8, reserved: 0, quantity: 2, status: $terminalStatus);
            $this->withToken($this->adminToken)->postJson($this->actionUrl($terminal, 'cancel'), [
                'reason' => 'other', 'note' => 'after shipping',
            ])->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        }

        [$order, $sellable] = $this->orderWithReservation(
            stock: 1,
            reserved: 2,
            quantity: 2,
            status: OrderStatus::Confirmed,
        );
        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'ship'), [])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertInventory($sellable, 1, 2);

        [$unreservedOrder, $unreservedItem] = $this->orderWithReservation(
            stock: 10,
            reserved: 1,
            quantity: 2,
            status: OrderStatus::Confirmed,
        );
        $this->withToken($this->adminToken)->postJson($this->actionUrl($unreservedOrder, 'ship'), [])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        $this->assertSame(OrderStatus::Confirmed, $unreservedOrder->fresh()->status);
        $this->assertInventory($unreservedItem, 10, 1);

        [$unreservedCancellation, $unreservedCancellationItem] = $this->orderWithReservation(stock: 10, reserved: 1, quantity: 2);
        $this->withToken($this->adminToken)->postJson($this->actionUrl($unreservedCancellation, 'cancel'), [
            'reason' => 'customer_cancelled',
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        $this->assertSame(OrderStatus::PendingConfirmation, $unreservedCancellation->fresh()->status);
        $this->assertInventory($unreservedCancellationItem, 10, 1);
    }

    public function test_cancellation_is_allowed_before_shipping_for_pending_confirmed_and_preparing_orders(): void
    {
        foreach ([OrderStatus::PendingConfirmation, OrderStatus::Confirmed, OrderStatus::Preparing] as $status) {
            $order = Order::factory()->create(['status' => $status]);
            $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'cancel'), [
                'reason' => CancellationReason::CustomerCancelled->value,
            ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        }
    }

    public function test_contact_updates_preserve_clear_and_timestamp_notes_without_lifecycle_or_inventory_changes(): void
    {
        Carbon::setTestNow('2026-09-19 13:00:00');
        [$order, $sellable] = $this->orderWithReservation(stock: 10, reserved: 2, quantity: 2);
        $order->update(['contact_note' => 'Existing note', 'payment_status' => PaymentStatus::Unpaid]);

        $url = '/api/v1/admin/orders/'.$order->public_id.'/contact-status';
        $response = $this->withToken($this->adminToken)->patchJson($url, ['contact_status' => 'responded']);
        $response->assertOk()->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.contact_status', 'responded')
            ->assertJsonPath('data.contact_note', 'Existing note')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $firstContactAt = $response->json('data.last_contacted_at');

        Carbon::setTestNow('2026-09-19 13:00:30');
        $repeatedResponse = $this->withToken($this->adminToken)->patchJson($url, ['contact_status' => 'responded']);
        $repeatedResponse->assertOk()->assertJsonPath('data.contact_note', 'Existing note');
        $repeatedContactAt = $repeatedResponse->json('data.last_contacted_at');
        $this->assertNotSame($firstContactAt, $repeatedContactAt);

        Carbon::setTestNow('2026-09-19 13:01:00');
        $this->withToken($this->adminToken)->patchJson($url, [
            'contact_status' => 'no_response', 'contact_note' => '  Ù„Ù… ÙŠØ±Ø¯  ',
        ])->assertOk()->assertJsonPath('data.contact_note', 'Ù„Ù… ÙŠØ±Ø¯')
            ->assertJsonPath('data.status', 'pending_confirmation');
        $secondContactAt = $order->fresh()->last_contacted_at?->toISOString();
        $this->assertNotSame($repeatedContactAt, $secondContactAt);

        Carbon::setTestNow('2026-09-19 13:02:00');
        $this->withToken($this->adminToken)->patchJson($url, ['contact_status' => 'responded', 'contact_note' => null])
            ->assertOk()->assertJsonPath('data.contact_note', null);
        $this->assertNotSame($secondContactAt, $order->fresh()->last_contacted_at?->toISOString());

        $this->withToken($this->adminToken)->patchJson($url, ['contact_status' => 'not_contacted'])
            ->assertOk()->assertJsonPath('data.last_contacted_at', null)->assertJsonPath('data.contact_note', null);
        $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertInventory($sellable, 10, 2);
    }

    public function test_contact_contract_is_strict_and_terminal_orders_reject_contact_updates(): void
    {
        $order = Order::factory()->create();
        $url = '/api/v1/admin/orders/'.$order->public_id.'/contact-status';
        $this->withToken($this->adminToken)->patchJson($url, [])->assertUnprocessable()
            ->assertJsonValidationErrors('contact_status');
        $this->withToken($this->adminToken)->patchJson($url, ['contact_status' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('contact_status');
        $this->withToken($this->adminToken)->patchJson($url, ['contact_status' => 'responded', 'status' => 'confirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        foreach ([OrderStatus::Delivered, OrderStatus::Cancelled] as $terminal) {
            $terminalOrder = Order::factory()->create(['status' => $terminal]);
            $this->withToken($this->adminToken)->patchJson(
                '/api/v1/admin/orders/'.$terminalOrder->public_id.'/contact-status',
                ['contact_status' => 'no_response'],
            )->assertStatus(409)->assertJsonPath('code', 'terminal_order_contact_update_not_allowed');
        }
    }

    public function test_confirmed_contact_time_is_set_only_when_missing_and_contact_note_is_preserved(): void
    {
        Carbon::setTestNow('2026-09-19 14:00:00');
        $order = Order::factory()->create(['contact_note' => 'Do not overwrite']);
        $this->withToken($this->adminToken)->postJson($this->actionUrl($order, 'confirm'), [])
            ->assertOk()->assertJsonPath('data.contact_status', 'responded')
            ->assertJsonPath('data.last_contacted_at', Carbon::now()->toISOString())
            ->assertJsonPath('data.contact_note', 'Do not overwrite');

        Carbon::setTestNow('2026-09-19 14:05:00');
        $later = Order::factory()->create([
            'last_contacted_at' => '2026-09-19 14:03:00',
            'contact_note' => 'Existing confirmed note',
        ]);
        $existingContactedAt = $later->last_contacted_at->toISOString();
        $this->withToken($this->adminToken)->postJson($this->actionUrl($later, 'confirm'), [])
            ->assertOk()->assertJsonPath('data.last_contacted_at', $existingContactedAt)
            ->assertJsonPath('data.contact_note', 'Existing confirmed note');
    }

    /** @return array<int, array{string, string, array<string, mixed>}> */
    private function actionRoutes(string $publicId): array
    {
        return [
            ['POST', "/api/v1/admin/orders/{$publicId}/confirm", []],
            ['POST', "/api/v1/admin/orders/{$publicId}/prepare", []],
            ['POST', "/api/v1/admin/orders/{$publicId}/ship", []],
            ['POST', "/api/v1/admin/orders/{$publicId}/deliver", []],
            ['POST', "/api/v1/admin/orders/{$publicId}/cancel", ['reason' => 'customer_cancelled']],
            ['PATCH', "/api/v1/admin/orders/{$publicId}/contact-status", ['contact_status' => 'responded']],
        ];
    }

    private function actionUrl(Order $order, string $action): string
    {
        return '/api/v1/admin/orders/'.$order->public_id.'/'.$action;
    }

    /** @return array{Order, SellableItem} */
    private function orderWithReservation(
        int $stock,
        int $reserved,
        int $quantity,
        OrderStatus $status = OrderStatus::PendingConfirmation,
    ): array {
        $product = Product::create([
            'slug' => 'admin-action-product-'.uniqid(),
            'name_ar' => 'Ù…Ù†ØªØ¬ Ø§Ø®ØªØ¨Ø§Ø±',
            'name_en' => 'Admin Action Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $category = Category::create([
            'slug' => 'admin-action-category-'.uniqid(),
            'name_ar' => 'ØªØµÙ†ÙŠÙ Ø§Ø®ØªØ¨Ø§Ø±',
            'name_en' => 'Admin Action Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $sellable = SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'ADMIN-ACTION-'.uniqid(),
            'price' => '25.00',
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'status' => 'active',
            'is_default' => true,
        ]);
        $order = Order::factory()->create([
            'status' => $status,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $sellable->id,
            'quantity' => $quantity,
            'unit_price' => '25.00',
            'line_total' => (string) (25 * $quantity).'.00',
        ]);

        return [$order, $sellable];
    }

    private function assertInventory(SellableItem $item, int $stock, int $reserved): void
    {
        $fresh = $item->newQueryWithoutScopes()->findOrFail($item->id);
        $this->assertSame($stock, $fresh->stock_quantity);
        $this->assertSame($reserved, $fresh->reserved_quantity);
    }
}
