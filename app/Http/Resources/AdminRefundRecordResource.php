<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AdminRefundRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evidence = $this->whenLoaded('mediaAttachments', function (): ?array {
            $attachment = $this->mediaAttachments->sortByDesc('id')->first();
            $asset = $attachment?->mediaAsset;

            return $attachment === null || $asset === null ? null : [
                'attachment_id' => (int) $attachment->getKey(),
                'asset_public_id' => $asset->public_id,
                'url' => Storage::disk($asset->disk)->url($asset->path),
                'mime_type' => $asset->mime_type,
                'size_bytes' => (int) $asset->size_bytes,
                'created_at' => $attachment->created_at?->toISOString(),
            ];
        });

        return [
            'id' => (int) $this->getKey(),
            'public_id' => $this->public_id,
            'order_id' => (int) $this->order_id,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'transfer_method' => $this->transfer_method->value,
            'transferred_at' => $this->transferred_at?->toISOString(),
            'transaction_reference' => $this->transaction_reference,
            'note' => $this->note,
            'recorded_by_user_id' => $this->recorded_by_user_id === null ? null : (int) $this->recorded_by_user_id,
            'version' => (int) $this->revision,
            'evidence' => $evidence,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
