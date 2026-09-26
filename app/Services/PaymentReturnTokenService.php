<?php

namespace App\Services;

use App\Exceptions\PaymentAttemptException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

final class PaymentReturnTokenService
{
    public const TOKEN_BYTES = 16;

    public function issue(Order $order, PaymentAttempt $attempt): string
    {
        if ($attempt->order_id !== $order->getKey()) {
            throw new PaymentAttemptException(
                'payment_return_token_binding_failed',
                'The payment return token could not be created.',
            );
        }

        try {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (\Throwable $exception) {
            throw new PaymentAttemptException(
                'payment_return_token_generation_failed',
                'The payment return token could not be created.',
            );
        }

        $attributes = [
            'payment_return_token_hash' => hash('sha256', $token),
            'payment_return_token_expires_at' => now()->addMinutes((int) config('payments.return_token_minutes', 120)),
        ];
        $attemptId = $attempt->getKey();
        $orderId = $order->getKey();
        $updated = DB::table('payment_attempts')
            ->where('id', $attemptId)
            ->where('order_id', $orderId)
            ->update([
                'payment_return_token_hash' => $attributes['payment_return_token_hash'],
                'payment_return_token_expires_at' => $attributes['payment_return_token_expires_at'],
                'updated_at' => now(),
            ]);

        $stored = DB::table('payment_attempts')
            ->where('id', $attemptId)
            ->where('order_id', $orderId)
            ->first([
                'payment_return_token_hash',
                'payment_return_token_expires_at',
            ]);

        if ($updated !== 1
            || $stored === null
            || ! hash_equals($attributes['payment_return_token_hash'], (string) $stored->payment_return_token_hash)
            || $stored->payment_return_token_expires_at === null) {
            throw new PaymentAttemptException(
                'payment_return_token_persistence_failed',
                'The payment return token could not be created.',
            );
        }

        $attempt->forceFill($attributes)->syncChanges();

        return $token;
    }

    /**
     * @return array{order_public_id:string, payment_attempt_public_id:string}|null
     */
    public function resolve(string $token): ?array
    {
        if ($token === '' || strlen($token) !== self::TOKEN_BYTES * 2 || ! preg_match('/^[a-f0-9]+$/', $token)) {
            return null;
        }

        $attempt = PaymentAttempt::query()
            ->where('payment_return_token_hash', hash('sha256', $token))
            ->where('payment_return_token_expires_at', '>=', now())
            ->first();

        if ($attempt === null) {
            return null;
        }

        return [
            'order_public_id' => $attempt->order->public_id,
            'payment_attempt_public_id' => $attempt->public_id,
        ];
    }
}
