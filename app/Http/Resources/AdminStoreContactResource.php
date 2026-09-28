<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminStoreContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'support_phone' => $this->support_phone,
            'support_whatsapp' => $this->support_whatsapp,
            'support_email' => $this->support_email,
            'address_ar' => $this->address_ar,
            'address_en' => $this->address_en,
            'google_maps_url' => $this->google_maps_url,
        ];
    }
}
