<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\PaymentAttemptException;
use App\Exceptions\PaymobConfigurationException;
use App\Exceptions\PaymobRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitiateCardPaymentRequest;
use App\Models\Order;
use App\Services\PaymobCardIntentionService;
use App\Services\PaymentAttemptService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function initiate(
        InitiateCardPaymentRequest $request,
        string $publicId,
        PaymentAttemptService $attempts,
        PaymobCardIntentionService $intentions,
    ): JsonResponse {
        if (! $request->hasValidSignature()) {
            return $this->error('invalid_signature', 'The payment access signature is invalid.', 403);
        }
        $order = Order::query()->where('public_id', $publicId)->first();
        if ($order === null) {
            return $this->error('order_not_found', 'Order not found.', 404);
        }
        if ($order->payment_method !== PaymentMethod::Card) {
            return $this->error('unsupported_payment_method', 'Card payment is not available for this order.', 422);
        }

        try {
            $attempt = $attempts->create($order, PaymentMethod::Card, $request->idempotencyKey());
            $result = $intentions->initiate($attempt);
        } catch (IdempotencyConflictException) {
            return $this->error('idempotency_conflict', 'The idempotency key conflicts with another payment request.', 409);
        } catch (PaymobConfigurationException) {
            return $this->error('payment_configuration_unavailable', 'Payment configuration is unavailable.', 503);
        } catch (PaymobRequestException $exception) {
            if ($exception->errorCode === 'provider_client_error') {
                return $this->error('payment_provider_rejected', 'The payment provider rejected the payment request.', 422);
            }
            $code = in_array($exception->errorCode, ['provider_timeout', 'provider_connection_error', 'provider_server_error'], true)
                ? 'payment_provider_unavailable'
                : 'payment_outcome_uncertain';
            return $this->error($code, 'The payment provider is temporarily unavailable.', 503);
        } catch (PaymentAttemptException $exception) {
            return $this->mapAttemptError($exception);
        }

        return response()->json([
            'paymentAttemptId' => $attempt->public_id,
            'status' => $result->status->value,
            'checkoutUrl' => $result->checkoutUrl,
            'expiresAt' => $result->expiresAt->toISOString(),
        ], $attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function status(\Illuminate\Http\Request $request, string $publicId): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->error('invalid_signature', 'The payment access signature is invalid.', 403);
        }
        $order = Order::query()->where('public_id', $publicId)->first();
        if ($order === null) {
            return $this->error('order_not_found', 'Order not found.', 404);
        }

        $attempt = $order->paymentAttempts()->latest('id')->first();
        $attemptStatus = $attempt?->status;
        $retryable = $order->payment_method === PaymentMethod::Card
            && $order->payment_status !== PaymentStatus::Paid
            && $order->status->value === 'pending_confirmation'
            && $order->payment_expires_at?->isFuture() === true
            && ! in_array($attemptStatus, [PaymentAttemptStatus::Pending, PaymentAttemptStatus::Submitting, PaymentAttemptStatus::Paid, PaymentAttemptStatus::RequiresReview], true);

        return response()->json([
            'orderPublicId' => $order->public_id,
            'orderStatus' => $order->status->value,
            'paymentStatus' => $order->payment_status->value,
            'paymentMethod' => $order->payment_method->value,
            'latestAttemptStatus' => $attemptStatus?->value,
            'paidAt' => $attempt?->paid_at?->toISOString(),
            'paymentExpiresAt' => $order->payment_expires_at?->toISOString(),
            'retryable' => $retryable,
            'resultCode' => $this->resultCode($order, $attemptStatus),
        ]);
    }

    private function mapAttemptError(PaymentAttemptException $exception): JsonResponse
    {
        $status = match ($exception->errorCode) {
            'order_already_paid', 'active_payment_attempt_exists', 'payment_attempt_not_retryable', 'payment_attempt_submission_ambiguous' => 409,
            'payment_window_expired', 'order_not_payable' => 422,
            'payment_outcome_uncertain' => 503,
            default => 422,
        };
        $code = match ($exception->errorCode) {
            'payment_attempt_submission_ambiguous' => 'payment_outcome_uncertain',
            default => $exception->errorCode,
        };

        return $this->error($code, 'The payment request could not be completed.', $status);
    }

    private function resultCode(Order $order, ?PaymentAttemptStatus $status): string
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            return 'paid';
        }
        if ($order->status->value === 'cancelled') {
            return 'order_cancelled';
        }
        if ($order->payment_expires_at === null || $order->payment_expires_at->isPast()) {
            return 'payment_window_expired';
        }
        return match ($status) {
            PaymentAttemptStatus::RequiresReview, PaymentAttemptStatus::Submitting => 'requires_review',
            PaymentAttemptStatus::Failed => 'payment_failed_retryable',
            PaymentAttemptStatus::Expired => 'payment_window_expired',
            PaymentAttemptStatus::Pending, PaymentAttemptStatus::Created => 'payment_pending',
            default => 'payment_not_started',
        };
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
