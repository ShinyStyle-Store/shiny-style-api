<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Exceptions\PaymobConfigurationException;

final class PaymobIntegrationResolver
{
    public function resolve(PaymentMethod $method): int
    {
        return match ($method) {
            PaymentMethod::Card => $this->configuredId('card_integration_id', 'card'),
            PaymentMethod::Wallet => $this->configuredId('wallet_integration_id', 'wallet'),
            PaymentMethod::CashOnDelivery => throw new PaymobConfigurationException(
                'unsupported_payment_method',
                'Cash on delivery is not a Paymob payment method.',
            ),
        };
    }

    private function configuredId(string $key, string $method): int
    {
        $value = config("services.paymob.{$key}");
        $normalized = $this->normalizePositiveInteger($value);

        if ($normalized === null) {
            throw new PaymobConfigurationException(
                $value === null || trim((string) $value) === ''
                    ? "missing_{$method}_integration_id"
                    : "invalid_{$method}_integration_id",
                "Paymob {$method} integration is not configured correctly.",
            );
        }

        return $normalized;
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/', trim($value))) {
            return null;
        }

        $validated = filter_var(trim($value), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validated === false ? null : (int) $validated;
    }
}
