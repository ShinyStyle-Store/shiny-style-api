<?php

namespace App\Services;

use App\Exceptions\PhysicalReturnConflictException;
use App\Http\Resources\AdminReturnInventoryTransferResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductConversion;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ReturnInventoryTransfer;
use App\Models\ReturnInventoryTransferReversal;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\SellableItem;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ReturnInventoryTransferService
{
    private const MAX_INTEGER = 2147483647;

    /** @return array<string, mixed> */
    public function show(Order $order, int $receiptId, int $receiptItemId): array
    {
        $lockedOrder = Order::query()->whereKey($order->getKey())->firstOrFail();
        $receipt = ReturnReceipt::query()
            ->where('order_id', $lockedOrder->getKey())
            ->whereKey($receiptId)
            ->firstOrFail();
        $item = ReturnReceiptItem::query()
            ->where('return_receipt_id', $receipt->getKey())
            ->whereKey($receiptItemId)
            ->firstOrFail();

        return $this->eligibilityPayload($lockedOrder, $receipt, $item);
    }

    /** @param array{target_sellable_item_id: int, quantity: int, reason: string} $data
     * @return array{transfer: ReturnInventoryTransfer, payload: array<string, mixed>, replayed: bool}
     */
    public function transfer(
        Order $order,
        int $receiptId,
        int $receiptItemId,
        array $data,
        int $actorId,
        string $idempotencyKey,
    ): array {
        $fingerprint = $this->fingerprint('transfer', (int) $order->getKey(), $receiptId, $receiptItemId, $data);

        try {
            return DB::transaction(function () use ($order, $receiptId, $receiptItemId, $data, $actorId, $idempotencyKey, $fingerprint): array {
                $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $existing = ReturnInventoryTransfer::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->assertFingerprint($existing->request_fingerprint, $fingerprint);

                    return ['transfer' => $existing, 'payload' => $existing->response_snapshot ?? $this->transferPayload($existing), 'replayed' => true];
                }

                $decision = $lockedOrder->orderReturn()->lockForUpdate()->first();
                [$receipt, $item, $orderItem] = $this->lockReturnItem($lockedOrder, $receiptId, $receiptItemId);
                $sourceId = $this->assertHistoricalSource($item, $orderItem);
                $conversion = ProductConversion::query()->where('source_sellable_item_id', $sourceId)->first();
                if ($conversion === null) {
                    $this->conflict('return_inventory_source_not_converted', 'The historical source has no recorded product conversion.');
                }
                if ((int) $orderItem->product_id !== (int) $conversion->product_id) {
                    $this->conflict('return_inventory_source_mismatch', 'The historical source and original order item belong to different products.');
                }
                $product = Product::withTrashed()->whereKey($conversion->product_id)->lockForUpdate()->firstOrFail();
                $this->assertReturnIsCurrent($receipt, $decision);
                $ids = collect([$sourceId, (int) $data['target_sellable_item_id']])->unique()->sort()->values()->all();
                $sellables = SellableItem::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $sellables->get($sourceId);
                $target = $sellables->get((int) $data['target_sellable_item_id']);

                $this->assertSource($source, $product, $conversion);
                $this->assertTarget($target, $product);
                if ($conversion->return_restock_baseline === null) {
                    $this->conflict('return_inventory_eligibility_unknown', 'This conversion predates return eligibility baselines, so this return cannot be safely transferred.');
                }
                $baseline = (int) ($conversion->return_restock_baseline[(string) $item->getKey()] ?? 0);
                $eligible = max(0, (int) $item->effective_restockable_quantity - $baseline);
                $alreadyTransferred = $this->activeTransferredQuantity($item->getKey(), true);
                $quantity = (int) $data['quantity'];
                if ($eligible - $alreadyTransferred < $quantity) {
                    $this->conflict('return_inventory_eligible_quantity_insufficient', 'The requested quantity exceeds the eligible, unallocated restock for this return item.');
                }

                $sourceStock = (int) $source->stock_quantity;
                $sourceReserved = (int) $source->reserved_quantity;
                $targetStock = (int) $target->stock_quantity;
                $targetReserved = (int) $target->reserved_quantity;
                if ($sourceStock < $sourceReserved || $sourceStock - $sourceReserved < $quantity) {
                    $this->conflict('return_inventory_source_unavailable', 'The historical source does not have enough available inventory for this transfer.');
                }
                if ($targetStock < $targetReserved) {
                    $this->conflict('return_inventory_invariant_violation', 'The target inventory is below its reserved quantity.');
                }
                if ($targetStock > self::MAX_INTEGER - $quantity) {
                    $this->conflict('return_inventory_overflow', 'The target inventory would exceed the supported quantity range.');
                }

                $sourceAfter = $sourceStock - $quantity;
                $targetAfter = $targetStock + $quantity;
                if ($sourceAfter < $sourceReserved || $targetAfter < $targetReserved) {
                    $this->conflict('return_inventory_reserved_conflict', 'The transfer would make inventory lower than reserved quantity.');
                }
                $source->forceFill(['stock_quantity' => $sourceAfter])->saveQuietly();
                $target->forceFill(['stock_quantity' => $targetAfter])->saveQuietly();

                $transfer = ReturnInventoryTransfer::query()->create([
                    'order_id' => $lockedOrder->getKey(),
                    'return_receipt_id' => $receipt->getKey(),
                    'return_receipt_item_id' => $item->getKey(),
                    'source_sellable_item_id' => $source->getKey(),
                    'target_sellable_item_id' => $target->getKey(),
                    'quantity' => $quantity,
                    'reason' => $data['reason'],
                    'recorded_by_user_id' => $actorId,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'source_stock_before' => $sourceStock,
                    'source_stock_after' => $sourceAfter,
                    'target_stock_before' => $targetStock,
                    'target_stock_after' => $targetAfter,
                ]);
                $payload = $this->transferPayload($transfer);
                $transfer->forceFill(['response_snapshot' => $payload])->saveQuietly();

                return ['transfer' => $transfer, 'payload' => $payload, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            $existing = ReturnInventoryTransfer::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertFingerprint($existing->request_fingerprint, $fingerprint);

                return ['transfer' => $existing, 'payload' => $existing->response_snapshot ?? $this->transferPayload($existing), 'replayed' => true];
            }

            throw $exception;
        }
    }

    /** @param array{reversal_reason: string} $data
     * @return array{transfer: ReturnInventoryTransfer, reversal: ?ReturnInventoryTransferReversal, payload: array<string, mixed>, replayed: bool}
     */
    public function reverse(
        Order $order,
        int $receiptId,
        int $receiptItemId,
        int $transferId,
        array $data,
        int $actorId,
        string $idempotencyKey,
    ): array {
        $fingerprint = $this->fingerprint('reverse', (int) $order->getKey(), $receiptId, $receiptItemId, [
            'transfer_id' => $transferId,
            'reversal_reason' => $data['reversal_reason'],
        ]);

        try {
            return DB::transaction(function () use ($order, $receiptId, $receiptItemId, $transferId, $data, $actorId, $idempotencyKey, $fingerprint): array {
                $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $existingReplay = ReturnInventoryTransferReversal::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingReplay !== null) {
                    $this->assertReversalFingerprint($existingReplay, $fingerprint);

                    return [
                        'transfer' => $existingReplay->transfer,
                        'reversal' => $existingReplay,
                        'payload' => $existingReplay->response_snapshot ?? $this->reversalPayload($existingReplay),
                        'replayed' => true,
                    ];
                }

                $decision = $lockedOrder->orderReturn()->lockForUpdate()->first();
                [$receipt, $item, $orderItem] = $this->lockReturnItem($lockedOrder, $receiptId, $receiptItemId);
                $sourceId = $this->assertHistoricalSource($item, $orderItem);
                $transfer = ReturnInventoryTransfer::query()
                    ->whereKey($transferId)
                    ->where('order_id', $lockedOrder->getKey())
                    ->where('return_receipt_id', $receipt->getKey())
                    ->where('return_receipt_item_id', $item->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if ((int) $transfer->source_sellable_item_id !== $sourceId) {
                    $this->conflict('return_inventory_transfer_item_mismatch', 'The transfer does not belong to this historical return item.');
                }
                if ($transfer->reversal()->exists()) {
                    $this->conflict('return_inventory_transfer_already_reversed', 'This transfer has already been reversed.');
                }
                $product = Product::withTrashed()->whereKey($orderItem->product_id)->lockForUpdate()->firstOrFail();
                $this->assertReturnIsCurrent($receipt, $decision);
                $ids = collect([$sourceId, (int) $transfer->target_sellable_item_id])->unique()->sort()->values()->all();
                $sellables = SellableItem::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $sellables->get($sourceId);
                $target = $sellables->get((int) $transfer->target_sellable_item_id);
                if (! $source instanceof SellableItem || ! $target instanceof SellableItem) {
                    $this->conflict('return_inventory_target_missing', 'The source or target inventory record no longer exists.');
                }
                if ((int) $source->product_id !== (int) $product->getKey()) {
                    $this->conflict('return_inventory_source_mismatch', 'The historical source does not belong to the original product.');
                }
                if ($source->superseded_at === null
                    || ProductConversion::query()->where('source_sellable_item_id', $sourceId)->where('product_id', $product->getKey())->doesntExist()) {
                    $this->conflict('return_inventory_source_mismatch', 'The historical source no longer has its recorded conversion.');
                }

                $quantity = (int) $transfer->quantity;
                $targetStock = (int) $target->stock_quantity;
                $targetReserved = (int) $target->reserved_quantity;
                $sourceStock = (int) $source->stock_quantity;
                $sourceReserved = (int) $source->reserved_quantity;
                if ($targetStock < $targetReserved || $targetStock - $targetReserved < $quantity) {
                    $this->conflict('return_inventory_target_unavailable', 'The target does not have enough available inventory to reverse this transfer.');
                }
                if ($sourceStock > self::MAX_INTEGER - $quantity) {
                    $this->conflict('return_inventory_overflow', 'The source inventory would exceed the supported quantity range.');
                }

                $targetAfter = $targetStock - $quantity;
                $sourceAfter = $sourceStock + $quantity;
                if ($targetAfter < $targetReserved || $sourceAfter < $sourceReserved) {
                    $this->conflict('return_inventory_reserved_conflict', 'The reversal would make inventory lower than reserved quantity.');
                }
                $target->forceFill(['stock_quantity' => $targetAfter])->saveQuietly();
                $source->forceFill(['stock_quantity' => $sourceAfter])->saveQuietly();

                $reversal = ReturnInventoryTransferReversal::query()->create([
                    'return_inventory_transfer_id' => $transfer->getKey(),
                    'reason' => $data['reversal_reason'],
                    'recorded_by_user_id' => $actorId,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'source_stock_before' => $sourceStock,
                    'source_stock_after' => $sourceAfter,
                    'target_stock_before' => $targetStock,
                    'target_stock_after' => $targetAfter,
                ]);
                $payload = $this->reversalPayload($reversal);
                $reversal->forceFill(['response_snapshot' => $payload])->saveQuietly();

                return ['transfer' => $transfer, 'reversal' => $reversal, 'payload' => $payload, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            $existing = ReturnInventoryTransferReversal::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertReversalFingerprint($existing, $fingerprint);

                return [
                    'transfer' => $existing->transfer,
                    'reversal' => $existing,
                    'payload' => $existing->response_snapshot ?? $this->reversalPayload($existing),
                    'replayed' => true,
                ];
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function eligibilityPayload(Order $order, ReturnReceipt $receipt, ReturnReceiptItem $item): array
    {
        $orderItem = OrderItem::query()->where('order_id', $order->getKey())->whereKey($item->order_item_id)->firstOrFail();
        $sourceId = $this->assertHistoricalSource($item, $orderItem);
        $conversion = ProductConversion::query()->where('source_sellable_item_id', $sourceId)->first();
        $source = $conversion === null ? null : SellableItem::withTrashed()->find($sourceId);
        $baselineKnown = $conversion !== null
            && $conversion->return_restock_baseline !== null
            && $source instanceof SellableItem
            && $source->superseded_at !== null
            && (int) $source->product_id === (int) $orderItem->product_id
            && (int) $conversion->product_id === (int) $orderItem->product_id;
        $baseline = $baselineKnown ? (int) ($conversion->return_restock_baseline[(string) $item->getKey()] ?? 0) : (int) $item->effective_restockable_quantity;
        $eligible = $baselineKnown && $receipt->reversed_at === null
            ? max(0, (int) $item->effective_restockable_quantity - $baseline)
            : 0;
        $sourceAvailable = $source instanceof SellableItem
            ? max(0, (int) $source->stock_quantity - (int) $source->reserved_quantity)
            : 0;
        $transferred = (int) ReturnInventoryTransfer::query()
            ->where('return_receipt_item_id', $item->getKey())
            ->whereDoesntHave('reversal')
            ->sum('quantity');
        $transfers = ReturnInventoryTransfer::query()->with('reversal')->where('return_receipt_item_id', $item->getKey())->orderBy('id')->get();

        return [
            'order_id' => (int) $order->getKey(),
            'return_receipt_id' => (int) $receipt->getKey(),
            'return_receipt_item_id' => (int) $item->getKey(),
            'source_sellable_item_id' => $sourceId,
            'current_restock_quantity' => (int) $item->effective_restockable_quantity,
            'pre_conversion_restock_quantity' => $baseline,
            'eligibility_known' => $baselineKnown,
            'eligible_quantity' => $eligible,
            'transferred_quantity' => $transferred,
            'source_available_quantity' => $sourceAvailable,
            'eligible_remaining_quantity' => min(max(0, $eligible - $transferred), $sourceAvailable),
            'receipt_reversed' => $receipt->reversed_at !== null,
            'transfers' => $transfers->map(fn (ReturnInventoryTransfer $transfer): array => (new AdminReturnInventoryTransferResource($transfer))->resolve(request()))->all(),
        ];
    }

    /** @return array{0: ReturnReceipt, 1: ReturnReceiptItem, 2: OrderItem} */
    private function lockReturnItem(Order $order, int $receiptId, int $receiptItemId): array
    {
        $receipt = ReturnReceipt::query()->where('order_id', $order->getKey())->whereKey($receiptId)->lockForUpdate()->firstOrFail();
        $item = ReturnReceiptItem::query()->where('return_receipt_id', $receipt->getKey())->whereKey($receiptItemId)->lockForUpdate()->firstOrFail();
        $orderItem = OrderItem::query()->where('order_id', $order->getKey())->whereKey($item->order_item_id)->lockForUpdate()->firstOrFail();

        return [$receipt, $item, $orderItem];
    }

    private function assertHistoricalSource(ReturnReceiptItem $item, OrderItem $orderItem): int
    {
        if ((int) $item->order_item_id !== (int) $orderItem->getKey()
            || $item->sellable_item_id === null
            || $orderItem->sellable_item_id === null
            || (int) $item->sellable_item_id !== (int) $orderItem->sellable_item_id
            || (int) $item->original_quantity !== (int) $orderItem->quantity
            || (int) $item->effective_received_quantity > (int) $item->original_quantity
            || (int) $item->effective_restockable_quantity > (int) $item->effective_received_quantity) {
            $this->conflict('return_inventory_source_mismatch', 'The return item does not match its original order item and quantities.');
        }

        return (int) $item->sellable_item_id;
    }

    private function assertSource(?SellableItem $source, Product $product, ProductConversion $conversion): void
    {
        if (! $source instanceof SellableItem
            || (int) $source->product_id !== (int) $product->getKey()
            || (int) $conversion->product_id !== (int) $product->getKey()
            || $source->superseded_at === null) {
            $this->conflict('return_inventory_source_mismatch', 'The source is not the superseded source recorded by this product conversion.');
        }
    }

    private function assertTarget(?SellableItem $target, Product $product): void
    {
        if (! $target instanceof SellableItem
            || $target->trashed()
            || (int) $target->product_id !== (int) $product->getKey()
            || $target->status !== 'active'
            || $target->superseded_at !== null) {
            $this->conflict('return_inventory_target_invalid', 'The target must be an active, current sellable item belonging to the same product.');
        }

        $options = ProductOption::query()->where('product_id', $product->getKey())->orderBy('id')->get();
        $values = $target->optionValues()
            ->withTrashed()
            ->with(['option' => fn (BelongsTo $relation) => $relation->withTrashed()])
            ->get();
        if ($options->isEmpty()) {
            if ($values->isNotEmpty() || (string) $target->combination_key !== '') {
                $this->conflict('return_inventory_target_invalid', 'A simple product target cannot have option value assignments.');
            }

            return;
        }

        $optionIds = $values->pluck('product_option_id')->map(fn ($id): int => (int) $id);
        $valueIds = $values->modelKeys();
        sort($valueIds, SORT_NUMERIC);
        $expectedOptionIds = $options->map(fn (ProductOption $option): int => (int) $option->getKey())->all();
        $valid = $values->count() === $options->count()
            && $optionIds->unique()->count() === $values->count()
            && $values->every(fn (ProductOptionValue $value): bool => ! $value->trashed()
                && $value->option !== null
                && ! $value->option->trashed()
                && (int) $value->option->product_id === (int) $product->getKey())
            && $optionIds->sort()->values()->all() === $expectedOptionIds
            && (string) $target->combination_key === implode(',', $valueIds);
        if (! $valid) {
            $this->conflict('return_inventory_target_invalid', 'The target does not have one valid option value for each active product option.');
        }
    }

    private function assertReturnIsCurrent(ReturnReceipt $receipt, ?OrderReturn $decision): void
    {
        if ($receipt->reversed_at !== null) {
            $this->conflict('return_receipt_reversed', 'A reversed return receipt cannot have inventory transferred.');
        }

        if ($receipt->order_return_id !== null) {
            if ($decision === null
                || (int) $receipt->order_return_id !== (int) $decision->getKey()
                || $decision->status->value !== 'received') {
                $this->conflict('return_receipt_not_current', 'Only the current received return receipt can have inventory transferred.');
            }
        }
    }

    private function activeTransferredQuantity(int $itemId, bool $lock): int
    {
        $query = ReturnInventoryTransfer::query()
            ->where('return_receipt_item_id', $itemId)
            ->whereDoesntHave('reversal')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query->get()->sum('quantity');
    }

    private function transferPayload(ReturnInventoryTransfer $transfer): array
    {
        return [
            'id' => (int) $transfer->getKey(),
            'order_id' => (int) $transfer->order_id,
            'return_receipt_id' => (int) $transfer->return_receipt_id,
            'return_receipt_item_id' => (int) $transfer->return_receipt_item_id,
            'source_sellable_item_id' => (int) $transfer->source_sellable_item_id,
            'target_sellable_item_id' => (int) $transfer->target_sellable_item_id,
            'quantity' => (int) $transfer->quantity,
            'reason' => $transfer->reason,
            'recorded_by_user_id' => $transfer->recorded_by_user_id,
            'idempotency_key' => $transfer->idempotency_key,
            'source_stock_before' => (int) $transfer->source_stock_before,
            'source_stock_after' => (int) $transfer->source_stock_after,
            'target_stock_before' => (int) $transfer->target_stock_before,
            'target_stock_after' => (int) $transfer->target_stock_after,
            'created_at' => $transfer->created_at?->toISOString(),
        ];
    }

    private function reversalPayload(ReturnInventoryTransferReversal $reversal): array
    {
        return [
            'id' => (int) $reversal->getKey(),
            'transfer_id' => (int) $reversal->return_inventory_transfer_id,
            'reason' => $reversal->reason,
            'recorded_by_user_id' => $reversal->recorded_by_user_id,
            'idempotency_key' => $reversal->idempotency_key,
            'source_stock_before' => (int) $reversal->source_stock_before,
            'source_stock_after' => (int) $reversal->source_stock_after,
            'target_stock_before' => (int) $reversal->target_stock_before,
            'target_stock_after' => (int) $reversal->target_stock_after,
            'created_at' => $reversal->created_at?->toISOString(),
        ];
    }

    private function assertFingerprint(string $stored, string $current): void
    {
        if (! hash_equals($stored, $current)) {
            $this->conflict('idempotency_key_conflict', 'This idempotency key was already used for a different transfer request.');
        }
    }

    private function assertReversalFingerprint(ReturnInventoryTransferReversal $reversal, string $fingerprint): void
    {
        if (! hash_equals($reversal->request_fingerprint, $fingerprint)) {
            $this->conflict('idempotency_key_conflict', 'This idempotency key was already used for a different transfer reversal.');
        }
    }

    private function fingerprint(string $operation, int $orderId, int $receiptId, int $itemId, array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode([$operation, $orderId, $receiptId, $itemId, $data], JSON_THROW_ON_ERROR));
    }

    private function conflict(string $code, string $message): never
    {
        throw new PhysicalReturnConflictException($code, $message);
    }
}
