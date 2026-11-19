<?php

namespace App\Services;

use App\Enums\PaymentAttemptStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymobPaymentTransaction;

final class PaymobPaymentTransactionLedger
{
    public function record(
        PaymentAttempt $attempt,
        Order $order,
        PaymobTransaction $transaction,
        PaymentAttemptStatus $status,
        ?string $reviewCode = null,
    ): PaymobPaymentTransaction {
        $attributes = [
            'order_id' => $order->getKey(),
            'payment_attempt_id' => $attempt->getKey(),
            'provider_transaction_id' => (string) $transaction->transactionId,
            'provider_order_id' => (string) $transaction->providerOrderId,
            'merchant_reference' => $transaction->merchantOrderId,
            'amount_minor' => $transaction->amountMinor,
            'currency' => $transaction->currency,
            'integration_id' => $transaction->integrationId,
            'provider_is_live' => $transaction->isLive,
            'status' => $status,
            'review_code' => $reviewCode,
            'success' => $transaction->success,
            'pending' => $transaction->pending,
            'error_occurred' => $transaction->errorOccurred,
            'is_refunded' => $transaction->refunded,
            'refunded_amount_minor' => $transaction->refundedAmountMinor,
            'is_voided' => $transaction->voided,
            'source_type' => $transaction->sourceType,
            'source_subtype' => $transaction->sourceSubtype,
            'provider_created_at' => $transaction->createdAt,
            'received_at' => now(),
        ];

        $record = PaymobPaymentTransaction::query()
            ->where('provider_transaction_id', (string) $transaction->transactionId)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            return PaymobPaymentTransaction::query()->create($attributes);
        }

        if ($record->payment_attempt_id !== $attempt->getKey()
            || $record->order_id !== $order->getKey()) {
            return $record;
        }

        if ($record->status === PaymentAttemptStatus::Paid) {
            if ($status === PaymentAttemptStatus::RequiresReview) {
                $record->forceFill([
                    'review_code' => $reviewCode,
                    'is_refunded' => $record->is_refunded || $transaction->refunded,
                    'refunded_amount_minor' => $transaction->refundedAmountMinor ?? $record->refunded_amount_minor,
                    'is_voided' => $record->is_voided || $transaction->voided,
                ])->save();
            }

            return $record;
        }

        if ($record->status === PaymentAttemptStatus::RequiresReview
            && $status === PaymentAttemptStatus::Paid) {
            $record->forceFill($attributes)->save();

            return $record;
        }

        if ($record->status === PaymentAttemptStatus::RequiresReview || $record->status === $status) {
            return $record;
        }

        $record->forceFill($attributes)->save();

        return $record;
    }

    public function find(string $transactionId): ?PaymobPaymentTransaction
    {
        return PaymobPaymentTransaction::query()
            ->where('provider_transaction_id', $transactionId)
            ->lockForUpdate()
            ->first();
    }

    public function orderHasSuccessfulTransaction(Order $order): bool
    {
        return PaymobPaymentTransaction::query()
            ->where('order_id', $order->getKey())
            ->where('status', PaymentAttemptStatus::Paid->value)
            ->exists();
    }
}
