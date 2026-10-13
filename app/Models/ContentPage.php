<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ContentPage extends Model
{
    protected $fillable = [
        'slug',
        'title_ar',
        'title_en',
        'body_ar',
        'body_en',
    ];

    public function seoRecords(): MorphMany
    {
        return $this->morphMany(SeoRecord::class, 'seoable');
    }
}
