<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymobWebhookException;
use App\Http\Controllers\Controller;
use App\Services\PaymobTransaction;
use App\Services\PaymobTransactionHmacVerifier;
use App\Services\PaymobTransactionWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PaymobWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymobTransactionHmacVerifier $verifier,
        PaymobTransactionWebhookService $service,
    ): JsonResponse {
        if (! $request->isJson()) {
            return response()->json(['message' => 'Invalid webhook request.'], 415);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || ($payload['type'] ?? null) !== 'TRANSACTION' || ! is_array($payload['obj'] ?? null)) {
            return response()->json(['message' => 'Invalid webhook request.'], 422);
        }

        try {
            $verifier->verify($payload['obj'], $request->query('hmac'));
            $transaction = PaymobTransaction::fromArray($payload['obj']);
            $result = $service->process($transaction);
        } catch (PaymobWebhookException $exception) {
            return response()->json(['message' => 'Invalid webhook request.'], $exception->httpStatus);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Webhook processing is temporarily unavailable.'], 500);
        }

        return response()->json(['received' => true], $result->httpStatus);
    }
}
