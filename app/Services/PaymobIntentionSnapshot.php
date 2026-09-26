<?php

namespace App\Services;

use Carbon\CarbonInterface;

final readonly class PaymobIntentionSnapshot
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $paymentAttemptId,
        public int $integrationId,
        public string $merchantReference,
        public CarbonInterface $expiresAt,
        public array $payload,
    ) {
    }
}
