<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'reason' => $this->reason->value,
            'note' => $this->note,
            'version' => (int) $this->version,
            'actor' => $this->recorded_by_user_id === null ? null : [
                'id' => (int) $this->recorded_by_user_id,
            ],
            'recorded_at' => $this->recorded_at?->toISOString(),
        ];
    }
}
