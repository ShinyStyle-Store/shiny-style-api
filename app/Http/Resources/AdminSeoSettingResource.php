<?php

namespace App\Http\Resources;

use App\Support\SeoSettingCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSeoSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => SeoSettingCatalog::apiKey((string) $this->key),
            'locale' => $this->locale === '' ? null : $this->locale,
            'value' => $this->value_json,
        ];
    }
}
