<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->resource['currency'],
            'items' => $this->resource['items'],
            'subtotal' => $this->resource['subtotal'],
            'shipping_fee' => $this->resource['shipping_fee'],
            'total' => $this->resource['total'],
        ];
    }
}
