<?php

namespace App\Services;

use App\Exceptions\PaymobConfigurationException;
use App\Exceptions\PaymobWebhookException;

final class PaymobTransactionHmacVerifier
{
    /** @param array<string, mixed> $object */
    public function verify(array $object, mixed $suppliedHmac): void
    {
        $secret = config('services.paymob.hmac_secret');
        if (! is_string($secret) || $secret === '') {
            throw new PaymobConfigurationException('missing_hmac_secret', 'Paymob HMAC verification is not configured.');
        }
        if (! is_string($suppliedHmac) || preg_match('/^[a-f0-9]{128}$/i', $suppliedHmac) !== 1) {
            throw new PaymobWebhookException('invalid_hmac', 'The payment callback signature is invalid.', 401);
        }

        $source = $this->requiredObject($object, 'source_data');
        $order = $this->requiredObject($object, 'order');
        $values = [
            $this->required($object, 'amount_cents'),
            $this->required($object, 'created_at'),
            $this->required($object, 'currency'),
            $this->boolean($object, 'error_occured'),
            $this->boolean($object, 'has_parent_transaction'),
            $this->required($object, 'id'),
            $this->required($object, 'integration_id'),
            $this->boolean($object, 'is_3d_secure'),
            $this->boolean($object, 'is_auth'),
            $this->boolean($object, 'is_capture'),
            $this->boolean($object, 'is_refunded'),
            $this->boolean($object, 'is_standalone_payment'),
            $this->boolean($object, 'is_voided'),
            $this->required($order, 'id'),
            $this->required($object, 'owner'),
            $this->boolean($object, 'pending'),
            $this->required($source, 'pan'),
            $this->required($source, 'sub_type'),
            $this->required($source, 'type'),
            $this->boolean($object, 'success'),
        ];

        $signed = implode('', array_map([$this, 'stringify'], $values));
        $calculated = hash_hmac('sha512', $signed, $secret);
        if (! hash_equals(strtolower($calculated), strtolower($suppliedHmac))) {
            throw new PaymobWebhookException('invalid_hmac', 'The payment callback signature is invalid.', 401);
        }
    }

    /** @param array<string, mixed> $data */
    private function requiredObject(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key])) {
            throw new PaymobWebhookException('invalid_hmac', 'The payment callback signature is invalid.', 401);
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function required(array $data, string $key): mixed
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || is_array($data[$key]) || is_object($data[$key])) {
            throw new PaymobWebhookException('invalid_hmac', 'The payment callback signature is invalid.', 401);
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $key): bool
    {
        $value = $this->required($data, $key);
        if (! is_bool($value)) {
            throw new PaymobWebhookException('invalid_hmac', 'The payment callback signature is invalid.', 401);
        }
        return $value;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            default => throw new PaymobWebhookException('invalid_hmac', 'The payment callback signature is invalid.', 401),
        };
    }
}
