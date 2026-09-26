<?php

namespace App\Services;

use App\Enums\PaymentAttemptStatus;
use Carbon\CarbonInterface;

final readonly class PaymobIntentionResult
{
    public function __construct(
        public int $paymentAttemptId,
        public PaymentAttemptStatus $status,
        public string $providerIntentionId,
        public string $checkoutUrl,
        public CarbonInterface $expiresAt,
    ) {
    }
}
