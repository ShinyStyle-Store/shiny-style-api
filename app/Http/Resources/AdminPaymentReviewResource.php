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
            'additional_transactions' => $this->whenLoaded('paymobTransactions', fn () => $this->paymobTransactions
                ->map(fn ($transaction): array => [
                    'transaction_id' => $transaction->provider_transaction_id,
                    'provider_order_id' => $transaction->provider_order_id,
                    'merchant_reference' => $transaction->merchant_reference,
                    'amount' => ExactMoney::formatMinorUnits((string) $transaction->amount_minor),
                    'currency' => $transaction->currency,
                    'integration_id' => $transaction->integration_id,
                    'status' => $transaction->status->value,
                    'reason' => $transaction->review_code,
                    'received_at' => $transaction->received_at?->toISOString(),
                ])->values()),
        ];
    }
}
