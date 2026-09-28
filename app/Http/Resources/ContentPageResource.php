<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $arabic = app()->getLocale() === 'ar';

        return [
            'title' => $arabic ? $this->title_ar : $this->title_en,
            'body' => $arabic ? $this->body_ar : $this->body_en,
        ];
    }
}
