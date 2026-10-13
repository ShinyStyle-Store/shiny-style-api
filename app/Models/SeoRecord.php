<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SeoRecord extends Model
{
    protected $fillable = [
        'locale',
        'meta_title',
        'meta_description',
        'canonical_path',
        'robots_index',
        'robots_follow',
        'og_title',
        'og_description',
        'og_image_id',
    ];

    protected function casts(): array
    {
        return [
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
        ];
    }

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    public function ogImage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'og_image_id');
    }
}
