<?php

namespace App\Http\Resources;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->resource['order'];
        $attempt = $this->resource['attempt'];

        return [
            'payment' => [
                'status' => $order->payment_status->value,
                'method' => $order->payment_method->value,
                'attemptStatus' => $attempt->status?->value,
                'resultCode' => $this->resultCode($order, $attempt->status),
                'paidAt' => $attempt->paid_at?->toISOString(),
                'expiresAt' => $order->payment_expires_at?->toISOString(),
            ],
            'order' => [
                'publicId' => $order->public_id,
                'orderNumber' => $order->order_number,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'customer' => [
                    'name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                ],
                'shipping' => [
                    'area' => [
                        'code' => $order->shipping_area_code,
                        'name' => app()->getLocale() === 'en'
                            ? $order->shipping_area_name_en
                            : $order->shipping_area_name_ar,
                    ],
                    'address' => $order->shipping_address,
                    'landmark' => $order->shipping_landmark,
                    'shippingFee' => (string) $order->shipping_fee,
                ],
                'items' => $order->items->map(fn ($item): array => [
                    'sku' => $item->sku,
                    'productName' => app()->getLocale() === 'en'
                        ? $item->product_name_en
                        : $item->product_name_ar,
                    'selectedOptions' => collect($item->options_snapshot)->map(fn (array $option): array => [
                        'name' => app()->getLocale() === 'en' ? $option['option_name_en'] : $option['option_name_ar'],
                        'value' => app()->getLocale() === 'en' ? $option['value_en'] : $option['value_ar'],
                    ])->values()->all(),
                    'quantity' => $item->quantity,
                    'unitPrice' => (string) $item->unit_price,
                    'lineTotal' => (string) $item->line_total,
                ])->values()->all(),
                'subtotal' => (string) $order->subtotal,
                'shippingFee' => (string) $order->shipping_fee,
                'total' => (string) $order->total,
                'createdAt' => $order->created_at?->toISOString(),
            ],
        ];
    }

    private function resultCode($order, ?PaymentAttemptStatus $status): string
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            return 'paid';
        }
        if ($order->status->value === 'cancelled') {
            return 'order_cancelled';
        }
        if ($order->payment_expires_at === null || $order->payment_expires_at->isPast()) {
            return 'payment_window_expired';
        }

        return match ($status) {
            PaymentAttemptStatus::RequiresReview, PaymentAttemptStatus::Submitting => 'requires_review',
            PaymentAttemptStatus::Failed => 'payment_failed_retryable',
            PaymentAttemptStatus::Expired => 'payment_window_expired',
            PaymentAttemptStatus::Pending, PaymentAttemptStatus::Created => 'payment_pending',
            default => 'payment_not_started',
        };
    }
}
