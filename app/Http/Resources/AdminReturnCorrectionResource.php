<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReturnCorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'return_receipt_id' => $this->return_receipt_id,
            'expected_version' => (int) $this->expected_revision,
            'new_version' => (int) $this->applied_revision,
            'correction_reason' => $this->explanation,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'idempotency_key' => $this->idempotency_key,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'return_receipt_item_id' => $item->return_receipt_item_id,
                'previous_received_quantity' => (int) $item->previous_received_quantity,
                'previous_restockable_quantity' => (int) $item->previous_restockable_quantity,
                'previous_reason' => $item->previous_reason->value,
                'new_received_quantity' => (int) $item->new_received_quantity,
                'new_restockable_quantity' => (int) $item->new_restockable_quantity,
                'new_reason' => $item->new_reason->value,
                'new_note' => $item->new_note,
            ])->values()->all()),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
