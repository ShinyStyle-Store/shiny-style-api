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
            'actorId' => $this->actor_id === null ? null : (string) $this->actor_id,
            'targetType' => $this->target_type,
            'targetId' => (string) $this->target_id,
            'action' => $this->action,
            'before' => $this->before_json,
            'after' => $this->after_json,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
