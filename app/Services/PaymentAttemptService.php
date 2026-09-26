<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\PaymentAttemptException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Support\ExactMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;

class PaymentAttemptService
{
    public function create(Order $order, PaymentMethod $method, string $idempotencyKey): PaymentAttempt
    {
        if (! $method->isOnline()) {
            throw new PaymentAttemptException(
                'unsupported_payment_method',
                'The selected payment method does not create a payment attempt.',
            );
        }

        try {
            return DB::transaction(function () use ($order, $method, $idempotencyKey): PaymentAttempt {
                $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
                if ($lockedOrder === null) {
                    throw new PaymentAttemptException('order_not_payable', 'The order is not payable.');
                }

                $fingerprint = $this->fingerprint($lockedOrder, $method);

                $existing = PaymentAttempt::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ($existing->order_id !== $lockedOrder->getKey()
                        || $existing->request_fingerprint !== $fingerprint) {
                        throw new IdempotencyConflictException(
                            'The idempotency key was already used for a different payment request.',
                        );
                    }

                    return $existing;
                }

                $this->assertPayable($lockedOrder);

                $activeAttempt = PaymentAttempt::query()
                    ->where('order_id', $lockedOrder->getKey())
                    ->whereIn('status', array_map(
                        static fn (PaymentAttemptStatus $status): string => $status->value,
                        [PaymentAttemptStatus::Created, PaymentAttemptStatus::Pending],
                    ))
                    ->lockForUpdate()
                    ->first();

                if ($activeAttempt !== null) {
                    throw new PaymentAttemptException(
                        'active_payment_attempt_exists',
                        'An active payment attempt already exists for this order.',
                    );
                }

                $nonRetryableAttempt = PaymentAttempt::query()
                    ->where('order_id', $lockedOrder->getKey())
                    ->whereIn('status', [
                        PaymentAttemptStatus::Paid->value,
                        PaymentAttemptStatus::RequiresReview->value,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($nonRetryableAttempt !== null) {
                    throw new PaymentAttemptException(
                        'payment_attempt_not_retryable',
                        'A completed payment attempt prevents automatic retry.',
                    );
                }

                $expiresAt = $lockedOrder->payment_expires_at;
                if ($expiresAt === null) {
                    throw new PaymentAttemptException(
                        'order_not_payable',
                        'The order does not have an active payment window.',
                    );
                }

                $amountMinor = $this->amountMinor($lockedOrder);
                $currency = strtoupper(trim((string) $lockedOrder->currency));

                if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                    throw new PaymentAttemptException('order_not_payable', 'The order currency is invalid.');
                }

                return PaymentAttempt::create([
                    'order_id' => $lockedOrder->getKey(),
                    'provider' => PaymentProvider::Paymob,
                    'method' => $method,
                    'status' => PaymentAttemptStatus::Created,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'merchant_reference' => 'SS-PAY-'.Str::ulid(),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt,
                ]);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = PaymentAttempt::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            if ($existing->order_id !== $order->getKey()
                || $existing->request_fingerprint !== $this->fingerprint($order, $method)) {
                throw new IdempotencyConflictException(
                    'The idempotency key was already used for a different payment request.',
                );
            }

            return $existing;
        }
    }

    private function assertPayable(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            throw new PaymentAttemptException('order_already_paid', 'The order has already been paid.');
        }

        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Delivered], true)) {
            throw new PaymentAttemptException('order_not_payable', 'The order is not payable.');
        }

        if ($order->payment_expires_at !== null && $order->payment_expires_at->isPast()) {
            throw new PaymentAttemptException('payment_window_expired', 'The payment window has expired.');
        }

        if ($order->payment_expires_at === null) {
            throw new PaymentAttemptException(
                'order_not_payable',
                'The order does not have an active payment window.',
            );
        }
    }

    private function amountMinor(Order $order): int
    {
        try {
            return ExactMoney::toMinorUnitInteger((string) $order->total);
        } catch (InvalidArgumentException|OverflowException) {
            throw new PaymentAttemptException(
                'invalid_payment_amount',
                'The order payment amount is invalid.',
            );
        }
    }

    private function fingerprint(Order $order, PaymentMethod $method): string
    {
        return hash('sha256', json_encode([
            'provider' => PaymentProvider::Paymob->value,
            'order_id' => $order->getKey(),
            'method' => $method->value,
        ], JSON_THROW_ON_ERROR));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['19', '23000', '23505'], true);
    }
}
