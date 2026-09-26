<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

final class PaymobTransactionWebhookService
{
    public function __construct(private readonly PaymobIntegrationResolver $integrations)
    {
    }

    public function process(PaymobTransaction $transaction): PaymobWebhookResult
    {
        $cardIntegrationId = $this->integrations->resolve(PaymentMethod::Card);

        return DB::transaction(function () use ($transaction, $cardIntegrationId): PaymobWebhookResult {
            $candidate = PaymentAttempt::query()
                ->where('provider', PaymentProvider::Paymob->value)
                ->where('provider_order_id', (string) $transaction->providerOrderId)
                ->first();

            if ($candidate === null) {
                return new PaymobWebhookResult(202);
            }

            $order = Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first();
            if ($order === null) {
                return new PaymobWebhookResult(202);
            }

            $attempt = PaymentAttempt::query()
                ->whereKey($candidate->getKey())
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                return new PaymobWebhookResult(202);
            }

            $transactionOwner = PaymentAttempt::query()
                ->where('provider_transaction_id', (string) $transaction->transactionId)
                ->first();

            if ($transactionOwner !== null && $transactionOwner->getKey() !== $attempt->getKey()) {
                $this->review($attempt, 'duplicate_transaction_conflict', $transaction->transactionId);
                return new PaymobWebhookResult();
            }

            if ($transactionOwner?->getKey() === $attempt->getKey()) {
                return new PaymobWebhookResult();
            }

            if (! $this->identityMatches($attempt, $transaction, $cardIntegrationId)) {
                $this->review($attempt, 'callback_identity_mismatch', $transaction->transactionId);
                return new PaymobWebhookResult();
            }

            if ($transaction->refunded || $transaction->voided) {
                $this->review($attempt, 'provider_refund_or_void', $transaction->transactionId);
                return new PaymobWebhookResult();
            }

            if ($transaction->pending) {
                if (in_array($attempt->status, [
                    PaymentAttemptStatus::Created,
                    PaymentAttemptStatus::Pending,
                    PaymentAttemptStatus::Submitting,
                ], true)) {
                    $attempt->forceFill([
                        'status' => PaymentAttemptStatus::Pending,
                        'provider_transaction_id' => (string) $transaction->transactionId,
                    ])->save();
                }
                return new PaymobWebhookResult();
            }

            if ($transaction->success && ! $transaction->errorOccurred) {
                if ($order->payment_status === PaymentStatus::Paid || $attempt->status === PaymentAttemptStatus::Paid) {
                    if ($attempt->status !== PaymentAttemptStatus::Paid) {
                        $this->review($attempt, 'already_paid_order_conflict', $transaction->transactionId);
                    }
                    return new PaymobWebhookResult();
                }

                if ($this->orderIsLateOrClosed($order)
                    || in_array($attempt->status, [PaymentAttemptStatus::Failed, PaymentAttemptStatus::Expired], true)) {
                    $this->review($attempt, 'late_payment_callback', $transaction->transactionId);
                    return new PaymobWebhookResult();
                }

                $attempt->forceFill([
                    'status' => PaymentAttemptStatus::Paid,
                    'provider_transaction_id' => (string) $transaction->transactionId,
                    'paid_at' => $attempt->paid_at ?? now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
                $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

                return new PaymobWebhookResult();
            }

            if (in_array($attempt->status, [PaymentAttemptStatus::Paid, PaymentAttemptStatus::RequiresReview], true)) {
                return new PaymobWebhookResult();
            }

            if ($attempt->status !== PaymentAttemptStatus::Expired) {
                $attempt->forceFill([
                    'status' => PaymentAttemptStatus::Failed,
                    'provider_transaction_id' => (string) $transaction->transactionId,
                    'failure_code' => 'provider_transaction_failed',
                    'failure_message' => null,
                ])->save();
                if ($order->payment_status !== PaymentStatus::Paid) {
                    $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
                }
            }

            return new PaymobWebhookResult();
        });
    }

    private function identityMatches(PaymentAttempt $attempt, PaymobTransaction $transaction, int $cardIntegrationId): bool
    {
        return $attempt->provider === PaymentProvider::Paymob
            && $attempt->method === PaymentMethod::Card
            && $attempt->provider_order_id === (string) $transaction->providerOrderId
            && $attempt->integration_id === $transaction->integrationId
            && $attempt->integration_id === $cardIntegrationId
            && $attempt->amount_minor === $transaction->amountMinor
            && strtoupper($attempt->currency) === $transaction->currency;
    }

    private function orderIsLateOrClosed(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Delivered], true)
            || $order->payment_expires_at === null
            || $order->payment_expires_at->isPast();
    }

    private function review(PaymentAttempt $attempt, string $code, int $transactionId): void
    {
        if ($attempt->status === PaymentAttemptStatus::Paid) {
            return;
        }

        $attributes = [
            'status' => PaymentAttemptStatus::RequiresReview,
            'failure_code' => $code,
            'failure_message' => null,
        ];
        if ($attempt->provider_transaction_id === null) {
            $attributes['provider_transaction_id'] = (string) $transactionId;
        }
        $attempt->forceFill($attributes)->save();
    }
}
