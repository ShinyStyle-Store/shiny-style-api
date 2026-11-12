<?php

namespace App\Services;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Support\ExactMoney;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;

final class PaymobTransactionInquiryService
{
    public function __construct(
        private readonly PaymobClient $client,
        private readonly PaymobIntegrationResolver $integrations,
    ) {}

    /** @return array{result:string,status:string} */
    public function verify(PaymentAttempt $attempt): array
    {
        $attempt = $attempt->fresh(['order']);
        if ($attempt === null || $attempt->status !== PaymentAttemptStatus::RequiresReview) {
            return ['result' => 'no_longer_under_review', 'status' => $attempt?->status?->value ?? 'unknown'];
        }

        $response = $this->client->inquireTransactionByOrder(
            $this->validProviderOrderId($attempt->provider_order_id) ? $attempt->provider_order_id : null,
            $attempt->merchant_reference,
        );
        if ($response === null) {
            return ['result' => 'not_found', 'status' => PaymentAttemptStatus::RequiresReview->value];
        }

        $outcome = $this->verifiedOutcome($attempt, $response);
        if ($outcome === null) {
            return ['result' => 'unverified', 'status' => PaymentAttemptStatus::RequiresReview->value];
        }

        return DB::transaction(function () use ($attempt, $response, $outcome): array {
            $order = Order::query()->whereKey($attempt->order_id)->lockForUpdate()->first();
            $lockedAttempt = PaymentAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('order_id', $attempt->order_id)
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null || $order === null) {
                return ['result' => 'unverified', 'status' => PaymentAttemptStatus::RequiresReview->value];
            }
            if ($lockedAttempt->status !== PaymentAttemptStatus::RequiresReview) {
                return ['result' => 'already_updated', 'status' => $lockedAttempt->status->value];
            }

            $transactionId = (string) $response['id'];
            $owner = PaymentAttempt::query()->where('provider_transaction_id', $transactionId)->first();
            if ($owner !== null && $owner->getKey() !== $lockedAttempt->getKey()) {
                return ['result' => 'unverified', 'status' => PaymentAttemptStatus::RequiresReview->value];
            }

            if ($outcome === 'pending') {
                $latestAttemptId = PaymentAttempt::query()
                    ->where('order_id', $order->getKey())
                    ->max('id');
                $lateWalletAttempt = $lockedAttempt->method === PaymentMethod::Wallet
                    && ($this->orderIsLateOrClosed($order) || (int) $latestAttemptId !== $lockedAttempt->getKey());
                $lockedAttempt->forceFill([
                    'provider_transaction_id' => $lockedAttempt->provider_transaction_id ?? $transactionId,
                    'failure_code' => $lateWalletAttempt ? 'wallet_pending_requires_review' : $lockedAttempt->failure_code,
                ])->save();
                if (! $lateWalletAttempt
                    && (int) $latestAttemptId === $lockedAttempt->getKey()
                    && $order->payment_status !== PaymentStatus::Paid) {
                    $order->forceFill(['payment_status' => PaymentStatus::Pending])->save();
                }

                return [
                    'result' => $lateWalletAttempt ? 'pending_requires_review' : 'pending',
                    'status' => PaymentAttemptStatus::RequiresReview->value,
                ];
            }

            if ($outcome === 'success') {
                $latestAttemptId = PaymentAttempt::query()
                    ->where('order_id', $order->getKey())
                    ->max('id');
                if ($lockedAttempt->method === PaymentMethod::Wallet
                    && ($this->orderIsLateOrClosed($order) || (int) $latestAttemptId !== $lockedAttempt->getKey())) {
                    $lockedAttempt->forceFill([
                        'provider_transaction_id' => $transactionId,
                        'failure_code' => 'late_wallet_success',
                        'failure_message' => null,
                    ])->save();

                    return ['result' => 'late_success_requires_review', 'status' => PaymentAttemptStatus::RequiresReview->value];
                }

                $lockedAttempt->forceFill([
                    'status' => PaymentAttemptStatus::Paid,
                    'provider_transaction_id' => $transactionId,
                    'paid_at' => $lockedAttempt->paid_at ?? now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
                $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

                return ['result' => 'verified_success', 'status' => PaymentAttemptStatus::Paid->value];
            }

            $lockedAttempt->forceFill([
                'status' => PaymentAttemptStatus::Failed,
                'provider_transaction_id' => $transactionId,
                'failure_code' => 'provider_transaction_failed',
                'failure_message' => null,
            ])->save();
            $latestAttemptId = PaymentAttempt::query()
                ->where('order_id', $order->getKey())
                ->max('id');
            if ((int) $latestAttemptId === $lockedAttempt->getKey() && $order->payment_status !== PaymentStatus::Paid) {
                $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
            }

            return ['result' => 'verified_failure', 'status' => PaymentAttemptStatus::Failed->value];
        });
    }

    /** @param array<string, mixed> $response */
    private function verifiedOutcome(PaymentAttempt $attempt, array $response): ?string
    {
        if ($attempt->provider !== PaymentProvider::Paymob
            || ! in_array($attempt->method, [PaymentMethod::Card, PaymentMethod::Wallet], true)
            || $attempt->order === null
            || $attempt->order->payment_method !== $attempt->method
            || $attempt->integration_id === null
            || $attempt->provider_is_live === null) {
            return null;
        }

        try {
            $configuredIntegrationId = $this->integrations->resolve($attempt->method);
        } catch (\App\Exceptions\PaymobConfigurationException) {
            return null;
        }
        $configuredEnvironment = $this->configuredEnvironment();
        if ($configuredEnvironment === null
            || $configuredEnvironment !== $attempt->provider_is_live
            || $configuredIntegrationId !== $attempt->integration_id) {
            return null;
        }

        $orderData = $response['order'] ?? null;
        if (! is_array($orderData)
            || ! $this->positiveInteger($response['id'] ?? null)
            || ! $this->positiveInteger($orderData['id'] ?? null)
            || ! $this->positiveInteger($response['integration_id'] ?? null)
            || ! $this->positiveInteger($response['amount_cents'] ?? null)
            || ! is_string($response['currency'] ?? null)
            || ! is_bool($response['success'] ?? null)
            || ! is_bool($response['pending'] ?? null)
            || ! is_bool($response['error_occured'] ?? null)
            || ! is_bool($response['is_live'] ?? null)) {
            return null;
        }

        $transactionId = (string) $response['id'];
        $currency = strtoupper(trim($response['currency']));
        if (($attempt->provider_order_id !== null
                && (string) $orderData['id'] !== $attempt->provider_order_id)
            || (int) $response['integration_id'] !== $attempt->integration_id
            || (int) $response['amount_cents'] !== $attempt->amount_minor
            || $currency !== strtoupper($attempt->currency)
            || (bool) $response['is_live'] !== $attempt->provider_is_live
            || ($attempt->provider_transaction_id !== null && $attempt->provider_transaction_id !== $transactionId)) {
            return null;
        }

        $returnedReference = $orderData['merchant_order_id'] ?? null;
        if ($returnedReference !== null
            && (! is_string($returnedReference) || $returnedReference !== $attempt->merchant_reference)) {
            return null;
        }
        if ($attempt->method === PaymentMethod::Wallet && $returnedReference !== $attempt->merchant_reference) {
            return null;
        }
        if ($attempt->provider_order_id === null
            && $returnedReference !== $attempt->merchant_reference) {
            return null;
        }

        foreach (['is_refunded', 'is_voided', 'is_refund', 'is_void'] as $optionalBoolean) {
            if (array_key_exists($optionalBoolean, $response) && ! is_bool($response[$optionalBoolean])) {
                return null;
            }
        }
        if (($response['is_refunded'] ?? false) || ($response['is_refund'] ?? false)
            || ($response['is_voided'] ?? false) || ($response['is_void'] ?? false)) {
            return null;
        }

        if ($attempt->method === PaymentMethod::Wallet
            && ! $this->walletOutcomeIsConsistent($response['success'], $response['pending'], $response['error_occured'])) {
            return null;
        }

        try {
            $orderAmount = ExactMoney::toMinorUnitInteger((string) $attempt->order->total);
        } catch (InvalidArgumentException|OverflowException) {
            return null;
        }
        if ($orderAmount !== $attempt->amount_minor
            || strtoupper((string) $attempt->order->currency) !== strtoupper($attempt->currency)) {
            return null;
        }

        if ($response['pending']) {
            return 'pending';
        }
        if ($response['success'] && ! $response['error_occured']) {
            return 'success';
        }

        return $response['success'] ? null : 'failure';
    }

    private function walletOutcomeIsConsistent(bool $success, bool $pending, bool $errorOccurred): bool
    {
        return ($success && ! $pending && ! $errorOccurred)
            || (! $success && $pending && ! $errorOccurred)
            || (! $success && ! $pending && $errorOccurred);
    }

    private function configuredEnvironment(): ?bool
    {
        $value = config('services.paymob.is_live');
        if (is_bool($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function validProviderOrderId(?string $providerOrderId): bool
    {
        return is_string($providerOrderId) && preg_match('/^[1-9][0-9]*$/', $providerOrderId) === 1;
    }

    private function orderIsLateOrClosed(Order $order): bool
    {
        return in_array($order->status, [
            \App\Enums\OrderStatus::Cancelled,
            \App\Enums\OrderStatus::Delivered,
            \App\Enums\OrderStatus::DeliveryRefused,
        ], true)
            || $order->payment_expires_at === null
            || $order->payment_expires_at->isPast();
    }

    private function positiveInteger(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1);
    }
}
