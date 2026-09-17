<?php

namespace Database\Factories;

use App\Models\ShippingArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingArea>
 */
class ShippingAreaFactory extends Factory
{
    protected $model = ShippingArea::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'parent_id' => null,
            'type' => 'governorate',
            'name_ar' => 'محافظة '.fake()->unique()->word(),
            'name_en' => fake()->city(),
            'shipping_fee' => fake()->randomFloat(2, 0, 250),
            'is_active' => true,
            'is_selectable' => true,
            'sort_order' => 0,
        ];
    }
}
