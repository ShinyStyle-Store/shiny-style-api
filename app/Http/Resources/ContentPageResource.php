<?php

namespace App\Http\Resources;

use App\Services\SeoResolverService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $arabic = app()->getLocale() === 'ar';

        $title = $arabic ? $this->title_ar : $this->title_en;
        $body = $arabic ? $this->body_ar : $this->body_en;

        return [
            'title' => $title,
            'body' => $body,
            'seo' => app(SeoResolverService::class)->resolve($this->resource, true, [
                'title' => $title,
                'body' => $body,
            ]),
        ];
    }
}
