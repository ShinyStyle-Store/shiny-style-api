<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPage extends Model
{
    protected $fillable = [
        'slug',
        'title_ar',
        'title_en',
        'body_ar',
        'body_en',
    ];
}
