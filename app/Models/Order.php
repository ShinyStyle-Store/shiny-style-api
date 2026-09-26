<?php

namespace App\Models;

use App\Enums\CancellationReason;
use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'order_number',
        'idempotency_key',
        'request_fingerprint',
        'user_id',
        'customer_name',
        'customer_phone',
        'alternate_phone',
        'customer_email',
        'shipping_area_id',
        'shipping_area_code',
        'shipping_area_name_ar',
        'shipping_area_name_en',
        'shipping_address',
        'shipping_landmark',
        'customer_note',
        'currency',
        'subtotal',
        'shipping_fee',
        'total',
        'status',
        'contact_status',
        'payment_method',
        'payment_status',
        'payment_expires_at',
        'cancellation_reason',
        'cancellation_note',
        'cancelled_at',
        'contact_note',
        'last_contacted_at',
        'confirmed_at',
        'preparing_at',
        'shipped_at',
        'delivered_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->public_id ??= (string) Str::ulid();
        });

        static::created(function (self $order): void {
            if ($order->order_number === null) {
                $order->updateQuietly([
                    'order_number' => self::formatOrderNumber($order->getKey()),
                ]);
            }
        });
    }

    public static function formatOrderNumber(int $id): string
    {
        return 'SS-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'contact_status' => ContactStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'cancellation_reason' => CancellationReason::class,
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'last_contacted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'preparing_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'payment_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shippingArea(): BelongsTo
    {
        return $this->belongsTo(ShippingArea::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
