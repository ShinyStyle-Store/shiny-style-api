<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'contact_status' => $this->contact_status->value,
            'payment_method' => $this->payment_method->value,
            'payment_status' => $this->payment_status->value,
            'currency' => $this->currency,
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'alternate_phone' => $this->alternate_phone,
            ],
            'shipping_area' => [
                'id' => $this->shipping_area_id,
                'code' => $this->shipping_area_code,
                'name' => $this->localizedValue($this->shipping_area_name_ar, $this->shipping_area_name_en),
            ],
            'item_count' => (int) ($this->items_count ?? 0),
            'total_quantity' => (int) ($this->items_sum_quantity ?? 0),
            'subtotal' => (string) $this->subtotal,
            'shipping_fee' => (string) $this->shipping_fee,
            'total' => (string) $this->total,
            'last_contacted_at' => $this->last_contacted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function localizedValue(?string $arabic, ?string $english): ?string
    {
        return app()->getLocale() === 'en'
            ? (($english !== null && $english !== '') ? $english : ($arabic !== '' ? $arabic : null))
            : (($arabic !== null && $arabic !== '') ? $arabic : ($english !== '' ? $english : null));
    }
}
