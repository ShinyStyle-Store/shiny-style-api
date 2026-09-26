<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
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
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
