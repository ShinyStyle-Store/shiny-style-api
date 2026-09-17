<?php

namespace Database\Seeders;

use App\Models\ShippingArea;
use Illuminate\Database\Seeder;

class ShippingAreaSeeder extends Seeder
{
    /** Temporary testing/demo fees; replace when approved client prices exist. */
    private const AREAS = [
        'cairo' => ['name_ar' => 'القاهرة', 'name_en' => 'Cairo', 'shipping_fee' => '70.00', 'sort_order' => 1],
        'giza' => ['name_ar' => 'الجيزة', 'name_en' => 'Giza', 'shipping_fee' => '60.00', 'sort_order' => 2],
        'alexandria' => ['name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'shipping_fee' => '80.00', 'sort_order' => 3],
    ];

    public function run(): void
    {
        foreach (self::AREAS as $code => $attributes) {
            $area = ShippingArea::firstOrNew(['code' => $code]);
            $area->fill([
                'type' => 'governorate',
                'parent_id' => null,
                'name_ar' => $attributes['name_ar'],
                'name_en' => $attributes['name_en'],
                'is_active' => true,
                'is_selectable' => true,
                'sort_order' => $attributes['sort_order'],
            ]);

            // Never replace an existing fee; this keeps demo seeding safe for deployed data.
            if (! $area->exists) {
                $area->shipping_fee = $attributes['shipping_fee'];
            }

            $area->save();
        }
    }
}
