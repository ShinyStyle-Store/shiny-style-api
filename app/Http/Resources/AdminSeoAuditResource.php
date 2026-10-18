<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSeoAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'record_type' => $this->target_type,
            'record_id' => (string) $this->target_id,
            'locale' => $this->locale ?? 'ar',
            'changed_at' => $this->created_at?->toISOString(),
            'changed_by' => $this->actor?->name,
        ];
    }
}
