<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminStoreContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'supportPhone' => $this->support_phone,
            'supportWhatsapp' => $this->support_whatsapp,
            'supportEmail' => $this->support_email,
            'addressAr' => $this->address_ar,
            'addressEn' => $this->address_en,
            'googleMapsUrl' => $this->google_maps_url,
        ];
    }
}
