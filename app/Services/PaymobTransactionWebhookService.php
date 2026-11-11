<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Support\ExactMoney;
use InvalidArgumentException;
use OverflowException;
use Illuminate\Support\Facades\DB;

final class PaymobTransactionWebhookService
{
    public function __construct(private readonly PaymobIntegrationResolver $integrations) {}

    public function process(PaymobTransaction $transaction): PaymobWebhookResult
    {
        return DB::transaction(function () use ($transaction): PaymobWebhookResult {
            $candidates = PaymentAttempt::query()
                ->where('provider', PaymentProvider::Paymob->value)
                ->where('provider_order_id', (string) $transaction->providerOrderId)
                ->get();

            if ($candidates->isEmpty()) {
                return new PaymobWebhookResult(202);
            }

            if ($candidates->count() > 1) {
                $candidateIds = $candidates->modelKeys();
                $orderIds = $candidates->pluck('order_id')->unique()->sort()->values();
                Order::query()->whereKey($orderIds)->orderBy('id')->lockForUpdate()->get();

                $lockedCandidates = PaymentAttempt::query()
                    ->whereKey($candidateIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($lockedCandidates as $candidate) {
                    $this->review($candidate, 'callback_order_id_ambiguous', $transaction->transactionId);
                }

                return new PaymobWebhookResult;
            }

            $candidate = $candidates->first();

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

                return new PaymobWebhookResult;
            }

            if (! $this->identityMatches($attempt, $order, $transaction)) {
                $this->review($attempt, 'callback_identity_mismatch', $transaction->transactionId);

                return new PaymobWebhookResult;
            }

            if ($transaction->refunded || $transaction->voided) {
                $this->review($attempt, 'provider_refund_or_void', $transaction->transactionId);

                return new PaymobWebhookResult;
            }

            if ($attempt->method === PaymentMethod::Wallet && ! $this->walletOutcomeIsConsistent($transaction)) {
                $this->review($attempt, 'callback_status_flags_inconsistent', $transaction->transactionId);

                return new PaymobWebhookResult;
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
                    $latestAttemptId = PaymentAttempt::query()
                        ->where('order_id', $order->getKey())
                        ->max('id');
                    if ((int) $latestAttemptId === $attempt->getKey()) {
                        $order->forceFill(['payment_status' => PaymentStatus::Pending])->save();
                    }
                }

                return new PaymobWebhookResult;
            }

            if ($transaction->success && ! $transaction->errorOccurred) {
                if ($order->payment_status === PaymentStatus::Paid || $attempt->status === PaymentAttemptStatus::Paid) {
                    if ($attempt->status !== PaymentAttemptStatus::Paid) {
                        $this->review($attempt, 'already_paid_order_conflict', $transaction->transactionId);
                    }

                    return new PaymobWebhookResult;
                }

                if ($this->orderIsLateOrClosed($order)
                    || $attempt->status === PaymentAttemptStatus::Expired) {
                    $this->review($attempt, 'late_payment_callback', $transaction->transactionId);

                    return new PaymobWebhookResult;
                }

                if ($attempt->method === PaymentMethod::Wallet && $this->hasNewerAttempt($attempt)) {
                    $this->review($attempt, 'late_wallet_success_with_newer_attempt', $transaction->transactionId);

                    return new PaymobWebhookResult;
                }

                $attempt->forceFill([
                    'status' => PaymentAttemptStatus::Paid,
                    'provider_transaction_id' => (string) $transaction->transactionId,
                    'paid_at' => $attempt->paid_at ?? now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
                $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

                return new PaymobWebhookResult;
            }

            if ($attempt->status === PaymentAttemptStatus::Paid
                || ($attempt->status === PaymentAttemptStatus::RequiresReview
                    && $attempt->method !== PaymentMethod::Wallet)) {
                return new PaymobWebhookResult;
            }

            if ($attempt->status !== PaymentAttemptStatus::Expired) {
                $attempt->forceFill([
                    'status' => PaymentAttemptStatus::Failed,
                    'provider_transaction_id' => (string) $transaction->transactionId,
                    'failure_code' => 'provider_transaction_failed',
                    'failure_message' => null,
                ])->save();
                $latestAttemptId = PaymentAttempt::query()
                    ->where('order_id', $order->getKey())
                    ->max('id');
                if ((int) $latestAttemptId === $attempt->getKey() && $order->payment_status !== PaymentStatus::Paid) {
                    $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
                }
            }

            return new PaymobWebhookResult;
        });
    }

    private function identityMatches(PaymentAttempt $attempt, Order $order, PaymobTransaction $transaction): bool
    {
        if ($attempt->provider !== PaymentProvider::Paymob
            || ! in_array($attempt->method, [PaymentMethod::Card, PaymentMethod::Wallet], true)
        ) {
            return false;
        }

        if ($attempt->method !== $order->payment_method
            || $attempt->provider_order_id !== (string) $transaction->providerOrderId
            || $attempt->integration_id !== $transaction->integrationId
            || $attempt->amount_minor !== $transaction->amountMinor
            || strtoupper($attempt->currency) !== $transaction->currency) {
            return false;
        }

        if ($attempt->method === PaymentMethod::Card) {
            try {
                if ($attempt->integration_id !== $this->integrations->resolve(PaymentMethod::Card)) {
                    return false;
                }
            } catch (\App\Exceptions\PaymobConfigurationException) {
                return false;
            }
        }

        if ($attempt->method === PaymentMethod::Wallet
            && $transaction->merchantOrderId !== $attempt->merchant_reference) {
            return false;
        }

        if ($transaction->merchantOrderId !== null
            && $transaction->merchantOrderId !== $attempt->merchant_reference) {
            return false;
        }

        try {
            return ExactMoney::toMinorUnitInteger((string) $order->total) === $attempt->amount_minor
                && strtoupper((string) $order->currency) === strtoupper($attempt->currency);
        } catch (InvalidArgumentException|OverflowException) {
            return false;
        }
    }

    private function walletOutcomeIsConsistent(PaymobTransaction $transaction): bool
    {
        return ($transaction->success && ! $transaction->pending && ! $transaction->errorOccurred)
            || (! $transaction->success && $transaction->pending && ! $transaction->errorOccurred)
            || (! $transaction->success && ! $transaction->pending && $transaction->errorOccurred);
    }

    private function hasNewerAttempt(PaymentAttempt $attempt): bool
    {
        return PaymentAttempt::query()
            ->where('order_id', $attempt->order_id)
            ->where('id', '>', $attempt->getKey())
            ->exists();
    }

    private function orderIsLateOrClosed(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Delivered, OrderStatus::DeliveryRefused], true)
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
