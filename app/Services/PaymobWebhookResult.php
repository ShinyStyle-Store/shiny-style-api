<?php

namespace App\Services;

final readonly class PaymobWebhookResult
{
    public function __construct(public int $httpStatus = 200)
    {
    }
}
