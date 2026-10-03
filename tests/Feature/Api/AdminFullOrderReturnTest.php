<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\OrderReturnStatus;
use App\Enums\OrderStatus;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFullOrderReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_receipt_accepts_multiple_items_partial_restock_and_updates_workflow_only(): void
    {
        [$order, $items, $sellable] = $this->workflowOrder([2, 1]);
        $before = $order->fresh();
        $token = $this->adminToken();
        $this->createDecision($token, $order);

        $response = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [
                'default_reason' => 'changed_mind',
                'items' => [
                    ['order_item_id' => $items[0]->id, 'received_quantity' => 2, 'restock_quantity' => 1],
                    ['order_item_id' => $items[1]->id, 'received_quantity' => 1, 'restock_quantity' => 0],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.workflow_linked', true)
            ->assertJsonPath('data.reversed', false)
            ->assertJsonPath('data.return_summary', 'full');
        $this->assertSame(11, $sellable->fresh()->stock_quantity);
        $this->assertSame(1, $sellable->fresh()->reserved_quantity);
        $this->assertSame($before->total, $order->fresh()->total);
        $this->assertSame(OrderStatus::DeliveryRefused, $order->fresh()->status);
        $this->assertSame(OrderReturnStatus::Received, $order->fresh()->orderReturn->status);
    }

    public function test_full_receipt_rejects_incomplete_items_without_mutation(): void
    {
        [$order, $items, $token] = $this->fullReceiptFixture();
        $this->receipt($token, $order, ['default_reason' => 'changed_mind', 'items' => [
            ['order_item_id' => $items[0]->id, 'received_quantity' => 2, 'restock_quantity' => 0],
        ]])->assertStatus(409)->assertJsonPath('code', 'return_full_order_items_required');
        $this->assertDatabaseCount('return_receipts', 0);
    }

    public function test_full_receipt_rejects_duplicate_items_with_validation_errors(): void
    {
        [$order, $items, $token] = $this->fullReceiptFixture();
        $this->receipt($token, $order, ['default_reason' => 'changed_mind', 'items' => [
            ['order_item_id' => $items[0]->id, 'received_quantity' => 2, 'restock_quantity' => 0],
            ['order_item_id' => $items[0]->id, 'received_quantity' => 2, 'restock_quantity' => 0],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.order_item_id');
        $this->assertDatabaseCount('return_receipts', 0);
    }

    public function test_full_receipt_rejects_foreign_items_without_mutation(): void
    {
        [$order, $items, $token] = $this->fullReceiptFixture();
        $foreignOrder = Order::factory()->shipped()->create();
        $foreign = OrderItem::factory()->create(['order_id' => $foreignOrder->id, 'quantity' => 1]);
        $this->receipt($token, $order, ['default_reason' => 'changed_mind', 'items' => [
            ['order_item_id' => $items[0]->id, 'received_quantity' => 2, 'restock_quantity' => 0],
            ['order_item_id' => $foreign->id, 'received_quantity' => 1, 'restock_quantity' => 0],
        ]])->assertStatus(409)->assertJsonPath('code', 'return_full_order_items_required');
        $this->assertDatabaseCount('return_receipts', 0);
    }

    public function test_full_receipt_rejects_incorrect_quantities_without_mutation(): void
    {
        [$order, $items, $token] = $this->fullReceiptFixture();
        $this->receipt($token, $order, ['default_reason' => 'changed_mind', 'items' => [
            ['order_item_id' => $items[0]->id, 'received_quantity' => 1, 'restock_quantity' => 0],
            ['order_item_id' => $items[1]->id, 'received_quantity' => 1, 'restock_quantity' => 0],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('return_receipts', 0);
    }

    public function test_correction_keeps_new_receipt_full_and_reversal_allows_replacement(): void
    {
        [$order, $items, $sellable] = $this->workflowOrder([2]);
        $token = $this->adminToken();
        $this->createDecision($token, $order);
        $receipt = $this->receipt($token, $order, [
            'default_reason' => 'changed_mind',
            'items' => [['order_item_id' => $items[0]->id, 'received_quantity' => 2, 'restock_quantity' => 2]],
        ])->assertCreated();
        $receiptId = $receipt->json('data.id');
        $receiptItemId = $receipt->json('data.items.0.id');

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections", [
                'expected_version' => 0,
                'correction_reason' => 'Restock correction.',
                'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 0, 'restock_quantity' => 0]],
            ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $correctionKey = (string) Str::uuid();
        $correction = $this->withToken($token)->withHeader('Idempotency-Key', $correctionKey)
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections", [
                'expected_version' => 0,
                'correction_reason' => 'Restock correction.',
                'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 2, 'restock_quantity' => 1]],
            ])->assertOk()->assertJsonPath('data.operation.items.0.previous_restock_quantity', 2)
            ->assertJsonPath('data.operation.items.0.new_restock_quantity', 1)
            ->assertJsonMissingPath('data.operation.items.0.previous_restockable_quantity')
            ->assertJsonMissingPath('data.operation.items.0.new_restockable_quantity')
            ->assertJsonPath('data.receipt.items.0.current_received_quantity', 2);

        $reverseKey = (string) Str::uuid();
        $reversal = $this->withToken($token)->withHeader('Idempotency-Key', $reverseKey)
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/reverse", [
                'expected_version' => 1,
                'reversal_reason' => 'Wrong shipment.',
            ])->assertCreated()->assertJsonPath('data.receipt.reversed', true)
            ->assertJsonPath('data.receipt.corrections.0.items.0.previous_restock_quantity', 2)
            ->assertJsonPath('data.receipt.corrections.0.items.0.new_restock_quantity', 1)
            ->assertJsonMissingPath('data.receipt.corrections.0.items.0.previous_restockable_quantity')
            ->assertJsonMissingPath('data.receipt.corrections.0.items.0.new_restockable_quantity');
        $this->assertSame(10, $sellable->fresh()->stock_quantity);
        $this->assertSame('none', $this->orderSummary($token, $order));

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections", [
                'expected_version' => 2,
                'correction_reason' => 'Too late.',
                'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 2, 'restock_quantity' => 0]],
            ])->assertStatus(409)->assertJsonPath('code', 'return_receipt_reversed');

        $replacement = $this->receipt($token, $order, [
            'default_reason' => 'changed_mind',
            'items' => [['order_item_id' => $items[0]->id, 'received_quantity' => 2, 'restock_quantity' => 0]],
        ])->assertCreated();
        $this->assertNotSame($receiptId, $replacement->json('data.id'));
        $correctionReplay = $this->withToken($token)->withHeader('Idempotency-Key', $correctionKey)
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/corrections", [
                'expected_version' => 0,
                'correction_reason' => 'Restock correction.',
                'items' => [['return_receipt_item_id' => $receiptItemId, 'received_quantity' => 2, 'restock_quantity' => 1]],
            ])->assertOk();
        $this->assertSame($correction->json('data'), $correctionReplay->json('data'));

        $reversalReplay = $this->withToken($token)->withHeader('Idempotency-Key', $reverseKey)
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/reverse", [
                'expected_version' => 1,
                'reversal_reason' => 'Wrong shipment.',
            ])->assertOk()->assertJsonPath('data.receipt.reversed', true);
        $this->assertSame($reversal->json('data'), $reversalReplay->json('data'));
    }

    public function test_original_receipt_replay_is_unchanged_after_reversal_and_replacement(): void
    {
        [$order, $items] = $this->workflowOrder([1]);
        $token = $this->adminToken();
        $this->createDecision($token, $order);
        $createKey = (string) Str::uuid();
        $payload = [
            'default_reason' => 'changed_mind',
            'items' => [['order_item_id' => $items[0]->id, 'received_quantity' => 1, 'restock_quantity' => 1]],
        ];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', $createKey)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', $payload)
            ->assertCreated();
        $receiptId = $first->json('data.id');
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}/reverse", [
                'expected_version' => 0,
                'reversal_reason' => 'Wrong shipment.',
            ])->assertCreated();
        $this->receipt($token, $order, [
            'default_reason' => 'changed_mind',
            'items' => [['order_item_id' => $items[0]->id, 'received_quantity' => 1, 'restock_quantity' => 0]],
        ])->assertCreated();

        $replay = $this->withToken($token)->withHeader('Idempotency-Key', $createKey)
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', $payload)
            ->assertOk();
        $this->assertSame($first->json('data'), $replay->json('data'));

        $this->withToken($token)->getJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receiptId}")
            ->assertOk()->assertJsonPath('data.reversed', true)->assertJsonPath('data.version', 1);
    }

    public function test_receipt_requires_a_decision_and_restock_rolls_back_on_inventory_failure(): void
    {
        [$order, $items, $sellable] = $this->workflowOrder([1, 1]);
        $order->forceFill(['status' => OrderStatus::DeliveryRefused])->saveQuietly();
        $token = $this->adminToken();
        $payload = [
            'default_reason' => 'changed_mind',
            'items' => [
                ['order_item_id' => $items[0]->id, 'received_quantity' => 1, 'restock_quantity' => 1],
                ['order_item_id' => $items[1]->id, 'received_quantity' => 1, 'restock_quantity' => 1],
            ],
        ];
        $this->receipt($token, $order, $payload)->assertStatus(409)->assertJsonPath('code', 'return_decision_receipt_required');

        $order->forceFill(['status' => OrderStatus::Shipped])->saveQuietly();
        $this->createDecision($token, $order);
        $sellable->forceFill(['stock_quantity' => 2147483647])->saveQuietly();
        $this->receipt($token, $order, $payload)->assertStatus(409)->assertJsonPath('code', 'return_inventory_overflow');
        $this->assertDatabaseCount('return_receipts', 0);
        $this->assertSame(OrderReturnStatus::WaitingForReturn, $order->fresh()->orderReturn->status);
    }

    private function createDecision(string $token, Order $order): void
    {
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', ['reason' => 'refused_delivery'])
            ->assertCreated();
    }

    private function receipt(string $token, Order $order, array $payload)
    {
        return $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', $payload);
    }

    private function orderSummary(string $token, Order $order): string
    {
        return (string) $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)
            ->assertOk()->json('data.return_summary');
    }

    /** @return array{0: Order, 1: list<OrderItem>, 2: string} */
    private function fullReceiptFixture(): array
    {
        [$order, $items] = $this->workflowOrder([2, 1]);
        $token = $this->adminToken();
        $this->createDecision($token, $order);

        return [$order, $items, $token];
    }

    private function workflowOrder(array $quantities): array
    {
        $product = Product::query()->create([
            'slug' => 'phase2-'.Str::lower(Str::random(10)),
            'name_ar' => 'منتج',
            'name_en' => 'Phase 2 Product',
            'status' => 'draft',
        ]);
        $sellable = SellableItem::query()->create([
            'product_id' => $product->id,
            'sku' => 'PHASE2-'.Str::upper(Str::random(8)),
            'price' => '100.00',
            'stock_quantity' => 10,
            'reserved_quantity' => 1,
            'status' => 'active',
            'combination_key' => '',
        ]);
        $order = Order::factory()->shipped()->create(['shipped_at' => now()->subHour()]);
        $items = collect($quantities)->map(fn (int $quantity): OrderItem => OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => $quantity,
            'sellable_item_id' => $sellable->id,
        ]))->all();

        return [$order, $items, $sellable];
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id, 'status' => AdminMembershipStatus::Active]);

        return $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }
}
