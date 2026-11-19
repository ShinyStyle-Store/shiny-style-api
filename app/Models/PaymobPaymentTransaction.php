<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymobPaymentTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'payment_attempt_id',
        'provider_transaction_id',
        'provider_order_id',
        'merchant_reference',
        'amount_minor',
        'currency',
        'integration_id',
        'provider_is_live',
        'status',
        'review_code',
        'success',
        'pending',
        'error_occurred',
        'is_refunded',
        'refunded_amount_minor',
        'is_voided',
        'source_type',
        'source_subtype',
        'provider_created_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount_minor' => 'integer',
            'integration_id' => 'integer',
            'provider_is_live' => 'boolean',
            'success' => 'boolean',
            'pending' => 'boolean',
            'error_occurred' => 'boolean',
            'is_refunded' => 'boolean',
            'refunded_amount_minor' => 'integer',
            'is_voided' => 'boolean',
            'provider_created_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }
}
