<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReturnReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $originalOperation = (bool) ($this->original_operation ?? false);

        return [
            'id' => $this->getKey(),
            'order_id' => $this->order_id,
            'return_requested_at' => $this->request_received_at?->toISOString(),
            'items_received_at' => $this->received_at?->toISOString(),
            'default_reason' => $this->default_reason?->value,
            'note' => $this->note,
            'version' => $originalOperation ? 0 : (int) $this->revision,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'idempotency_key' => $this->idempotency_key,
            'return_summary' => $originalOperation ? $this->summary(true) : ($this->order_summary ?? $this->summary()),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->getKey(),
                'order_item_id' => $item->order_item_id,
                'sellable_item_id' => $item->sellable_item_id,
                'ordered_quantity' => (int) $item->original_quantity,
                'original_received_quantity' => (int) $item->initial_received_quantity,
                'original_restock_quantity' => (int) $item->initial_restockable_quantity,
                'original_reason' => $item->initial_reason->value,
                'original_note' => $item->initial_note,
                'current_received_quantity' => $originalOperation ? (int) $item->initial_received_quantity : (int) $item->effective_received_quantity,
                'current_restock_quantity' => $originalOperation ? (int) $item->initial_restockable_quantity : (int) $item->effective_restockable_quantity,
                'current_reason' => $originalOperation ? $item->initial_reason->value : $item->effective_reason->value,
                'current_note' => $originalOperation ? $item->initial_note : $item->effective_note,
            ])->values()->all()),
            'corrections' => $originalOperation ? [] : $this->whenLoaded('corrections', fn () => AdminReturnCorrectionResource::collection($this->corrections)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function summary(bool $original = false): string
    {
        if (! $this->relationLoaded('items')) {
            return 'partial';
        }

        $has = false;
        $full = true;
        foreach ($this->items as $item) {
            $received = $original ? (int) $item->initial_received_quantity : (int) $item->effective_received_quantity;
            $has = $has || $received > 0;
            if ($received < (int) $item->original_quantity) {
                $full = false;
            }
        }

        return ! $has ? 'none' : ($full ? 'full' : 'partial');
    }
}
