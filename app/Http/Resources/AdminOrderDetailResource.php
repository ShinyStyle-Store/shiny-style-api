<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderDetailResource extends JsonResource
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
            'shipping' => [
                'area' => [
                    'id' => $this->shipping_area_id,
                    'code' => $this->shipping_area_code,
                    'name' => $this->localizedValue($this->shipping_area_name_ar, $this->shipping_area_name_en),
                ],
                'address' => $this->shipping_address,
                'landmark' => $this->shipping_landmark,
                'shipping_fee' => (string) $this->shipping_fee,
            ],
            'items' => $this->items->map(fn ($item): array => [
                'sku' => $item->sku,
                'product_name' => $this->localizedValue($item->product_name_ar, $item->product_name_en),
                'selected_options' => collect($item->options_snapshot)->map(fn (array $option): array => [
                    'name' => $this->localizedValue($option['option_name_ar'] ?? null, $option['option_name_en'] ?? null),
                    'value' => $this->localizedValue($option['value_ar'] ?? null, $option['value_en'] ?? null),
                ])->values()->all(),
                'quantity' => (int) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
            ])->values()->all(),
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'order_note' => $this->customer_note,
            'contact_note' => $this->contact_note,
            'last_contacted_at' => $this->last_contacted_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason?->value,
            'cancellation_note' => $this->cancellation_note,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'preparing_at' => $this->preparing_at?->toISOString(),
            'shipped_at' => $this->shipped_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function localizedValue(?string $arabic, ?string $english): ?string
    {
        return app()->getLocale() === 'en'
            ? (($english !== null && $english !== '') ? $english : ($arabic !== '' ? $arabic : null))
            : (($arabic !== null && $arabic !== '') ? $arabic : ($english !== '' ? $english : null));
    }
}
