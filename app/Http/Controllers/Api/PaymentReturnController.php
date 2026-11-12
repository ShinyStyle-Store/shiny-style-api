<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentReturnResource;
use App\Models\PaymentAttempt;
use App\Services\PaymentReturnTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReturnController extends Controller
{
    public function show(Request $request, PaymentReturnTokenService $tokens): JsonResponse
    {
        $grant = $tokens->resolve((string) $request->bearerToken());
        if ($grant === null) {
            return response()->json([
                'code' => 'invalid_payment_return_token',
                'message' => 'The payment return token is invalid or expired.',
            ], 401);
        }

        $attempt = PaymentAttempt::query()
            ->where('public_id', $grant['payment_attempt_public_id'])
            ->first();
        $order = $attempt?->order;

        if ($attempt === null
            || $order === null
            || $order->public_id !== $grant['order_public_id']
            || ! $order->payment_method->isOnline()
            || $attempt->method !== $order->payment_method) {
            return response()->json([
                'code' => 'invalid_payment_return_token',
                'message' => 'The payment return token is invalid or expired.',
            ], 401);
        }

        $order->load('items');
        $latestAttempt = $order->paymentAttempts()->latest('id')->first() ?? $attempt;

        return (new PaymentReturnResource([
            'order' => $order,
            'attempt' => $latestAttempt,
            'return_token_is_for_latest_attempt' => $latestAttempt->is($attempt),
        ]))
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
