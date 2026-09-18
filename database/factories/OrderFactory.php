<?php

namespace Database\Factories;

use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\ShippingArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = '100.00';
        $shippingFee = '70.00';
        $shippingAreaCode = 'test-area-'.uniqid();
        $shippingArea = ShippingArea::factory()->state([
            'code' => $shippingAreaCode,
            'name_ar' => 'منطقة اختبار',
            'name_en' => 'Test Area',
        ]);

        return [
            'user_id' => User::factory(),
            'customer_name' => fake()->name(),
            'customer_phone' => '01'.fake()->numerify('#########'),
            'alternate_phone' => null,
            'customer_email' => fake()->safeEmail(),
            'shipping_area_id' => $shippingArea,
            'shipping_area_code' => $shippingAreaCode,
            'shipping_area_name_ar' => 'منطقة اختبار',
            'shipping_area_name_en' => 'Test Area',
            'shipping_address' => fake()->address(),
            'shipping_landmark' => null,
            'customer_note' => null,
            'currency' => 'EGP',
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'total' => '170.00',
            'status' => OrderStatus::PendingConfirmation,
            'contact_status' => ContactStatus::NotContacted,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
            'cancellation_reason' => null,
            'cancellation_note' => null,
            'cancelled_at' => null,
            'contact_note' => null,
            'last_contacted_at' => null,
            'confirmed_at' => null,
            'preparing_at' => null,
            'shipped_at' => null,
            'delivered_at' => null,
        ];
    }

    public function guest(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Cancelled,
        ]);
    }

    public function shipped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Shipped,
            'shipped_at' => now(),
        ]);
    }
}
