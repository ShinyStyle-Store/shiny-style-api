<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class PaymentReturnTokenService
{
    /**
     * @return array{order_public_id:string, payment_attempt_public_id:string}
     */
    public function issue(Order $order, PaymentAttempt $attempt): string
    {
        $encrypted = Crypt::encryptString(json_encode([
            'purpose' => 'payment-return',
            'order_public_id' => $order->public_id,
            'payment_attempt_public_id' => $attempt->public_id,
            'expires_at' => now()->addMinutes((int) config('payments.return_token_minutes', 120))->timestamp,
        ], JSON_THROW_ON_ERROR));
        $encoded = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');

        return $encoded;
    }

    /**
     * @return array{order_public_id:string, payment_attempt_public_id:string}|null
     */
    public function resolve(string $token): ?array
    {
        $encoded = trim($token);
        if ($encoded === '' || strlen($encoded) > 4096 || ! preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            return null;
        }

        $ciphertext = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        try {
            $payload = is_string($ciphertext) ? json_decode(Crypt::decryptString($ciphertext), true) : null;
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['purpose'] ?? null) !== 'payment-return'
            || ! is_string($payload['order_public_id'] ?? null)
            || ! is_string($payload['payment_attempt_public_id'] ?? null)
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] < now()->timestamp) {
            return null;
        }

        return [
            'order_public_id' => $payload['order_public_id'],
            'payment_attempt_public_id' => $payload['payment_attempt_public_id'],
        ];
    }

}
