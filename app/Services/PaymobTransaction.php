<?php

namespace App\Services;

use App\Exceptions\PaymobWebhookException;

final readonly class PaymobTransaction
{
    public function __construct(
        public int $transactionId,
        public int $providerOrderId,
        public int $integrationId,
        public int $amountMinor,
        public string $currency,
        public bool $success,
        public bool $pending,
        public bool $errorOccurred,
        public bool $refunded,
        public bool $voided,
        public string $sourceType,
        public string $sourceSubtype,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $object */
    public static function fromArray(array $object): self
    {
        $order = self::object($object, 'order');
        $source = self::object($object, 'source_data');

        return new self(
            self::positiveInteger($object, 'id'),
            self::positiveInteger($order, 'id'),
            self::positiveInteger($object, 'integration_id'),
            self::positiveInteger($object, 'amount_cents'),
            self::currency($object['currency'] ?? null),
            self::boolean($object, 'success'),
            self::boolean($object, 'pending'),
            self::boolean($object, 'error_occured'),
            self::boolean($object, 'is_refunded') || self::optionalBoolean($object, 'is_refund'),
            self::boolean($object, 'is_voided') || self::optionalBoolean($object, 'is_void'),
            self::nonEmptyString($source, 'type'),
            self::nonEmptyString($source, 'sub_type'),
            self::optionalString($object, 'created_at'),
            self::optionalString($object, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function object(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key])) {
            throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function positiveInteger(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($integer !== false) {
                return (int) $integer;
            }
        }
        throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
    }

    private static function currency(mixed $value): string
    {
        if (! is_string($value)) {
            throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
        }
        $currency = strtoupper(trim($value));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
        }
        return $currency;
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key): bool
    {
        if (! array_key_exists($key, $data) || ! is_bool($data[$key])) {
            throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function nonEmptyString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (! is_string($data[$key]) || trim($data[$key]) === '') {
            throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
        }
        return trim($data[$key]);
    }

    /** @param array<string, mixed> $data */
    private static function optionalBoolean(array $data, string $key): bool
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return false;
        }
        if (! is_bool($data[$key])) {
            throw new PaymobWebhookException('invalid_callback', 'The payment callback is invalid.');
        }
        return $data[$key];
    }
}
