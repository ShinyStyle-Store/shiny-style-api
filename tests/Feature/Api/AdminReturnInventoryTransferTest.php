<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductConversion;
use App\Models\ReturnInventoryTransfer;
use App\Models\ReturnInventoryTransferReversal;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminReturnInventoryTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id, 'status' => AdminMembershipStatus::Active]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_post_conversion_return_can_be_split_and_transfer_reversal_is_complete_and_idempotent(): void
    {
        [$product, $source, $targets] = $this->convertedProduct();
        [$order, $receipt, $receiptItem] = $this->receivedReturn($product, $source, 3, 3);
        $snapshot = $order->items()->firstOrFail()->only(['sellable_item_id', 'sku', 'product_name_en', 'options_snapshot', 'unit_price', 'line_total']);
        $orderState = $order->fresh()->only(['status', 'payment_status', 'total']);
        $sourceBefore = (int) $source->fresh()->stock_quantity;
        $totalStockBefore = $sourceBefore + (int) $targets[0]->fresh()->stock_quantity + (int) $targets[1]->fresh()->stock_quantity;
        $firstKey = (string) Str::uuid();
        $first = $this->transfer($order, $receipt, $receiptItem, $targets[0], 1, $firstKey)
            ->assertCreated()->assertJsonPath('data.quantity', 1);
        $this->assertSame($sourceBefore - 1, (int) $source->fresh()->stock_quantity);
        $this->assertSame(3, (int) $targets[0]->fresh()->stock_quantity);

        $retry = $this->transfer($order, $receipt, $receiptItem, $targets[0], 1, $firstKey)->assertOk();
        $this->assertSame($first->json('data'), $retry->json('data'));
        $secondKey = (string) Str::uuid();
        $this->assertNotSame($firstKey, $secondKey);
        $this->assertSame(3, $this->eligibility($order, $receipt, $receiptItem)->json('data.current_restock_quantity'));
        $this->assertSame(0, $this->eligibility($order, $receipt, $receiptItem)->json('data.pre_conversion_restock_quantity'));
        $this->assertSame(1, $this->eligibility($order, $receipt, $receiptItem)->json('data.transferred_quantity'));
        $this->assertSame($sourceBefore - 1, (int) $source->fresh()->stock_quantity);
        $this->assertSame(1, ReturnInventoryTransfer::query()->count());
        $firstTargetValues = $targets[0]->optionValues()->orderBy('product_option_values.id')->pluck('product_option_values.id')->all();
        $secondTargetValues = $targets[1]->optionValues()->orderBy('product_option_values.id')->pluck('product_option_values.id')->all();
        $this->assertCount(1, $firstTargetValues);
        $this->assertCount(1, $secondTargetValues);
        $this->assertNotSame($firstTargetValues, $secondTargetValues);
        $this->assertSame(implode(',', $secondTargetValues), (string) $targets[1]->combination_key);
        $second = $this->transfer(
            $order,
            $receipt,
            $receiptItem,
            $targets[1],
            2,
            $secondKey,
        );

        $second->assertCreated()->assertJsonPath('data.quantity', 2);
        $secondTransferId = (int) $second->json('data.id');
        $originalTransferHistory = ReturnInventoryTransfer::query()->findOrFail($secondTransferId)->getAttributes();
        $this->assertSame($sourceBefore - 3, (int) $source->fresh()->stock_quantity);
        $this->assertSame(6, (int) $targets[1]->fresh()->stock_quantity);
        $this->assertSame(3, $this->eligibility($order, $receipt, $receiptItem)->json('data.eligible_quantity'));
        $this->assertSame(0, $this->eligibility($order, $receipt, $receiptItem)->json('data.eligible_remaining_quantity'));

        $this->transfer($order, $receipt, $receiptItem, $targets[1], 1, (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'return_inventory_eligible_quantity_insufficient');
        $reversalKey = (string) Str::uuid();
        $reverseUrl = $this->reverseUrl($order, $receipt, $receiptItem, (int) $second->json('data.id'));
        $reversed = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', $reversalKey)
            ->postJson($reverseUrl, ['reversal_reason' => 'The selected variant was incorrect.'])
            ->assertCreated()->assertJsonPath('data.transfer_id', $second->json('data.id'));
        $this->assertSame($sourceBefore - 1, (int) $source->fresh()->stock_quantity);
        $this->assertSame(4, (int) $targets[1]->fresh()->stock_quantity);
        $this->assertSame(2, $this->eligibility($order, $receipt, $receiptItem)->json('data.eligible_remaining_quantity'));

        $replay = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', $reversalKey)
            ->postJson($reverseUrl, ['reversal_reason' => 'The selected variant was incorrect.'])->assertOk();
        $this->assertSame($reversed->json('data'), $replay->json('data'));
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', $reversalKey)
            ->postJson($reverseUrl, ['reversal_reason' => 'A changed request with the same key.'])
            ->assertConflict()->assertJsonPath('code', 'idempotency_key_conflict');
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($reverseUrl, ['reversal_reason' => 'Try again.'])
            ->assertConflict()->assertJsonPath('code', 'return_inventory_transfer_already_reversed');
        $this->assertSame($originalTransferHistory, ReturnInventoryTransfer::query()->findOrFail($secondTransferId)->getAttributes());
        $this->assertSame($snapshot, $order->items()->firstOrFail()->only(array_keys($snapshot)));
        $this->assertSame($orderState, $order->fresh()->only(array_keys($orderState)));
        $this->assertSame($totalStockBefore, (int) $source->fresh()->stock_quantity + (int) $targets[0]->fresh()->stock_quantity + (int) $targets[1]->fresh()->stock_quantity);
        $this->assertSame(2, ReturnInventoryTransfer::query()->count());
        $this->assertSame(1, ReturnInventoryTransferReversal::query()->count());
        $this->assertNotNull(SellableItem::withTrashed()->findOrFail($source->id)->superseded_at);
        $currentProductStock = (int) SellableItem::query()->where('product_id', $product->id)
            ->where('status', 'active')->whereNull('superseded_at')->sum('stock_quantity');
        $this->assertSame((int) $targets[0]->fresh()->stock_quantity + (int) $targets[1]->fresh()->stock_quantity, $currentProductStock);
    }

    public function test_pre_conversion_return_restock_is_not_eligible_for_a_second_allocation(): void
    {
        [$product, $source] = $this->simpleProduct();
        [$order, $item] = $this->shippedOrder($product, $source, 1);
        $decision = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/refuse-delivery", ['reason' => 'refused_delivery'])
            ->assertCreated();
        $receipt = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns", [
                'default_reason' => 'refused_delivery',
                'items' => [['order_item_id' => $item->id, 'received_quantity' => 1, 'restock_quantity' => 1]],
            ])->assertCreated();
        $this->assertSame('waiting_for_return', $decision->json('data.status'));

        [$product, $source, $targets] = $this->convertExistingSimpleProduct($product, $source, 7);
        $receiptId = (int) $receipt->json('data.id');
        $itemId = (int) $receipt->json('data.items.0.id');
        $this->eligibility($order, $receiptId, $itemId)->assertOk()
            ->assertJsonPath('data.eligibility_known', true)
            ->assertJsonPath('data.current_restock_quantity', 1)
            ->assertJsonPath('data.pre_conversion_restock_quantity', 1)
            ->assertJsonPath('data.eligible_quantity', 0);
        $this->transfer($order, $receiptId, $itemId, $targets[0], 1, (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'return_inventory_eligible_quantity_insufficient');
        $this->assertSame(0, (int) $source->fresh()->stock_quantity);
        $this->assertDatabaseCount('return_inventory_transfers', 0);
    }

    public function test_conversion_without_a_baseline_rejects_transfer_instead_of_guessing_eligibility(): void
    {
        [$product, $source, $targets] = $this->convertedProduct();
        ProductConversion::query()->where('source_sellable_item_id', $source->id)->update(['return_restock_baseline' => null]);
        [$order, $receipt, $receiptItem] = $this->receivedReturn($product, $source, 1, 1);

        $this->eligibility($order, $receipt, $receiptItem)->assertOk()
            ->assertJsonPath('data.eligibility_known', false)
            ->assertJsonPath('data.eligible_quantity', 0);
        $this->transfer($order, $receipt, $receiptItem, $targets[0], 1, (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'return_inventory_eligibility_unknown');
        $this->assertSame(1, (int) $source->fresh()->stock_quantity);
        $this->assertDatabaseCount('return_inventory_transfers', 0);
    }

    public function test_corrections_create_new_eligibility_and_cannot_reduce_or_reverse_a_transferred_return(): void
    {
        [$product, $source, $targets] = $this->convertedProduct();
        [$order, $receipt, $receiptItem] = $this->receivedReturn($product, $source, 2, 0);
        $correctionUrl = "/api/v1/admin/orders/{$order->public_id}/returns/{$receipt}/corrections";
        $corrected = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($correctionUrl, [
                'expected_version' => 0,
                'correction_reason' => 'Inspection found two restockable units.',
                'items' => [['return_receipt_item_id' => $receiptItem, 'received_quantity' => 2, 'restock_quantity' => 2]],
            ])->assertOk();
        $this->assertSame(2, $this->eligibility($order, $receipt, $receiptItem)->json('data.eligible_remaining_quantity'));
        $this->transfer($order, $receipt, $receiptItem, $targets[0], 2, (string) Str::uuid())->assertCreated();

        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($correctionUrl, [
                'expected_version' => 1,
                'correction_reason' => 'Attempt to reduce transferred stock.',
                'items' => [['return_receipt_item_id' => $receiptItem, 'received_quantity' => 2, 'restock_quantity' => 1]],
            ])->assertConflict()->assertJsonPath('code', 'return_inventory_transfer_requires_reversal');
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns/{$receipt}/reverse", [
                'expected_version' => 1,
                'reversal_reason' => 'Attempt before reversing transfer.',
            ])->assertConflict()->assertJsonPath('code', 'return_inventory_transfer_requires_reversal');

        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($correctionUrl, [
                'expected_version' => 1,
                'correction_reason' => 'Only update the inspection reason.',
                'items' => [[
                    'return_receipt_item_id' => $receiptItem,
                    'received_quantity' => 2,
                    'restock_quantity' => 2,
                    'reason' => 'defective_item',
                    'note' => 'Reason changed; stock remains untouched.',
                ]],
            ])->assertOk();

        $transfer = ReturnInventoryTransfer::query()->firstOrFail();
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->reverseUrl($order, $receipt, $receiptItem, (int) $transfer->getKey()), [
                'reversal_reason' => 'Restore the inspected stock to the historical source.',
            ])->assertCreated();
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($correctionUrl, [
                'expected_version' => 2,
                'correction_reason' => 'Correct after the transfer was reversed.',
                'items' => [['return_receipt_item_id' => $receiptItem, 'received_quantity' => 2, 'restock_quantity' => 1]],
            ])->assertOk();
        $this->assertSame(1, (int) $source->fresh()->stock_quantity);
        $this->assertNotNull($corrected->json('data.operation.id'));
    }

    public function test_transfer_rejects_invalid_targets_and_reversal_respects_target_available_quantity(): void
    {
        [$product, $source, $targets] = $this->convertedProduct();
        [$otherProduct, $otherSource, $otherTargets] = $this->convertedProduct();
        [$order, $receipt, $receiptItem] = $this->receivedReturn($product, $source, 2, 2);

        $this->transfer($order, $receipt, $receiptItem, $otherTargets[0], 1, (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'return_inventory_target_invalid');
        $transfer = $this->transfer($order, $receipt, $receiptItem, $targets[0], 2, (string) Str::uuid())->assertCreated();
        $targets[0]->forceFill(['reserved_quantity' => $targets[0]->fresh()->stock_quantity])->saveQuietly();
        $targetStock = (int) $targets[0]->fresh()->stock_quantity;
        $sourceStock = (int) $source->fresh()->stock_quantity;
        $reverseUrl = $this->reverseUrl($order, $receipt, $receiptItem, (int) $transfer->json('data.id'));
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($reverseUrl, ['reversal_reason' => 'Reserved target stock cannot be moved.'])
            ->assertConflict()->assertJsonPath('code', 'return_inventory_target_unavailable');
        $this->assertSame($targetStock, (int) $targets[0]->fresh()->stock_quantity);
        $this->assertSame($sourceStock, (int) $source->fresh()->stock_quantity);
        $this->assertDatabaseCount('return_inventory_transfer_reversals', 0);
    }

    public function test_transfer_rejects_inactive_target_and_rolls_back_inventory_if_a_late_write_fails(): void
    {
        [$product, $source, $targets] = $this->convertedProduct();
        [$order, $receipt, $receiptItem] = $this->receivedReturn($product, $source, 1, 1);
        $targets[1]->update(['status' => 'inactive']);
        $this->transfer($order, $receipt, $receiptItem, $targets[1], 1, (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'return_inventory_target_invalid');
        $targets[1]->update(['status' => 'active']);
        $targets[1]->optionValues()->detach();
        $this->transfer($order, $receipt, $receiptItem, $targets[1], 1, (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'return_inventory_target_invalid');
        $this->assertSame('draft', $product->fresh()->status);

        $sourceStock = (int) $source->fresh()->stock_quantity;
        $targetStock = (int) $targets[0]->fresh()->stock_quantity;
        Event::listen('eloquent.creating: '.ReturnInventoryTransfer::class, fn () => throw new RuntimeException('Simulated late transfer audit write failure.'));
        $this->transfer($order, $receipt, $receiptItem, $targets[0], 1, (string) Str::uuid())->assertServerError();

        $this->assertSame($sourceStock, (int) $source->fresh()->stock_quantity);
        $this->assertSame($targetStock, (int) $targets[0]->fresh()->stock_quantity);
        $this->assertDatabaseCount('return_inventory_transfers', 0);
    }

    public function test_reversal_can_use_archived_target_and_same_key_with_different_transfer_payload_conflicts(): void
    {
        [$product, $source, $targets] = $this->convertedProduct();
        [$order, $receipt, $receiptItem] = $this->receivedReturn($product, $source, 1, 1);
        $key = (string) Str::uuid();
        $first = $this->transfer($order, $receipt, $receiptItem, $targets[0], 1, $key)->assertCreated();
        $this->transfer($order, $receipt, $receiptItem, $targets[1], 1, $key)
            ->assertConflict()->assertJsonPath('code', 'idempotency_key_conflict');
        $targets[0]->delete();
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->reverseUrl($order, $receipt, $receiptItem, (int) $first->json('data.id')),
                ['reversal_reason' => 'Historical repair after target archival.'])->assertCreated();
        $this->assertNotNull($targets[0]->fresh()->deleted_at);
        $this->assertNotNull($source->fresh()->superseded_at);
        $this->assertSame(0, (int) $source->fresh()->reserved_quantity);
    }

    private function convertedProduct(): array
    {
        [$product, $source] = $this->simpleProduct();

        return $this->convertExistingSimpleProduct($product, $source, 6);
    }

    private function convertExistingSimpleProduct(Product $product, SellableItem $source, int $stock): array
    {
        if ((int) $source->fresh()->stock_quantity !== $stock) {
            $source->update(['stock_quantity' => $stock]);
        }
        $context = $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/products/{$product->id}/conversion-context/{$source->id}")
            ->assertOk()->json('data');
        $options = [['ref' => 'fit', 'code' => 'fit', 'name_ar' => 'المقاس', 'name_en' => 'Fit', 'sort_order' => 0,
            'values' => [
                ['ref' => 'regular', 'code' => 'regular', 'value_ar' => 'عادي', 'value_en' => 'Regular', 'sort_order' => 0],
                ['ref' => 'wide', 'code' => 'wide', 'value_ar' => 'واسع', 'value_en' => 'Wide', 'sort_order' => 1],
            ],
        ]];
        $payload = [
            'context_token' => $context['contextToken'],
            'options' => $options,
            'variants' => [
                ['ref' => 'regular-variant', 'sku' => 'REGULAR-'.Str::upper(Str::random(8)), 'price' => '25.00', 'stock_quantity' => 2, 'sort_order' => 0, 'option_value_refs' => ['regular']],
                ['ref' => 'wide-variant', 'sku' => 'WIDE-'.Str::upper(Str::random(8)), 'price' => '25.00', 'stock_quantity' => $stock - 2, 'sort_order' => 1, 'option_value_refs' => ['wide']],
            ],
            'default_variant_ref' => 'regular-variant',
        ];
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertCreated();
        $targets = SellableItem::query()->where('product_id', $product->id)->where('status', 'active')->orderBy('sort_order')->get()->all();

        return [$product, $source, $targets];
    }

    private function simpleProduct(): array
    {
        $product = Product::query()->create([
            'slug' => 'transfer-'.Str::lower(Str::random(8)),
            'name_ar' => 'منتج اختبار',
            'name_en' => 'Transfer Product',
            'status' => 'draft',
        ]);
        $category = Category::query()->create([
            'slug' => 'transfer-category-'.Str::lower(Str::random(8)),
            'name_ar' => 'تصنيف اختبار',
            'name_en' => 'Transfer Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $source = SellableItem::query()->create([
            'product_id' => $product->id,
            'sku' => 'TRANSFER-SOURCE-'.Str::upper(Str::random(8)),
            'price' => '25.00',
            'stock_quantity' => 6,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$product, $source];
    }

    private function receivedReturn(Product $product, SellableItem $source, int $quantity, int $restock): array
    {
        [$order, $orderItem] = $this->shippedOrder($product, $source, $quantity);
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/refuse-delivery", ['reason' => 'refused_delivery'])
            ->assertCreated();
        $receipt = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/orders/{$order->public_id}/returns", [
                'default_reason' => 'refused_delivery',
                'items' => [['order_item_id' => $orderItem->id, 'received_quantity' => $quantity, 'restock_quantity' => $restock]],
            ])->assertCreated();

        return [$order, (int) $receipt->json('data.id'), (int) $receipt->json('data.items.0.id')];
    }

    private function shippedOrder(Product $product, SellableItem $source, int $quantity): array
    {
        $order = Order::factory()->shipped()->create(['shipped_at' => now()->subHour()]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $source->id,
            'sku' => $source->sku,
            'quantity' => $quantity,
        ]);

        return [$order, $item];
    }

    private function transfer(Order $order, int $receipt, int $item, SellableItem $target, int $quantity, string $key)
    {
        return $this->withToken($this->adminToken)->withHeader('Idempotency-Key', $key)
            ->postJson($this->transferUrl($order, $receipt, $item), [
                'target_sellable_item_id' => $target->id,
                'quantity' => $quantity,
                'reason' => 'Administrative redistribution after return inspection.',
            ]);
    }

    private function eligibility(Order $order, int $receipt, int $item)
    {
        return $this->withToken($this->adminToken)->getJson($this->transferUrl($order, $receipt, $item));
    }

    private function transferUrl(Order $order, int $receipt, int $item): string
    {
        return "/api/v1/admin/orders/{$order->public_id}/returns/{$receipt}/items/{$item}/inventory-transfers";
    }

    private function reverseUrl(Order $order, int $receipt, int $item, int $transfer): string
    {
        return $this->transferUrl($order, $receipt, $item)."/{$transfer}/reverse";
    }
}
