<?php

namespace App\Services;

final readonly class PaymentExpirationResult
{
    public function __construct(public string $status)
    {
    }
}
