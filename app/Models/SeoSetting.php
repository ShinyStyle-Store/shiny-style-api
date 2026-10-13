<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSetting extends Model
{
    protected $fillable = [
        'key',
        'locale',
        'value_json',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'json',
        ];
    }
}
