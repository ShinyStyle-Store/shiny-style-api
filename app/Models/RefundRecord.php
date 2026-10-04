<?php

namespace App\Models;

use App\Enums\MediaRole;
use App\Enums\RefundTransferMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class RefundRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'public_id', 'amount', 'currency', 'transfer_method', 'transferred_at',
        'transaction_reference', 'note', 'recorded_by_user_id', 'idempotency_key',
        'request_fingerprint', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_method' => RefundTransferMethod::class,
            'transferred_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->public_id ??= (string) Str::ulid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'mediable')
            ->where('role', MediaRole::REFUND_EVIDENCE_IMAGE);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(RefundRecordCorrection::class)->orderBy('id');
    }
}
