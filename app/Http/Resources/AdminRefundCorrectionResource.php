<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRefundCorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'refund_record_id' => (int) $this->refund_record_id,
            'expected_version' => (int) $this->expected_revision,
            'new_version' => (int) $this->applied_revision,
            'reason' => $this->reason,
            'recorded_by_user_id' => $this->recorded_by_user_id === null ? null : (int) $this->recorded_by_user_id,
            'previous' => $this->snapshot($this->previous_snapshot),
            'new' => $this->snapshot($this->new_snapshot),
            'previous_media_attachment_id' => $this->previous_media_attachment_id,
            'new_media_attachment_id' => $this->new_media_attachment_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshot(array $snapshot): array
    {
        if (array_key_exists('revision', $snapshot)) {
            $snapshot['version'] = $snapshot['revision'];
            unset($snapshot['revision']);
        }

        return $snapshot;
    }
}
