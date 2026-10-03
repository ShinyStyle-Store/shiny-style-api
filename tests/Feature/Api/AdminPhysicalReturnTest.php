<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\SellableItem;
use App\Models\User;
use App\Services\PhysicalReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminPhysicalReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_restock_is_idempotent_and_preserves_reservations(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 3, stock: 7, reserved: 1);
        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);
        $key = (string) Str::uuid();
        $payload = [
            'items_received_at' => now()->subMinute()->toISOString(),
            'default_reason' => 'changed_mind',
            'items' => [[
                'order_item_id' => $item->id,
                'received_quantity' => 3,
                'restock_quantity' => 2,
            ]],
        ];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', $payload)
            ->assertCreated()->assertJsonPath('data.version', 0);
        $first->assertJsonPath('data.items.0.current_restock_quantity', 2);
        $first->assertJsonMissingPath('data.revision')
            ->assertJsonMissingPath('data.items.0.effective_restockable_quantity');
        $this->assertSame(9, $sellable->fresh()->stock_quantity);
        $this->assertSame(1, $sellable->fresh()->reserved_quantity);

        $replay = $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', $payload)
            ->assertOk()->assertJsonPath('data.items.0.current_restock_quantity', 2);
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertDatabaseCount('return_receipts', 1);
        $this->assertSame(9, $sellable->fresh()->stock_quantity);
    }

    public function test_old_api_names_are_rejected_without_mutation_and_new_error_paths_are_used(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['received_at' => now()->subMinute()->toISOString(), 'default_reason' => 'changed_mind', 'items' => [[
                'order_item_id' => $item->id, 'received_quantity' => 1, 'restockable_quantity' => 1,
            ]]],
        )->assertUnprocessable()->assertJsonValidationErrors(['received_at', 'items.0.restockable_quantity', 'items.0.restock_quantity']);
        $this->assertDatabaseCount('return_receipts', 0);
        $this->assertSame(5, $sellable->fresh()->stock_quantity);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['default_reason' => 'changed_mind', 'items' => [[
                'order_item_id' => $item->id, 'received_quantity' => 2, 'restock_quantity' => 3,
            ]]],
        )->assertUnprocessable()->assertJsonValidationErrors('items.0.restock_quantity');
    }

    public function test_correction_to_zero_subtracts_delta_and_updates_summary(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 2, stock: 8);
        $token = $this->adminToken();
        $created = $this->createReturnResponse($token, $order, $item, 2, 2);
        $receiptId = $created->json('data.id');

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            [
                'expected_version' => 0,
                'correction_reason' => 'The inspection result was entered incorrectly.',
                'items' => [[
                    'return_receipt_item_id' => $created->json('data.items.0.id'),
                    'received_quantity' => 0,
                    'restock_quantity' => 0,
                ]],
            ],
        )->assertOk()->assertJsonPath('data.operation.new_version', 1)
            ->assertJsonPath('data.operation.items.0.previous_restock_quantity', 2)
            ->assertJsonPath('data.operation.items.0.new_restock_quantity', 0)
            ->assertJsonMissingPath('data.operation.items.0.previous_restockable_quantity')
            ->assertJsonMissingPath('data.operation.items.0.new_restockable_quantity')
            ->assertJsonPath('data.receipt.return_summary', 'none');
        $detail = $this->withToken($token)->getJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}")->assertOk();
        $this->assertArrayNotHasKey('expected_revision', $detail->json('data.corrections.0'));
        $detail->assertJsonPath('data.corrections.0.items.0.previous_restock_quantity', 2)
            ->assertJsonPath('data.corrections.0.items.0.new_restock_quantity', 0)
            ->assertJsonMissingPath('data.corrections.0.items.0.previous_restockable_quantity')
            ->assertJsonMissingPath('data.corrections.0.items.0.new_restockable_quantity');

        $this->assertSame(8, $sellable->fresh()->stock_quantity);
        $this->assertSame('none', $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)
            ->assertOk()->json('data.return_summary'));
    }

    public function test_stale_correction_is_rejected_without_stock_mutation_and_mixed_reasons_are_supported(): void
    {
        [$order, $first, $sellable] = $this->shippedOrder(quantity: 1, stock: 5);
        $second = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'sellable_item_id' => $sellable->id]);
        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);
        $created = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            [
                'default_reason' => 'changed_mind',
                'items' => [
                    ['order_item_id' => $first->id, 'received_quantity' => 1, 'restock_quantity' => 1],
                    ['order_item_id' => $second->id, 'received_quantity' => 1, 'restock_quantity' => 0, 'reason' => 'defective_item', 'note' => 'Damaged.'],
                ],
            ],
        )->assertCreated();
        $receiptId = $created->json('data.id');
        $receiptItemId = $created->json('data.items.0.id');
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            ['expected_version' => 0, 'correction_reason' => 'Corrected.', 'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 1, 'restock_quantity' => 0]]],
        )->assertOk();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            ['expected_version' => 0, 'correction_reason' => 'Stale.', 'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 1, 'restock_quantity' => 0]]],
        )->assertStatus(409)->assertJsonPath('code', 'return_revision_conflict');
        $this->assertSame(5, $sellable->fresh()->stock_quantity);
    }

    public function test_new_api_payload_retries_a_receipt_with_a_pre_refactor_internal_fingerprint(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 1, stock: 5);
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id, 'status' => AdminMembershipStatus::Active]);
        $token = $admin->createToken('admin', ['admin-access'])->plainTextToken;
        $key = (string) Str::uuid();
        $this->createDeliveryRefusal($token, $order);

        app(PhysicalReturnService::class)->create($order, [
            'default_reason' => 'changed_mind',
            'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restockable_quantity' => 0]],
        ], $admin->id, $key);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['default_reason' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 0]]],
        )->assertOk()->assertJsonPath('data.version', 0);
    }

    public function test_delivery_guards_full_returns_and_cod_partial_returns(): void
    {
        [$cod, $item, $codSellable] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();
        $this->createReturn($token, $cod, $item, 1, 0);
        $codBefore = $cod->fresh();
        $this->withToken($token)->postJson('/api/v1/admin/orders/'.$cod->public_id.'/deliver')
            ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        $codAfter = $cod->fresh();
        $this->assertSame(OrderStatus::Shipped, $codAfter->status);
        $this->assertSame($codBefore->payment_status, $codAfter->payment_status);
        $this->assertSame($codBefore->delivered_at?->toISOString(), $codAfter->delivered_at?->toISOString());
        $this->assertSame(5, $codSellable->fresh()->stock_quantity);

        [$card, $cardItem, $cardSellable] = $this->shippedOrder(quantity: 1, stock: 5, paymentMethod: PaymentMethod::Card, paymentStatus: PaymentStatus::Paid);
        $this->createReturn($token, $card, $cardItem, 1, 0);
        $cardBefore = $card->fresh();
        $this->withToken($token)->postJson('/api/v1/admin/orders/'.$card->public_id.'/deliver')
            ->assertStatus(409)->assertJsonPath('code', 'invalid_order_transition');
        $this->assertSame(OrderStatus::Shipped, $card->fresh()->status);
        $this->assertSame($cardBefore->payment_status, $card->fresh()->payment_status);
        $this->assertSame(5, $cardSellable->fresh()->stock_quantity);
    }

    public function test_multiple_receipts_cannot_exceed_the_original_order_item_quantity(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 3, stock: 5);
        $token = $this->adminToken();

        $this->createReturn($token, $order, $item, 2, 0);
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['default_reason' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'received_quantity' => 2, 'restock_quantity' => 0]]],
        )->assertStatus(409)->assertJsonPath('code', 'return_decision_receipt_required');
        $this->assertDatabaseCount('return_receipts', 1);
    }

    public function test_same_receipt_key_with_different_content_does_not_mutate(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);
        $key = (string) Str::uuid();
        $base = ['default_reason' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'received_quantity' => 2, 'restock_quantity' => 1]]];

        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', $base)->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [...$base, 'note' => 'Different content'])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_conflict');

        $this->assertDatabaseCount('return_receipts', 1);
        $this->assertSame(6, $sellable->fresh()->stock_quantity);
    }

    public function test_same_correction_key_with_different_content_does_not_mutate(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();
        $receipt = $this->createReturnResponse($token, $order, $item, 1, 1);
        $receiptId = $receipt->json('data.id');
        $receiptItemId = $receipt->json('data.items.0.id');
        $key = (string) Str::uuid();
        $correction = ['expected_version' => 0, 'correction_reason' => 'Inspection correction.', 'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 1, 'restock_quantity' => 0]]];

        $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections", $correction,
        )->assertOk();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            [...$correction, 'correction_reason' => 'Different correction.'],
        )->assertStatus(409)->assertJsonPath('code', 'idempotency_key_conflict');

        $this->assertDatabaseCount('return_corrections', 1);
        $this->assertSame(5, $sellable->fresh()->stock_quantity);
    }

    public function test_correction_replay_after_a_later_correction_returns_original_operation_once(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();
        $receipt = $this->createReturnResponse($token, $order, $item, 2, 2);
        $receiptId = $receipt->json('data.id');
        $receiptItemId = $receipt->json('data.items.0.id');
        $firstKey = (string) Str::uuid();
        $firstPayload = ['expected_version' => 0, 'correction_reason' => 'First correction.', 'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 2, 'restock_quantity' => 1]]];

        $firstCorrection = $this->withToken($token)->withHeader('Idempotency-Key', $firstKey)->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections", $firstPayload,
        )->assertOk()->assertJsonPath('data.operation.new_version', 1)
            ->assertJsonPath('data.operation.items.0.previous_restock_quantity', 2)
            ->assertJsonPath('data.operation.items.0.new_restock_quantity', 1)
            ->assertJsonMissingPath('data.operation.items.0.previous_restockable_quantity')
            ->assertJsonMissingPath('data.operation.items.0.new_restockable_quantity');
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            ['expected_version' => 1, 'correction_reason' => 'Second correction.', 'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 2, 'restock_quantity' => 0]]],
        )->assertOk()->assertJsonPath('data.receipt.version', 2);

        $replay = $this->withToken($token)->withHeader('Idempotency-Key', $firstKey)->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections", $firstPayload,
        )->assertOk();
        $this->assertSame($firstCorrection->json('data'), $replay->json('data'));
        $current = $this->withToken($token)->getJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}")->assertOk();
        $current->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.items.0.current_received_quantity', 2)
            ->assertJsonPath('data.items.0.current_restock_quantity', 0);
        $this->assertDatabaseCount('return_corrections', 2);
        $this->assertSame(5, $sellable->fresh()->stock_quantity);
    }

    public function test_failed_snapshot_persistence_rolls_back_the_receipt_operation(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 1, stock: 5);
        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);
        $shouldFail = true;

        DB::listen(function ($query) use (&$shouldFail): void {
            if ($shouldFail && str_contains($query->sql, 'creation_response_snapshot')) {
                throw new RuntimeException('Snapshot persistence failed.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
                '/api/v1/admin/orders/'.$order->public_id.'/returns',
                ['default_reason' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 1]]],
            );
            $this->fail('The snapshot persistence failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Snapshot persistence failed.', $exception->getMessage());
        } finally {
            $shouldFail = false;
        }

        $this->assertDatabaseCount('return_receipts', 0);
        $this->assertDatabaseCount('return_receipt_items', 0);
        $this->assertSame(5, $sellable->fresh()->stock_quantity);
    }

    public function test_correction_below_reserved_quantity_rolls_back_everything(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 1, stock: 5, reserved: 1);
        $token = $this->adminToken();
        $receipt = $this->createReturnResponse($token, $order, $item, 1, 1);
        $receiptId = $receipt->json('data.id');
        $receiptItemId = $receipt->json('data.items.0.id');
        $sellable->forceFill(['stock_quantity' => 1])->saveQuietly();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            ['expected_version' => 0, 'correction_reason' => 'Unsafe correction.', 'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 1, 'restock_quantity' => 0]]],
        )->assertStatus(409)->assertJsonPath('code', 'return_inventory_reserved_conflict');

        $this->assertSame(1, $sellable->fresh()->stock_quantity);
        $this->assertDatabaseHas('return_receipts', ['id' => $receiptId, 'revision' => 0]);
        $this->assertDatabaseHas('return_receipt_items', ['id' => $receiptItemId, 'effective_restockable_quantity' => 1]);
        $this->assertDatabaseCount('return_corrections', 0);
    }

    public function test_multi_item_receipt_rolls_back_when_one_line_requires_a_missing_target(): void
    {
        [$order, $first, $sellable] = $this->shippedOrder(quantity: 1, stock: 5);
        $second = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'sellable_item_id' => null]);
        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            [
                'default_reason' => 'changed_mind',
                'items' => [
                    ['order_item_id' => $first->id, 'received_quantity' => 1, 'restock_quantity' => 1],
                    ['order_item_id' => $second->id, 'received_quantity' => 1, 'restock_quantity' => 1],
                ],
            ],
        )->assertStatus(409)->assertJsonPath('code', 'return_inventory_target_missing');

        $this->assertSame(5, $sellable->fresh()->stock_quantity);
        $this->assertDatabaseCount('return_receipts', 0);
        $this->assertDatabaseCount('return_receipt_items', 0);
    }

    public function test_admin_authentication_and_authorization_are_enforced(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 1, stock: 5);

        $this->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [])->assertUnauthorized();
        $customer = User::factory()->create();
        $customerToken = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($customerToken)->getJson('/api/v1/admin/orders/'.$order->public_id.'/returns')->assertForbidden();
    }

    public function test_authorized_admin_rejects_cross_order_order_items_without_mutation(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 1, stock: 5);
        [$otherOrder, $otherItem, $otherSellable] = $this->shippedOrder(quantity: 1, stock: 5);

        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['default_reason' => 'changed_mind', 'items' => [['order_item_id' => $otherItem->id, 'received_quantity' => 1, 'restock_quantity' => 0]]],
        )->assertStatus(409)->assertJsonPath('code', 'return_full_order_items_required');
        $this->assertDatabaseCount('return_receipts', 0);
        $this->assertDatabaseCount('return_receipt_items', 0);
        $this->assertSame(5, $otherSellable->fresh()->stock_quantity);
    }

    public function test_original_receipt_replay_remains_immutable_after_correction(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 1, stock: 5);
        $token = $this->adminToken();
        $key = (string) Str::uuid();
        $this->createDeliveryRefusal($token, $order);
        $created = $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['default_reason' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 1]]],
        )->assertCreated();
        $receiptId = $created->json('data.id');
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            ['expected_version' => 0, 'correction_reason' => 'Corrected.', 'items' => [['return_receipt_item_id' => $created->json('data.items.0.id'), 'received_quantity' => 1, 'restock_quantity' => 0]]],
        )->assertOk();

        $replay = $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['default_reason' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 1]]],
        )->assertOk();
        $replay->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.items.0.current_restock_quantity', 1)
            ->assertJsonPath('data.corrections', []);

        $current = $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id.'/returns/'.$receiptId)->assertOk();
        $current->assertJsonPath('data.version', 1)->assertJsonPath('data.items.0.current_restock_quantity', 0);
    }

    public function test_invalid_inputs_no_op_corrections_and_summary_transitions_are_rejected_or_consistent(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);
        $future = now()->addMinute()->toISOString();
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns',
            ['items_received_at' => $future, 'default_reason' => 'other', 'items' => [['order_item_id' => $item->id, 'received_quantity' => 2, 'restock_quantity' => 0]]],
        )->assertUnprocessable();

        $created = $this->createReturnResponse($token, $order, $item, 1, 0);
        $receiptId = $created->json('data.id');
        $receiptItemId = $created->json('data.items.0.id');
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            ['expected_version' => 0, 'correction_reason' => 'No-op.', 'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 1, 'restock_quantity' => 0]]],
        )->assertUnprocessable();
        $this->assertDatabaseCount('return_corrections', 0);
    }

    public function test_soft_deleted_target_can_be_restocked_without_reactivation(): void
    {
        [$order, $item, $sellable] = $this->shippedOrder(quantity: 1, stock: 5);
        $token = $this->adminToken();
        $this->createDeliveryRefusal($token, $order);
        $reservedBefore = $sellable->reserved_quantity;
        $sellable->delete();
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [
                'default_reason' => 'changed_mind',
                'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 1]],
            ])->assertCreated();
        $this->assertNotNull($sellable->fresh()->deleted_at);
        $this->assertSame(6, $sellable->fresh()->stock_quantity);
        $this->assertSame($reservedBefore, $sellable->fresh()->reserved_quantity);
    }

    public function test_summary_transitions_across_receipts_and_corrections(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();
        $first = $this->createReturnResponse($token, $order, $item, 1, 0);
        $this->assertSame('partial', $this->orderSummary($token, $order));
        $second = $this->createReturnResponse($token, $order, $item, 1, 0);
        $this->assertSame('full', $this->orderSummary($token, $order));

        $this->correctReceipt($token, $order, $second->json('data.id'), $second->json('data.items.0.id'), 0, 0, 0);
        $this->assertSame('partial', $this->orderSummary($token, $order));
        $this->correctReceipt($token, $order, $first->json('data.id'), $first->json('data.items.0.id'), 0, 0, 0);
        $this->assertSame('none', $this->orderSummary($token, $order));
    }

    public function test_duplicate_items_unknown_fields_and_other_reason_note_are_rejected(): void
    {
        [$order, $item] = $this->shippedOrder(quantity: 2, stock: 5);
        $token = $this->adminToken();
        $duplicate = ['default_reason' => 'changed_mind', 'items' => [
            ['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 0],
            ['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 0],
        ]];
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', $duplicate)
            ->assertUnprocessable();
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [
                'default_reason' => 'other', 'unexpected' => true,
                'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 0]],
            ])->assertUnprocessable();
        $this->assertDatabaseCount('return_receipts', 0);
    }

    private function createReturn(string $token, Order $order, OrderItem $item, int $received, int $restockable): void
    {
        $this->createReturnResponse($token, $order, $item, $received, $restockable);
    }

    private function createDeliveryRefusal(string $token, Order $order): void
    {
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', [
                'reason' => 'refused_delivery',
            ])->assertCreated();
    }

    private function createReturnResponse(string $token, Order $order, OrderItem $item, int $received, int $restockable)
    {
        // This helper intentionally creates a historical legacy receipt. New
        // receipts must use the decision-linked full-order endpoint; tests
        // exercising old corrections/summary behavior need pre-existing data.
        $receipt = ReturnReceipt::query()->create([
            'order_id' => $order->id,
            'received_at' => now()->subMinute(),
            'default_reason' => 'refused_delivery',
            'revision' => 0,
            'recorded_by_user_id' => User::factory()->create()->id,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
        ]);
        ReturnReceiptItem::query()->create([
            'return_receipt_id' => $receipt->id,
            'order_item_id' => $item->id,
            'sellable_item_id' => $item->sellable_item_id,
            'original_quantity' => $item->quantity,
            'initial_received_quantity' => $received,
            'initial_restockable_quantity' => $restockable,
            'initial_reason' => 'refused_delivery',
            'effective_received_quantity' => $received,
            'effective_restockable_quantity' => $restockable,
            'effective_reason' => 'refused_delivery',
        ]);
        if ($restockable > 0) {
            SellableItem::query()->whereKey($item->sellable_item_id)->increment('stock_quantity', $restockable);
        }

        return $this->withToken($token)->getJson(
            '/api/v1/admin/orders/'.$order->public_id.'/returns/'.$receipt->id,
        )->assertOk();
    }

    private function correctReceipt(string $token, Order $order, int $receiptId, int $receiptItemId, int $revision, int $received, int $restockable): void
    {
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            "/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections",
            [
                'expected_version' => $revision,
                'correction_reason' => 'Correction for test data.',
                'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => $received, 'restock_quantity' => $restockable]],
            ],
        )->assertOk();
    }

    private function orderSummary(string $token, Order $order): string
    {
        return (string) $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)
            ->assertOk()->json('data.return_summary');
    }

    private function shippedOrder(int $quantity, int $stock, int $reserved = 0, PaymentMethod $paymentMethod = PaymentMethod::CashOnDelivery, PaymentStatus $paymentStatus = PaymentStatus::Unpaid): array
    {
        $product = Product::query()->create([
            'slug' => 'return-product-'.Str::lower(Str::random(8)),
            'name_ar' => 'Ù…Ù†ØªØ¬', 'name_en' => 'Product', 'status' => 'draft',
        ]);
        $sellable = SellableItem::query()->create([
            'product_id' => $product->id, 'sku' => 'RETURN-'.Str::upper(Str::random(8)),
            'price' => '100.00', 'stock_quantity' => $stock, 'reserved_quantity' => $reserved,
            'status' => 'active', 'combination_key' => '',
        ]);
        $order = Order::factory()->shipped()->create([
            'payment_method' => $paymentMethod, 'payment_status' => $paymentStatus, 'shipped_at' => now()->subHour(),
        ]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => $quantity, 'sellable_item_id' => $sellable->id]);

        return [$order, $item, $sellable];
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id, 'status' => AdminMembershipStatus::Active]);

        return $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }
}
