<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReturnInventoryTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'order_id' => (int) $this->order_id,
            'return_receipt_id' => (int) $this->return_receipt_id,
            'return_receipt_item_id' => (int) $this->return_receipt_item_id,
            'source_sellable_item_id' => (int) $this->source_sellable_item_id,
            'target_sellable_item_id' => (int) $this->target_sellable_item_id,
            'quantity' => (int) $this->quantity,
            'reason' => $this->reason,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'idempotency_key' => $this->idempotency_key,
            'source_stock_before' => (int) $this->source_stock_before,
            'source_stock_after' => (int) $this->source_stock_after,
            'target_stock_before' => (int) $this->target_stock_before,
            'target_stock_after' => (int) $this->target_stock_after,
            'reversed' => $this->relationLoaded('reversal') && $this->reversal !== null,
            'reversal' => $this->whenLoaded('reversal', fn () => $this->reversal === null ? null : [
                'id' => $this->reversal->getKey(),
                'reason' => $this->reversal->reason,
                'recorded_by_user_id' => $this->reversal->recorded_by_user_id,
                'idempotency_key' => $this->reversal->idempotency_key,
                'source_stock_before' => (int) $this->reversal->source_stock_before,
                'source_stock_after' => (int) $this->reversal->source_stock_after,
                'target_stock_before' => (int) $this->reversal->target_stock_before,
                'target_stock_after' => (int) $this->reversal->target_stock_after,
                'created_at' => $this->reversal->created_at?->toISOString(),
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
