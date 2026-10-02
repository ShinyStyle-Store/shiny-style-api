<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Exceptions\PhysicalReturnConflictException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnCorrection;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\SellableItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhysicalReturnService
{
    private const MAX_INTEGER = 2147483647;

    /** @return array{receipt: ReturnReceipt, replayed: bool} */
    public function create(Order $order, array $data, int $actorId, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint('create', $order->getKey(), $data);

        return DB::transaction(function () use ($order, $data, $actorId, $idempotencyKey, $fingerprint): array {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $existing = ReturnReceipt::query()
                ->where('order_id', $lockedOrder->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertFingerprint($existing->request_fingerprint, $fingerprint);

                return [
                    'receipt' => $this->originalReceipt($existing),
                    'replayed' => true,
                ];
            }

            $this->assertReceiptOrder($lockedOrder);
            $receivedAt = $this->parseReceiptDate($data['received_at'] ?? null);
            $requestReceivedAt = $this->parseRequestDate($data['request_received_at'] ?? null, $receivedAt);
            $this->assertBeforeShipment($lockedOrder, $receivedAt);

            $inputItems = collect($data['items']);
            $orderItems = OrderItem::query()
                ->where('order_id', $lockedOrder->getKey())
                ->whereIn('id', $inputItems->pluck('order_item_id')->map(fn ($id): int => (int) $id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($orderItems->count() !== $inputItems->count()) {
                throw ValidationException::withMessages(['items' => 'Every order item must belong to the target order.']);
            }

            $orderItemIds = $orderItems->keys()->map(fn ($id): int => (int) $id)->all();
            $existingItems = $this->lockExistingItems($lockedOrder, $orderItemIds);
            $returnedByOrderItem = $this->returnedQuantities($existingItems);
            $targets = $this->lockTargets($orderItems, $inputItems, false);
            $stockDeltas = [];
            $resolvedItems = [];

            foreach ($inputItems as $input) {
                $orderItem = $orderItems->get((int) $input['order_item_id']);
                $received = (int) $input['received_quantity'];
                $restockable = (int) $input['restockable_quantity'];
                $this->assertQuantityRange($restockable, $received, 'items');
                $this->assertCumulativeLimit($returnedByOrderItem, $orderItem, $received);

                $reason = $this->resolveReason($input['reason'] ?? null, $data['default_reason'] ?? null);
                $note = array_key_exists('note', $input) ? $input['note'] : ($data['note'] ?? null);
                $this->assertReasonNote($reason, $note);
                $sellableId = $orderItem->sellable_item_id === null ? null : (int) $orderItem->sellable_item_id;

                if ($restockable > 0 && $sellableId === null) {
                    throw new PhysicalReturnConflictException('return_inventory_target_missing', 'The inventory target for a non-zero restock is missing.');
                }
                if ($sellableId !== null && $restockable > 0 && ! isset($targets[$sellableId])) {
                    throw new PhysicalReturnConflictException('return_inventory_target_missing', 'The historical inventory target no longer exists.');
                }

                if ($restockable > 0) {
                    $stockDeltas[$sellableId] = $this->addIntegers($stockDeltas[$sellableId] ?? 0, $restockable);
                }
                $resolvedItems[] = compact('orderItem', 'received', 'restockable', 'reason', 'note', 'sellableId');
            }

            $this->applyStockDeltas($targets, $stockDeltas);
            $receipt = ReturnReceipt::query()->create([
                'order_id' => $lockedOrder->getKey(),
                'request_received_at' => $requestReceivedAt,
                'received_at' => $receivedAt,
                'default_reason' => $data['default_reason'] ?? null,
                'note' => $data['note'] ?? null,
                'revision' => 0,
                'recorded_by_user_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
            ]);

            foreach ($resolvedItems as $item) {
                ReturnReceiptItem::query()->create([
                    'return_receipt_id' => $receipt->getKey(),
                    'order_item_id' => $item['orderItem']->getKey(),
                    'sellable_item_id' => $item['sellableId'],
                    'original_quantity' => (int) $item['orderItem']->quantity,
                    'initial_received_quantity' => $item['received'],
                    'initial_restockable_quantity' => $item['restockable'],
                    'initial_reason' => $item['reason']->value,
                    'effective_received_quantity' => $item['received'],
                    'effective_restockable_quantity' => $item['restockable'],
                    'effective_reason' => $item['reason']->value,
                    'initial_note' => $item['note'],
                    'effective_note' => $item['note'],
                ]);
            }

            return ['receipt' => $this->loadReceipt($receipt), 'replayed' => false];
        });
    }

    /** @return array{correction: ReturnCorrection, receipt: ReturnReceipt, replayed: bool} */
    public function correct(ReturnReceipt $receipt, array $data, int $actorId, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint('correction', $receipt->getKey(), $data);

        return DB::transaction(function () use ($receipt, $data, $actorId, $idempotencyKey, $fingerprint): array {
            $order = Order::query()->whereKey($receipt->order_id)->lockForUpdate()->firstOrFail();
            $lockedReceipt = ReturnReceipt::query()->whereKey($receipt->getKey())->where('order_id', $order->getKey())->lockForUpdate()->firstOrFail();
            $existing = ReturnCorrection::query()
                ->where('return_receipt_id', $lockedReceipt->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->with('items')
                ->first();

            if ($existing !== null) {
                $this->assertFingerprint($existing->request_fingerprint, $fingerprint);

                return [
                    'correction' => $existing,
                    'receipt' => $this->loadReceipt($lockedReceipt),
                    'replayed' => true,
                ];
            }

            if ((int) $data['expected_revision'] !== (int) $lockedReceipt->revision) {
                throw new PhysicalReturnConflictException('return_revision_conflict', 'The return receipt has changed. Refresh it and retry the correction.');
            }

            $receiptItems = ReturnReceiptItem::query()
                ->where('return_receipt_id', $lockedReceipt->getKey())
                ->whereIn('id', collect($data['items'])->pluck('return_receipt_item_id')->map(fn ($id): int => (int) $id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($receiptItems->count() !== count($data['items'])) {
                throw ValidationException::withMessages(['items' => 'Every correction item must belong to the target receipt.']);
            }

            $allItems = $this->lockExistingItems($order, $receiptItems->pluck('order_item_id')->map(fn ($id): int => (int) $id)->all(), true);
            $orderItems = OrderItem::query()->where('order_id', $order->getKey())->whereIn('id', $allItems->pluck('order_item_id'))->lockForUpdate()->get()->keyBy('id');
            if ($orderItems->count() !== $allItems->pluck('order_item_id')->unique()->count()) {
                throw new PhysicalReturnConflictException('return_order_item_mismatch', 'A return item no longer belongs to the target order.');
            }
            $proposed = [];
            $targets = $this->lockCorrectionTargets($receiptItems);
            $stockDeltas = [];
            $history = [];

            foreach ($data['items'] as $input) {
                $item = $receiptItems->get((int) $input['return_receipt_item_id']);
                $received = (int) $input['received_quantity'];
                $restockable = (int) $input['restockable_quantity'];
                $this->assertQuantityRange($restockable, $received, 'items');
                $reason = array_key_exists('reason', $input) && $input['reason'] !== null
                    ? ReturnReason::from($input['reason'])
                    : $item->effective_reason;
                $note = array_key_exists('note', $input) ? $input['note'] : $item->effective_note;
                $this->assertReasonNote($reason, $note);
                if ($received > (int) $item->original_quantity) {
                    throw ValidationException::withMessages(['items' => 'The effective received quantity cannot exceed the original order quantity.']);
                }

                $delta = $restockable - (int) $item->effective_restockable_quantity;
                if ($delta !== 0 && ($item->sellable_item_id === null || ! isset($targets[(int) $item->sellable_item_id]))) {
                    throw new PhysicalReturnConflictException('return_inventory_target_missing', 'The historical inventory target is required for this stock correction but no longer exists.');
                }
                if ($delta !== 0) {
                    $id = (int) $item->sellable_item_id;
                    $stockDeltas[$id] = $this->addIntegers($stockDeltas[$id] ?? 0, $delta);
                }
                $proposed[$item->getKey()] = ['received' => $received, 'restockable' => $restockable, 'reason' => $reason, 'note' => $note];
                $history[] = compact('item', 'received', 'restockable', 'reason', 'note');
                $history[array_key_last($history)]['previous_received'] = (int) $item->effective_received_quantity;
                $history[array_key_last($history)]['previous_restockable'] = (int) $item->effective_restockable_quantity;
                $history[array_key_last($history)]['previous_reason'] = $item->effective_reason->value;
            }

            $this->assertNoOp($history);
            $this->assertProposedTotals($order, $receiptItems, $proposed);
            $this->applyStockDeltas($targets, $stockDeltas);

            $newRevision = (int) $lockedReceipt->revision + 1;
            if ($newRevision > self::MAX_INTEGER) {
                throw new PhysicalReturnConflictException('return_revision_overflow', 'The return receipt revision cannot be increased safely.');
            }
            $correction = ReturnCorrection::query()->create([
                'return_receipt_id' => $lockedReceipt->getKey(),
                'expected_revision' => $lockedReceipt->revision,
                'applied_revision' => $newRevision,
                'explanation' => $data['explanation'],
                'recorded_by_user_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
            ]);

            foreach ($history as $entry) {
                $item = $entry['item'];
                $item->forceFill([
                    'effective_received_quantity' => $entry['received'],
                    'effective_restockable_quantity' => $entry['restockable'],
                    'effective_reason' => $entry['reason']->value,
                    'effective_note' => $entry['note'],
                ])->saveQuietly();
                $correction->items()->create([
                    'return_receipt_item_id' => $item->getKey(),
                    'previous_received_quantity' => $entry['previous_received'],
                    'previous_restockable_quantity' => $entry['previous_restockable'],
                    'previous_reason' => $entry['previous_reason'],
                    'new_received_quantity' => $entry['received'],
                    'new_restockable_quantity' => $entry['restockable'],
                    'new_reason' => $entry['reason']->value,
                    'new_note' => $entry['note'],
                ]);
            }

            $lockedReceipt->forceFill(['revision' => $newRevision])->saveQuietly();

            return [
                'correction' => $correction->load('items'),
                'receipt' => $this->loadReceipt($lockedReceipt),
                'replayed' => false,
            ];
        });
    }

    public function summary(Order $order): string
    {
        $items = OrderItem::query()->where('order_id', $order->getKey())->get(['id', 'quantity']);
        $returned = ReturnReceiptItem::query()
            ->select('order_item_id')
            ->selectRaw('SUM(effective_received_quantity) AS returned_quantity')
            ->whereHas('receipt', fn ($query) => $query->where('order_id', $order->getKey()))
            ->groupBy('order_item_id')
            ->pluck('returned_quantity', 'order_item_id');

        $hasReturn = false;
        $isFull = true;
        foreach ($items as $item) {
            $quantity = (int) ($returned[$item->getKey()] ?? 0);
            $hasReturn = $hasReturn || $quantity > 0;
            if ($quantity < (int) $item->quantity) {
                $isFull = false;
            }
        }

        return ! $hasReturn ? 'none' : ($isFull ? 'full' : 'partial');
    }

    public function loadReceipt(ReturnReceipt $receipt, bool $withCorrections = true): ReturnReceipt
    {
        return $receipt->load([
            'items.orderItem',
            ...($withCorrections ? ['corrections.items'] : []),
        ]);
    }

    private function assertReceiptOrder(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
            throw new PhysicalReturnConflictException('return_order_status_not_allowed', 'Returns can only be recorded for shipped or delivered orders.');
        }
    }

    private function parseReceiptDate(?string $value): Carbon
    {
        $date = $value === null ? now() : Carbon::parse($value);
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['received_at' => 'The physical receipt date cannot be in the future.']);
        }
        return $date;
    }

    private function parseRequestDate(?string $value, Carbon $receivedAt): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        $date = Carbon::parse($value);
        if ($date->greaterThan($receivedAt)) {
            throw ValidationException::withMessages(['request_received_at' => 'The request date cannot be later than the physical receipt date.']);
        }
        return $date;
    }

    private function assertBeforeShipment(Order $order, Carbon $receivedAt): void
    {
        if ($order->shipped_at !== null && $receivedAt->lessThan($order->shipped_at)) {
            throw ValidationException::withMessages(['received_at' => 'The physical receipt cannot be before shipment.']);
        }
    }

    private function assertQuantityRange(int $restockable, int $received, string $field): void
    {
        if ($restockable < 0 || $restockable > $received) {
            throw ValidationException::withMessages([$field => 'The restockable quantity must be between zero and the received quantity.']);
        }
    }

    private function assertCumulativeLimit(array $returned, OrderItem $item, int $newQuantity): void
    {
        if (($returned[$item->getKey()] ?? 0) + $newQuantity > (int) $item->quantity) {
            throw ValidationException::withMessages(['items' => 'The cumulative returned quantity cannot exceed the original order quantity.']);
        }
    }

    private function resolveReason(?string $itemReason, ?string $defaultReason): ReturnReason
    {
        $value = $itemReason ?? $defaultReason;
        if ($value === null) {
            throw ValidationException::withMessages(['reason' => 'A return reason is required.']);
        }
        return ReturnReason::from($value);
    }

    private function assertReasonNote(ReturnReason $reason, ?string $note): void
    {
        if ($reason === ReturnReason::Other && (! is_string($note) || trim($note) === '')) {
            throw ValidationException::withMessages(['note' => 'A meaningful note is required for the other reason.']);
        }
    }

    /** @return EloquentCollection<int, ReturnReceiptItem> */
    private function lockExistingItems(Order $order, array $orderItemIds, bool $forCorrection = false): EloquentCollection
    {
        return ReturnReceiptItem::query()
            ->whereIn('order_item_id', $orderItemIds)
            ->whereHas('receipt', fn ($query) => $query->where('order_id', $order->getKey()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function returnedQuantities(EloquentCollection $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item->order_item_id] = $this->addIntegers(
                $result[$item->order_item_id] ?? 0,
                (int) $item->effective_received_quantity,
            );
        }
        return $result;
    }

    /** @return array<int, SellableItem> */
    private function lockTargets(EloquentCollection $orderItems, SupportCollection $inputItems, bool $includeZero): array
    {
        $ids = $orderItems->pluck('sellable_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        return $this->lockTargetIds($ids);
    }

    /** @return array<int, SellableItem> */
    private function lockCorrectionTargets(EloquentCollection $items): array
    {
        $ids = $items->pluck('sellable_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        return $this->lockTargetIds($ids);
    }

    /** @param list<int> $ids @return array<int, SellableItem> */
    private function lockTargetIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return SellableItem::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
    }

    /** @param array<int, SellableItem> $targets @param array<int, int> $deltas */
    private function applyStockDeltas(array $targets, array $deltas): void
    {
        ksort($deltas);
        foreach ($deltas as $id => $delta) {
            $item = $targets[$id] ?? null;
            if ($item === null) {
                throw new PhysicalReturnConflictException('return_inventory_target_missing', 'The historical inventory target no longer exists.');
            }
            $stock = (int) $item->stock_quantity;
            $reserved = (int) $item->reserved_quantity;
            if ($stock < 0 || $reserved < 0 || $reserved > self::MAX_INTEGER) {
                throw new PhysicalReturnConflictException('return_inventory_invariant_violation', 'The inventory state is invalid.');
            }
            if (($delta > 0 && $stock > self::MAX_INTEGER - $delta) || ($delta < 0 && $stock < -$delta)) {
                throw new PhysicalReturnConflictException('return_inventory_overflow', 'The inventory update is outside the supported integer range.');
            }
            $newStock = $stock + $delta;
            if ($newStock < $reserved) {
                throw new PhysicalReturnConflictException('return_inventory_reserved_conflict', 'The inventory update would reduce stock below reserved quantity.');
            }
            $item->forceFill(['stock_quantity' => $newStock])->saveQuietly();
        }
    }

    private function addIntegers(int $left, int $right): int
    {
        if (($right > 0 && $left > self::MAX_INTEGER - $right)
            || ($right < 0 && $left < -self::MAX_INTEGER - $right)) {
            throw new PhysicalReturnConflictException('return_inventory_overflow', 'The aggregate inventory update is outside the supported integer range.');
        }

        return $left + $right;
    }

    private function assertProposedTotals(Order $order, EloquentCollection $selected, array $proposed): void
    {
        $all = $this->lockExistingItems($order, $selected->pluck('order_item_id')->map(fn ($id): int => (int) $id)->all(), true);
        $totals = $this->returnedQuantities($all);
        foreach ($selected as $item) {
            $totals[$item->order_item_id] = ($totals[$item->order_item_id] ?? 0) - (int) $item->effective_received_quantity;
            $totals[$item->order_item_id] += (int) $proposed[$item->getKey()]['received'];
        }
        $orders = OrderItem::query()->where('order_id', $order->getKey())->whereIn('id', array_keys($totals))->get()->keyBy('id');
        foreach ($totals as $orderItemId => $total) {
            if ($total > (int) $orders[$orderItemId]->quantity) {
                throw new PhysicalReturnConflictException('return_quantity_conflict', 'The cumulative returned quantity exceeds the original order quantity.');
            }
        }
    }

    private function assertNoOp(array $history): void
    {
        foreach ($history as $entry) {
            $item = $entry['item'];
            if ((int) $item->effective_received_quantity !== $entry['received']
                || (int) $item->effective_restockable_quantity !== $entry['restockable']
                || $item->effective_reason !== $entry['reason']
                || $item->effective_note !== $entry['note']) {
                return;
            }
        }
        throw ValidationException::withMessages(['items' => 'The correction does not change the current receipt state.']);
    }

    private function originalReceipt(ReturnReceipt $receipt): ReturnReceipt
    {
        return $receipt->load(['items.orderItem'])->setAttribute('original_operation', true);
    }

    private function assertFingerprint(string $stored, string $current): void
    {
        if (! hash_equals($stored, $current)) {
            throw new PhysicalReturnConflictException('idempotency_key_conflict', 'The idempotency key was already used with different content.');
        }
    }

    private function fingerprint(string $operation, int $scopeId, array $data): string
    {
        $canonical = $this->canonicalize(['operation' => $operation, 'scope_id' => $scopeId, 'data' => $data]);
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            usort($value, fn ($a, $b) => is_array($a) && is_array($b)
                ? (($a['order_item_id'] ?? $a['return_receipt_item_id'] ?? 0) <=> ($b['order_item_id'] ?? $b['return_receipt_item_id'] ?? 0))
                : 0);
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
