<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRecordCorrection extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_record_id', 'expected_revision', 'applied_revision', 'reason',
        'recorded_by_user_id', 'idempotency_key', 'request_fingerprint',
        'previous_snapshot', 'new_snapshot', 'previous_media_attachment_id',
        'new_media_attachment_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_revision' => 'integer',
            'applied_revision' => 'integer',
            'previous_snapshot' => 'array',
            'new_snapshot' => 'array',
        ];
    }

    public function refundRecord(): BelongsTo
    {
        return $this->belongsTo(RefundRecord::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function previousMediaAttachment(): BelongsTo
    {
        return $this->belongsTo(MediaAttachment::class, 'previous_media_attachment_id');
    }

    public function newMediaAttachment(): BelongsTo
    {
        return $this->belongsTo(MediaAttachment::class, 'new_media_attachment_id');
    }
}
