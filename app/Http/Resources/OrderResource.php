<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
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
                    'id' => $this->shippingArea->getKey(),
                    'code' => $this->shippingArea->code,
                    'name' => $this->localizedValue($this->shipping_area_name_ar, $this->shipping_area_name_en),
                ],
                'address' => $this->shipping_address,
                'landmark' => $this->shipping_landmark,
                'shipping_fee' => (string) $this->shipping_fee,
            ],
            'items' => $this->items->map(fn ($item): array => [
                'sellable_item_id' => $item->sellable_item_id,
                'sku' => $item->sku,
                'product_name' => $this->localizedValue($item->product_name_ar, $item->product_name_en),
                'selected_options' => collect($item->options_snapshot)->map(fn (array $option): array => [
                    'name' => $this->localizedValue($option['option_name_ar'], $option['option_name_en']),
                    'value' => $this->localizedValue($option['value_ar'], $option['value_en']),
                ])->values()->all(),
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
            ])->values()->all(),
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'order_note' => $this->customer_note,
            'created_at' => $this->created_at?->toISOString(),
        ];

        if ($this->payment_method->value === 'card') {
            $data['payment'] = [
                'required' => true,
                'method' => 'card',
                'status' => $this->payment_status->value,
                'expiresAt' => $this->payment_expires_at?->toISOString(),
                'initiateUrl' => URL::temporarySignedRoute(
                    'orders.payments.initiate',
                    now()->addMinutes((int) config('payments.initiation_url_minutes', 30)),
                    ['public_id' => $this->public_id],
                ),
                'statusUrl' => URL::temporarySignedRoute(
                    'orders.payments.status',
                    now()->addMinutes((int) config('payments.status_url_minutes', 1440)),
                    ['public_id' => $this->public_id],
                ),
            ];
        }

        return $data;
    }

    private function localizedValue(string $arabic, string $english): string
    {
        return app()->getLocale() === 'en' ? $english : $arabic;
    }
}
