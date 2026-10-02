<?php

namespace App\Models;

use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'request_received_at', 'received_at', 'default_reason', 'note',
        'revision', 'recorded_by_user_id', 'idempotency_key', 'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'request_received_at' => 'datetime',
            'received_at' => 'datetime',
            'default_reason' => ReturnReason::class,
            'revision' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnReceiptItem::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ReturnCorrection::class)->orderBy('applied_revision');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
