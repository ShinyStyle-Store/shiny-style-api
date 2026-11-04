<?php

namespace App\Http\Resources;

use App\Support\ExactMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPaymentReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment_attempt_id' => $this->public_id,
            'order_number' => $this->order?->order_number,
            'amount' => ExactMoney::formatMinorUnits((string) $this->amount_minor),
            'currency' => $this->currency,
            'reason' => $this->failure_code,
            'status' => $this->status->value,
            'updated_at' => $this->updated_at?->toISOString(),
            'paymob_reference' => $this->provider_transaction_id
                ?? $this->provider_order_id
                ?? $this->merchant_reference,
        ];
    }
}
