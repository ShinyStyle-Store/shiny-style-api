<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

final class PaymentExpirationService
{
    public function __construct(private readonly OrderLifecycleService $lifecycle)
    {
    }

    public function expire(Order $order): PaymentExpirationResult
    {
        return DB::transaction(function () use ($order): PaymentExpirationResult {
            // Payment lifecycle lock hierarchy: Order -> PaymentAttempts -> OrderItems -> SellableItems.
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if ($lockedOrder === null) {
                return new PaymentExpirationResult('skipped');
            }

            if (! $this->isEligible($lockedOrder)) {
                return new PaymentExpirationResult('skipped');
            }

            $attempts = PaymentAttempt::query()
                ->where('order_id', $lockedOrder->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($attempts->contains(fn (PaymentAttempt $attempt): bool => $attempt->status === PaymentAttemptStatus::RequiresReview
                || $attempt->status === PaymentAttemptStatus::Submitting)) {
                return new PaymentExpirationResult('review-required');
            }

            if ($attempts->contains(fn (PaymentAttempt $attempt): bool => $attempt->status === PaymentAttemptStatus::Paid)) {
                return new PaymentExpirationResult('skipped');
            }

            // The lifecycle service locks OrderItems and SellableItems in deterministic ID order.
            $this->lifecycle->transition(
                $lockedOrder,
                OrderStatus::Cancelled,
                CancellationReason::PaymentExpired,
                null,
            );

            foreach ($attempts as $attempt) {
                if (in_array($attempt->status, [PaymentAttemptStatus::Created, PaymentAttemptStatus::Pending], true)) {
                    $attempt->forceFill(['status' => PaymentAttemptStatus::Expired])->save();
                }
            }

            return new PaymentExpirationResult('expired');
        });
    }

    private function isEligible(Order $order): bool
    {
        return $order->payment_method->isOnline()
            && ! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)
            && $order->status === OrderStatus::PendingConfirmation
            && $order->payment_expires_at !== null
            && $order->payment_expires_at->lte(now());
    }
}
