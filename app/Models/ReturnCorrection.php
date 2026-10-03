<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnCorrection extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_receipt_id', 'expected_revision', 'applied_revision', 'explanation',
        'recorded_by_user_id', 'idempotency_key', 'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'expected_revision' => 'integer',
            'applied_revision' => 'integer',
            'response_snapshot' => 'array',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ReturnReceipt::class, 'return_receipt_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnCorrectionItem::class)->orderBy('id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
