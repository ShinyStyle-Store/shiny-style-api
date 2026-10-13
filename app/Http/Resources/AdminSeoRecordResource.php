<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSeoRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'locale' => $this->locale,
            'metaTitle' => $this->meta_title,
            'metaDescription' => $this->meta_description,
            'canonicalPath' => $this->canonical_path,
            'robots' => [
                'index' => $this->robots_index,
                'follow' => $this->robots_follow,
            ],
            'openGraph' => [
                'title' => $this->og_title,
                'description' => $this->og_description,
                'imageId' => $this->og_image_id,
            ],
        ];
    }
}
