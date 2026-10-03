<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\OrderStatus;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderReturnDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_refusal_creates_a_decision_without_mutating_order_financial_or_timestamps(): void
    {
        $order = Order::factory()->shipped()->create(['shipped_at' => now()->subHour()]);
        $before = $order->fresh();
        $token = $this->adminToken();

        $response = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', [
                'reason' => 'refused_delivery',
                'note' => 'Customer refused the complete order.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'delivery_refusal')
            ->assertJsonPath('data.status', 'waiting_for_return');

        $after = $order->fresh();
        $this->assertSame(OrderStatus::DeliveryRefused, $after->status);
        $this->assertSame($before->payment_status, $after->payment_status);
        $this->assertSame($before->total, $after->total);
        $this->assertSame($before->shipped_at?->toISOString(), $after->shipped_at?->toISOString());
        $this->assertNull($after->delivered_at);
        $this->assertDatabaseCount('order_returns', 1);
    }

    public function test_return_after_delivery_keeps_the_order_delivered(): void
    {
        $order = Order::factory()->create([
            'status' => OrderStatus::Delivered,
            'delivered_at' => now()->subHour(),
        ]);
        $before = $order->fresh();

        $this->withToken($this->adminToken())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/approve-return', [
                'reason' => 'changed_mind',
            ])->assertCreated()->assertJsonPath('data.kind', 'return_after_delivery');

        $after = $order->fresh();
        $this->assertSame(OrderStatus::Delivered, $after->status);
        $this->assertSame($before->delivered_at?->toISOString(), $after->delivered_at?->toISOString());
    }

    public function test_decision_retries_are_immutable_and_new_keys_are_rejected(): void
    {
        $order = Order::factory()->shipped()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1]);
        $token = $this->adminToken();
        $key = (string) Str::uuid();
        $payload = ['reason' => 'refused_delivery', 'note' => 'Refused.'];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', $payload)
            ->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', $payload)
            ->assertStatus(409)->assertJsonPath('code', 'return_decision_already_exists');
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/approve-return', ['reason' => 'changed_mind'])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_conflict');
    }

    public function test_decision_replay_is_unchanged_after_receipt_and_reversal(): void
    {
        $order = Order::factory()->shipped()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1]);
        $token = $this->adminToken();
        $key = (string) Str::uuid();
        $payload = ['reason' => 'refused_delivery', 'note' => 'Refused.'];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', $payload)
            ->assertCreated();
        $receipt = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [
                'default_reason' => 'refused_delivery',
                'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 0]],
            ])->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns/'.$receipt->json('data.id').'/reverse', [
                'expected_version' => 0,
                'reversal_reason' => 'Wrong shipment.',
            ])->assertCreated();

        $replay = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', $payload)
            ->assertOk();

        $this->assertSame($first->json('data'), $replay->json('data'));
    }

    public function test_legacy_returns_cannot_receive_a_new_decision_and_decision_receipts_use_phase_two_flow(): void
    {
        $legacyOrder = Order::factory()->shipped()->create();
        ReturnReceipt::query()->create([
            'order_id' => $legacyOrder->id,
            'received_at' => now()->subMinute(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
        ]);

        $this->withToken($this->adminToken())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$legacyOrder->public_id.'/refuse-delivery', ['reason' => 'refused_delivery'])
            ->assertStatus(409)->assertJsonPath('code', 'return_decision_legacy_conflict');

        $order = Order::factory()->shipped()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1]);
        $this->withToken($this->adminToken())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', ['reason' => 'refused_delivery'])
            ->assertCreated();

        $this->withToken($this->adminToken())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [
                'default_reason' => 'refused_delivery',
                'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 0]],
            ])->assertCreated()->assertJsonPath('data.workflow_linked', true);
    }

    public function test_unknown_fields_other_reason_and_invalid_source_states_are_rejected(): void
    {
        $order = Order::factory()->create();
        $token = $this->adminToken();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', [
                'reason' => 'other', 'kind' => 'delivery_refusal',
            ])->assertUnprocessable()->assertJsonValidationErrors(['note', 'kind']);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', ['reason' => 'refused_delivery'])
            ->assertStatus(409)->assertJsonPath('code', 'return_decision_order_status_not_allowed');
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id, 'status' => AdminMembershipStatus::Active]);

        return $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }
}
