<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'sellable_item_id' => null,
            'sku' => 'ORDER-'.strtoupper(fake()->bothify('??####')),
            'product_name_ar' => 'منتج محفوظ',
            'product_name_en' => 'Snapshot Product',
            'options_snapshot' => [],
            'base_unit_price' => '100.00',
            'unit_price' => '100.00',
            'discount_amount' => '0.00',
            'offer_id' => null,
            'offer_discount_percentage' => null,
            'quantity' => 1,
            'line_total' => '100.00',
        ];
    }
}
