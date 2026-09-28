<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreContact extends Model
{
    public const SINGLETON_KEY = 'default';

    protected $fillable = [
        'support_phone',
        'support_whatsapp',
        'support_email',
        'address_ar',
        'address_en',
        'google_maps_url',
    ];
}
