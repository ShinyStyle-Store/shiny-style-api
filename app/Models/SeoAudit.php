<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'target_type',
        'target_id',
        'locale',
        'action',
        'before_json',
        'after_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'json',
            'after_json' => 'json',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
