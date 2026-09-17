<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'name' => $this->localizedName(),
            'type' => $this->type,
            'shipping_fee' => (string) $this->shipping_fee,
        ];
    }
}
