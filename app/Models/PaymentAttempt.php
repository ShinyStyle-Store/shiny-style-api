<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $attempt): void {
            $attempt->public_id ??= (string) Str::ulid();
        });
    }

    protected $fillable = [
        'order_id',
        'public_id',
        'provider',
        'method',
        'status',
        'amount_minor',
        'currency',
        'merchant_reference',
        'idempotency_key',
        'request_fingerprint',
        'provider_intention_id',
        'provider_order_id',
        'provider_transaction_id',
        'integration_id',
        'failure_code',
        'failure_message',
        'expires_at',
        'paid_at',
        'provider_client_secret',
        'submission_claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'method' => PaymentMethod::class,
            'status' => PaymentAttemptStatus::class,
            'amount_minor' => 'integer',
            'integration_id' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'provider_client_secret' => 'encrypted',
            'submission_claimed_at' => 'datetime',
        ];
    }

    protected $hidden = ['provider_client_secret'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
