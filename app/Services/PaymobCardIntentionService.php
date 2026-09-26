<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentAttemptException;
use App\Exceptions\PaymobConfigurationException;
use App\Exceptions\PaymobRequestException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Support\ExactMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;
use Throwable;

final class PaymobCardIntentionService
{
    public function __construct(
        private readonly PaymobClient $client,
        private readonly PaymobIntegrationResolver $integrations,
        private readonly PaymentReturnTokenService $returnTokens,
    ) {
    }

    public function initiate(PaymentAttempt $paymentAttempt): PaymobIntentionResult
    {
        $configuration = $this->validateCardCheckoutConfiguration();
        $endpoint = $configuration['endpoint'];
        $integrationId = $configuration['integration_id'];

        $prepared = DB::transaction(function () use ($paymentAttempt, $integrationId): array {
            $orderId = (int) $paymentAttempt->order_id;
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new PaymentAttemptException('order_not_payable', 'The order is unavailable.');
            }

            $lockedAttempt = PaymentAttempt::query()
                ->whereKey($paymentAttempt->getKey())
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null) {
                throw new PaymentAttemptException('order_not_payable', 'The payment attempt is unavailable.');
            }

            if ($lockedAttempt->status === PaymentAttemptStatus::Pending) {
                if ($order->payment_status === PaymentStatus::Paid) {
                    throw new PaymentAttemptException('order_already_paid', 'The order has already been paid.');
                }
                if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Delivered], true)) {
                    throw new PaymentAttemptException('order_not_payable', 'The order is not payable.');
                }
                if ($order->payment_expires_at === null || $order->payment_expires_at->isPast()) {
                    throw new PaymentAttemptException('payment_window_expired', 'The payment window has expired.');
                }
                if ($lockedAttempt->provider_client_secret === null || $lockedAttempt->provider_intention_id === null) {
                    throw new PaymentAttemptException('payment_attempt_result_incomplete', 'The payment result is incomplete.');
                }

                return ['attempt' => $lockedAttempt, 'snapshot' => null];
            }

            if ($lockedAttempt->status === PaymentAttemptStatus::Submitting) {
                throw new PaymentAttemptException(
                    'payment_outcome_uncertain',
                    'The payment provider outcome is uncertain; manual review is required.',
                );
            }

            $this->assertEligible($lockedAttempt, $order, $integrationId);
            $snapshot = $this->snapshot($lockedAttempt, $order, $integrationId);

            $lockedAttempt->forceFill([
                'status' => PaymentAttemptStatus::Submitting,
                'submission_claimed_at' => now(),
            ])->save();

            return ['attempt' => $lockedAttempt->fresh(), 'snapshot' => $snapshot];
        });

        /** @var PaymentAttempt $preparedAttempt */
        $preparedAttempt = $prepared['attempt'];
        if ($prepared['snapshot'] === null) {
            return $this->resultFromStored($preparedAttempt);
        }

        /** @var PaymobIntentionSnapshot $snapshot */
        $snapshot = $prepared['snapshot'];

        try {
            $response = $this->client->postJson('create_intention', $endpoint, $snapshot->payload, [
                'payment_attempt_public_id' => $preparedAttempt->public_id,
                'merchant_reference' => $preparedAttempt->merchant_reference,
            ]);
            $validated = $this->validateResponse($response, $snapshot, $integrationId);
        } catch (PaymobRequestException $exception) {
            if ($exception->errorCode === 'provider_client_error') {
                $this->markFailed($preparedAttempt->getKey(), $exception->errorCode);
                throw $exception;
            }

            $this->markUncertain($preparedAttempt->getKey(), $exception->errorCode);
            throw new PaymentAttemptException(
                'payment_outcome_uncertain',
                'The payment provider outcome is uncertain; manual review is required.',
            );
        } catch (PaymentAttemptException|InvalidArgumentException $exception) {
            $this->markUncertain($preparedAttempt->getKey(), 'malformed_provider_response');
            throw new PaymentAttemptException(
                'payment_outcome_uncertain',
                'The payment provider outcome is uncertain; manual review is required.',
            );
        } catch (Throwable $exception) {
            $this->markUncertain($preparedAttempt->getKey(), 'malformed_provider_response');
            throw new PaymentAttemptException(
                'payment_outcome_uncertain',
                'The payment provider outcome is uncertain; manual review is required.',
            );
        }

        return DB::transaction(function () use ($preparedAttempt, $validated, $snapshot): PaymobIntentionResult {
            $orderId = (int) $preparedAttempt->order_id;
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new PaymentAttemptException('payment_outcome_uncertain', 'The payment provider outcome is uncertain; manual review is required.');
            }
            $lockedAttempt = PaymentAttempt::query()
                ->whereKey($preparedAttempt->getKey())
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->first();
            if ($lockedAttempt === null || $lockedAttempt->status !== PaymentAttemptStatus::Submitting) {
                throw new PaymentAttemptException('payment_outcome_uncertain', 'The payment provider outcome is uncertain; manual review is required.');
            }
            $this->assertStillPersistable($lockedAttempt, $order);

            $lockedAttempt->forceFill([
                'status' => PaymentAttemptStatus::Pending,
                'provider_intention_id' => $validated['intention_id'],
                'provider_order_id' => $validated['order_id'],
                'integration_id' => $snapshot->integrationId,
                'provider_client_secret' => $validated['client_secret'],
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            return $this->resultFromStored($lockedAttempt);
        });
    }

    /** @return array{endpoint:string,integration_id:int} */
    public function validateCardCheckoutConfiguration(): array
    {
        $endpoint = (string) config('services.paymob.intention_endpoint', '/v1/intention/');
        $this->client->validateConfiguration($endpoint);
        $integrationId = $this->integrations->resolve(PaymentMethod::Card);
        $this->requiredHttpsUrl('redirect_url');
        $this->requiredHttpsUrl('webhook_url');
        $this->requiredPublicKey();
        $this->requiredHttpsUrl('unified_checkout_base_url');

        return ['endpoint' => $endpoint, 'integration_id' => $integrationId];
    }

    private function assertEligible(PaymentAttempt $attempt, Order $order, int $integrationId): void
    {
        if ($attempt->provider !== PaymentProvider::Paymob || $attempt->method !== PaymentMethod::Card) {
            throw new PaymentAttemptException('unsupported_payment_method', 'Only Paymob card attempts can be initiated.');
        }
        if ($attempt->status !== PaymentAttemptStatus::Created) {
            if ($attempt->status === PaymentAttemptStatus::Paid || $order->payment_status === PaymentStatus::Paid) {
                throw new PaymentAttemptException('order_already_paid', 'The order has already been paid.');
            }
            throw new PaymentAttemptException('payment_attempt_not_retryable', 'The payment attempt cannot be initiated.');
        }
        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Delivered], true)) {
            throw new PaymentAttemptException('order_not_payable', 'The order is not payable.');
        }
        if ($order->payment_status === PaymentStatus::Paid) {
            throw new PaymentAttemptException('order_already_paid', 'The order has already been paid.');
        }
        if ($order->payment_expires_at === null) {
            throw new PaymentAttemptException('order_not_payable', 'The order does not have an active payment window.');
        }
        if ($order->payment_expires_at->isPast()) {
            throw new PaymentAttemptException('payment_window_expired', 'The payment window has expired.');
        }
        if ($attempt->expires_at === null || $attempt->expires_at->gt($order->payment_expires_at)) {
            throw new PaymentAttemptException('order_not_payable', 'The payment attempt expiry is invalid.');
        }

        try {
            $expectedAmount = ExactMoney::toMinorUnitInteger((string) $order->total);
        } catch (InvalidArgumentException|OverflowException) {
            throw new PaymentAttemptException('invalid_payment_amount', 'The order payment amount is invalid.');
        }
        if ($attempt->amount_minor !== $expectedAmount) {
            throw new PaymentAttemptException('invalid_payment_amount', 'The payment attempt amount is invalid.');
        }
        $currency = strtoupper(trim((string) $order->currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency) || $attempt->currency !== $currency) {
            throw new PaymentAttemptException('order_not_payable', 'The payment currency is invalid.');
        }
        if ($integrationId <= 0) {
            throw new PaymobConfigurationException('invalid_card_integration_id', 'Paymob card integration is invalid.');
        }
    }

    private function assertStillPersistable(PaymentAttempt $attempt, Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Delivered], true)
            || $order->payment_status === PaymentStatus::Paid
            || $order->payment_expires_at === null
            || $order->payment_expires_at->isPast()
            || $attempt->expires_at === null
            || $attempt->expires_at->gt($order->payment_expires_at)) {
            throw new PaymentAttemptException('payment_outcome_uncertain', 'The payment provider outcome is uncertain; manual review is required.');
        }

        try {
            $amount = ExactMoney::toMinorUnitInteger((string) $order->total);
        } catch (InvalidArgumentException|OverflowException) {
            throw new PaymentAttemptException('payment_outcome_uncertain', 'The payment provider outcome is uncertain; manual review is required.');
        }
        if ($amount !== $attempt->amount_minor || strtoupper(trim((string) $order->currency)) !== $attempt->currency) {
            throw new PaymentAttemptException('payment_outcome_uncertain', 'The payment provider outcome is uncertain; manual review is required.');
        }
    }

    private function snapshot(PaymentAttempt $attempt, Order $order, int $integrationId): PaymobIntentionSnapshot
    {
        $remainingSeconds = now()->diffInSeconds($order->payment_expires_at, false);
        $seconds = min(86400, (int) floor($remainingSeconds));
        if ($seconds < 1) {
            throw new PaymentAttemptException('payment_window_expired', 'The payment window has expired.');
        }
        $items = [];
        $represented = 0;
        foreach ($order->items as $item) {
            $unit = $this->minor((string) $item->unit_price);
            $line = $this->minor((string) $item->line_total);
            $quantity = (int) $item->quantity;
            if ($quantity < 1 || $unit * $quantity !== $line) {
                throw new PaymentAttemptException('invalid_payment_amount', 'The stored order item amount is invalid.');
            }
            $represented += $line;
            $items[] = [
                'name' => trim((string) ($item->product_name_en ?: $item->product_name_ar)),
                'amount' => $unit,
                'description' => trim((string) ($item->product_name_en ?: $item->product_name_ar)),
                'quantity' => $quantity,
            ];
        }

        $shipping = $this->minor((string) $order->shipping_fee);
        if ($shipping > 0) {
            $represented += $shipping;
            $items[] = ['name' => 'Shipping', 'amount' => $shipping, 'description' => 'Shipping', 'quantity' => 1];
        }
        if ($represented !== $attempt->amount_minor) {
            throw new PaymentAttemptException('invalid_payment_amount', 'The order items do not reconcile with the order total.');
        }

        $billing = $this->billing($order);
        return new PaymobIntentionSnapshot(
            (int) $attempt->getKey(),
            $integrationId,
            $attempt->merchant_reference,
            $order->payment_expires_at,
            [
                'amount' => $attempt->amount_minor,
                'currency' => $attempt->currency,
                'payment_methods' => [$integrationId],
                'special_reference' => $attempt->merchant_reference,
                'expiration' => $seconds,
                'items' => $items,
                'billing_data' => $billing,
                'notification_url' => $this->requiredHttpsUrl('webhook_url'),
                'redirection_url' => $this->returnUrl($order, $attempt),
            ],
        );
    }

    private function returnUrl(Order $order, PaymentAttempt $attempt): string
    {
        return $this->requiredHttpsUrl('redirect_url')
            .'#payment_return='.rawurlencode($this->returnTokens->issue($order, $attempt));
    }

    /** @return array<string, string> */
    private function billing(Order $order): array
    {
        $name = preg_split('/\s+/', trim((string) $order->customer_name), 2) ?: [];
        $first = trim((string) ($name[0] ?? ''));
        $last = trim((string) ($name[1] ?? $first));
        $email = trim((string) $order->customer_email);
        $phone = trim((string) $order->customer_phone);
        $street = trim((string) $order->shipping_address);
        $city = trim((string) ($order->shipping_area_name_en ?: $order->shipping_area_name_ar));
        if ($first === '' || $last === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || $street === '' || $city === '') {
            throw new PaymentAttemptException('invalid_billing_data', 'The stored billing data is incomplete.');
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'phone_number' => $phone,
            'email' => $email,
            'country' => 'Egypt',
            'city' => $city,
            'state' => $city,
            'street' => $street,
        ];
    }

    /** @param array<string, mixed> $response @return array{intention_id:string, client_secret:string, order_id:?string} */
    private function validateResponse(array $response, PaymobIntentionSnapshot $snapshot, int $integrationId): array
    {
        $id = $response['id'] ?? null;
        $secret = $response['client_secret'] ?? null;
        if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '' || ! is_string($secret) || trim($secret) === '') {
            throw new PaymentAttemptException('malformed_provider_response', 'The payment provider response is incomplete.');
        }
        if (array_key_exists('amount', $response) && (string) $response['amount'] !== (string) $snapshot->payload['amount']) {
            throw new PaymentAttemptException('malformed_provider_response', 'The payment provider amount is inconsistent.');
        }
        if (array_key_exists('currency', $response) && strtoupper((string) $response['currency']) !== $snapshot->payload['currency']) {
            throw new PaymentAttemptException('malformed_provider_response', 'The payment provider currency is inconsistent.');
        }
        if (array_key_exists('special_reference', $response) && (string) $response['special_reference'] !== $snapshot->merchantReference) {
            throw new PaymentAttemptException('malformed_provider_response', 'The payment provider reference is inconsistent.');
        }
        if (array_key_exists('payment_methods', $response)) {
            $methods = is_array($response['payment_methods']) ? $response['payment_methods'] : [$response['payment_methods']];
            $matches = false;
            foreach ($methods as $method) {
                $returned = is_array($method) ? ($method['integration_id'] ?? null) : $method;
                if ((string) $returned === (string) $integrationId) {
                    $matches = true;
                }
            }
            if (! $matches) {
                throw new PaymentAttemptException('malformed_provider_response', 'The payment provider method is inconsistent.');
            }
        }
        $orderId = $response['intention_order_id']
            ?? $response['order']['id']
            ?? $response['order_id']
            ?? null;
        if ($orderId !== null && (! is_string($orderId) && ! is_int($orderId))) {
            throw new PaymentAttemptException('malformed_provider_response', 'The payment provider order is invalid.');
        }
        return ['intention_id' => (string) $id, 'client_secret' => $secret, 'order_id' => $orderId === null ? null : (string) $orderId];
    }

    private function resultFromStored(PaymentAttempt $attempt): PaymobIntentionResult
    {
        if ($attempt->provider_client_secret === null || $attempt->provider_intention_id === null || $attempt->expires_at === null) {
            throw new PaymentAttemptException('payment_attempt_result_incomplete', 'The payment result is incomplete.');
        }
        return new PaymobIntentionResult(
            (int) $attempt->getKey(),
            $attempt->status,
            $attempt->provider_intention_id,
            $this->checkoutUrl($attempt->provider_client_secret),
            $attempt->expires_at,
        );
    }

    private function checkoutUrl(string $clientSecret): string
    {
        return rtrim($this->requiredHttpsUrl('unified_checkout_base_url'), '/').'?'.http_build_query([
            'publicKey' => $this->requiredPublicKey(),
            'clientSecret' => $clientSecret,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function requiredPublicKey(): string
    {
        $key = config('services.paymob.public_key');
        if (! is_string($key) || trim($key) === '') {
            throw new PaymobConfigurationException('missing_public_key', 'Paymob public key is not configured.');
        }
        return trim($key);
    }

    private function requiredHttpsUrl(string $key): string
    {
        $url = config("services.paymob.{$key}");
        $parts = is_string($url) ? parse_url(trim($url)) : false;
        if (! is_string($url) || trim($url) === '' || $parts === false || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new PaymobConfigurationException("invalid_{$key}", "Paymob {$key} is invalid.");
        }
        return rtrim(trim($url), '/');
    }

    private function minor(string $value): int
    {
        try {
            return ExactMoney::toMinorUnitInteger($value);
        } catch (InvalidArgumentException|OverflowException) {
            throw new PaymentAttemptException('invalid_payment_amount', 'The stored payment amount is invalid.');
        }
    }

    private function markFailed(int $id, string $code): void
    {
        DB::transaction(function () use ($id, $code): void {
            $attempt = PaymentAttempt::query()->whereKey($id)->lockForUpdate()->first();
            if ($attempt?->status === PaymentAttemptStatus::Submitting) {
                $attempt->forceFill(['status' => PaymentAttemptStatus::Failed, 'failure_code' => $code, 'failure_message' => null])->save();
            }
        });
    }

    private function markUncertain(int $id, string $code): void
    {
        DB::transaction(function () use ($id, $code): void {
            $attempt = PaymentAttempt::query()->whereKey($id)->lockForUpdate()->first();
            if ($attempt?->status === PaymentAttemptStatus::Submitting) {
                $attempt->forceFill(['status' => PaymentAttemptStatus::RequiresReview, 'failure_code' => $code, 'failure_message' => null])->save();
            }
        });
    }
}
