<?php

namespace App\Services;

use App\Enums\OrderReturnKind;
use App\Enums\OrderReturnStatus;
use App\Enums\OrderStatus;
use App\Exceptions\OrderReturnConflictException;
use App\Http\Resources\AdminOrderReturnResource;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderReturnDecisionService
{
    /** @return array{decision: OrderReturn, replayed: bool} */
    public function create(Order $order, OrderReturnKind $kind, array $data, int $actorId, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint($order, $kind, $data);

        try {
            return DB::transaction(function () use ($order, $kind, $data, $actorId, $idempotencyKey, $fingerprint): array {
                $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

                $existing = OrderReturn::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ($existing->order_id !== $lockedOrder->getKey()
                        || $existing->request_fingerprint !== $fingerprint) {
                        throw new OrderReturnConflictException(
                            'idempotency_key_conflict',
                            'The idempotency key was already used for a different return decision.',
                        );
                    }

                    return [
                        'decision' => $existing,
                        'payload' => $existing->decision_response_snapshot
                            ?? $this->legacyDecisionPayload($existing),
                        'replayed' => true,
                    ];
                }

                if ($lockedOrder->returnReceipts()->exists()) {
                    throw new OrderReturnConflictException(
                        'return_decision_legacy_conflict',
                        'Orders with legacy return receipts cannot receive a new return decision.',
                    );
                }

                if ($lockedOrder->orderReturn()->exists()) {
                    throw new OrderReturnConflictException(
                        'return_decision_already_exists',
                        'A return decision already exists for this order.',
                    );
                }

                $this->assertSourceStatus($lockedOrder, $kind);

                $recordedAt = now();
                $decision = OrderReturn::query()->create([
                    'order_id' => $lockedOrder->getKey(),
                    'kind' => $kind,
                    'status' => OrderReturnStatus::WaitingForReturn,
                    'reason' => $data['reason'],
                    'note' => $data['note'] ?? null,
                    'recorded_by_user_id' => $actorId,
                    'recorded_at' => $recordedAt,
                    'version' => 0,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);

                if ($kind === OrderReturnKind::DeliveryRefusal) {
                    $lockedOrder->status = OrderStatus::DeliveryRefused;
                    $lockedOrder->saveQuietly();
                }

                $payload = (new AdminOrderReturnResource($decision))->resolve(request());
                $decision->timestamps = false;
                $decision->forceFill(['decision_response_snapshot' => $payload])->saveQuietly();

                return ['decision' => $decision, 'payload' => $payload, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = OrderReturn::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            if ($existing->order_id !== $order->getKey()
                || $existing->request_fingerprint !== $fingerprint) {
                throw new OrderReturnConflictException(
                    'idempotency_key_conflict',
                    'The idempotency key was already used for a different return decision.',
                );
            }

            return [
                'decision' => $existing,
                'payload' => $existing->decision_response_snapshot
                    ?? $this->legacyDecisionPayload($existing),
                'replayed' => true,
            ];
        }
    }

    private function assertSourceStatus(Order $order, OrderReturnKind $kind): void
    {
        $expected = $kind === OrderReturnKind::DeliveryRefusal
            ? OrderStatus::Shipped
            : OrderStatus::Delivered;

        if ($order->status !== $expected) {
            throw new OrderReturnConflictException(
                'return_decision_order_status_not_allowed',
                'The order status does not allow this return decision.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function legacyDecisionPayload(OrderReturn $decision): array
    {
        $decision->setAttribute('status', OrderReturnStatus::WaitingForReturn)
            ->setAttribute('version', 0);

        return (new AdminOrderReturnResource($decision))->resolve(request());
    }

    private function fingerprint(Order $order, OrderReturnKind $kind, array $data): string
    {
        return hash('sha256', json_encode([
            'operation' => 'order_return_decision',
            'kind' => $kind->value,
            'order_id' => $order->getKey(),
            'reason' => $data['reason'],
            'note' => $data['note'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['19', '23000', '23505'], true);
    }
}
