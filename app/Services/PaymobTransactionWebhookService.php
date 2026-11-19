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
    public function __construct(
        private readonly PaymobIntegrationResolver $integrations,
        private readonly PaymobPaymentTransactionLedger $ledger,
    ) {}

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
                $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::RequiresReview, 'callback_identity_mismatch');
                $this->review($attempt, 'callback_identity_mismatch', $transaction->transactionId);

                return new PaymobWebhookResult;
            }

            $priorTransaction = $this->ledger->find((string) $transaction->transactionId);
            if ($priorTransaction !== null
                && $priorTransaction->payment_attempt_id === $attempt->getKey()
                && $priorTransaction->order_id === $order->getKey()
                && ! $transaction->refunded
                && ! $transaction->voided
                && ($priorTransaction->status === PaymentAttemptStatus::Paid
                    || ($priorTransaction->status === PaymentAttemptStatus::RequiresReview
                        && $priorTransaction->success === true
                        && $priorTransaction->pending === false
                        && $priorTransaction->error_occurred === false
                        && $transaction->success
                        && ! $transaction->pending
                        && ! $transaction->errorOccurred)
                    || ($priorTransaction->status === PaymentAttemptStatus::RequiresReview
                        && $priorTransaction->pending === true
                        && $priorTransaction->success === false
                        && $priorTransaction->error_occurred === false
                        && $transaction->pending
                        && ! $transaction->success
                        && ! $transaction->errorOccurred))) {
                return new PaymobWebhookResult;
            }

            if ($transaction->refunded || $transaction->voided) {
                $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::RequiresReview, 'provider_refund_or_void');
                $this->review($attempt, 'provider_refund_or_void', $transaction->transactionId);

                return new PaymobWebhookResult;
            }

            if ($attempt->method === PaymentMethod::Wallet && ! $this->walletOutcomeIsConsistent($transaction)) {
                $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::RequiresReview, 'callback_status_flags_inconsistent');
                $this->review($attempt, 'callback_status_flags_inconsistent', $transaction->transactionId);

                return new PaymobWebhookResult;
            }

            if ($transaction->pending) {
                if ($order->payment_status === PaymentStatus::Paid
                    || $this->orderIsLateOrClosed($order)
                    || $attempt->status === PaymentAttemptStatus::Expired
                    || $attempt->status === PaymentAttemptStatus::RequiresReview) {
                    $reviewCode = $order->payment_status === PaymentStatus::Paid
                        ? 'additional_transaction_pending'
                        : ($this->orderIsLateOrClosed($order) || $attempt->status === PaymentAttemptStatus::Expired
                            ? 'late_pending_callback'
                            : 'pending_attempt_requires_review');
                    $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::RequiresReview, $reviewCode);
                    $this->review($attempt, $reviewCode, $transaction->transactionId);
                    return new PaymobWebhookResult;
                }
                $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::Pending);
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
                if ($this->orderIsLateOrClosed($order)
                    || $attempt->status === PaymentAttemptStatus::Expired) {
                    $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::RequiresReview, 'late_payment_callback');
                    $this->review($attempt, 'late_payment_callback', $transaction->transactionId);

                    return new PaymobWebhookResult;
                }

                if ($order->payment_status === PaymentStatus::Paid
                    || $attempt->status === PaymentAttemptStatus::Paid
                    || $this->ledger->orderHasSuccessfulTransaction($order)) {
                    $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::RequiresReview, 'additional_success_after_paid');
                    $this->review($attempt, 'additional_success_after_paid', $transaction->transactionId);

                    return new PaymobWebhookResult;
                }

                $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::Paid);

                $attempt->forceFill([
                    'status' => PaymentAttemptStatus::Paid,
                    'provider_transaction_id' => $attempt->provider_transaction_id ?? (string) $transaction->transactionId,
                    'paid_at' => $attempt->paid_at ?? now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
                $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

                return new PaymobWebhookResult;
            }

            if ($attempt->status === PaymentAttemptStatus::Paid) {
                return new PaymobWebhookResult;
            }

            $this->ledger->record($attempt, $order, $transaction, PaymentAttemptStatus::RequiresReview, 'provider_transaction_failed');
            if ($attempt->status !== PaymentAttemptStatus::Expired) {
                $this->review($attempt, 'provider_transaction_failed', $transaction->transactionId);
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

        try {
            if ($attempt->integration_id !== $this->integrations->resolve($attempt->method)) {
                return false;
            }
        } catch (\App\Exceptions\PaymobConfigurationException) {
            return false;
        }

        if ($transaction->merchantOrderId !== $attempt->merchant_reference) {
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
