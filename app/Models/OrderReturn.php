<?php

namespace App\Models;

use App\Enums\OrderReturnKind;
use App\Enums\OrderReturnStatus;
use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderReturn extends Model
{
    use HasFactory;

    protected $table = 'order_returns';

    protected $fillable = [
        'order_id', 'kind', 'status', 'reason', 'note', 'recorded_by_user_id',
        'recorded_at', 'version', 'idempotency_key', 'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'kind' => OrderReturnKind::class,
            'status' => OrderReturnStatus::class,
            'reason' => ReturnReason::class,
            'recorded_at' => 'datetime',
            'version' => 'integer',
            'decision_response_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ReturnReceipt::class);
    }
}
