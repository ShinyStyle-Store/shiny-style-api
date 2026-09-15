<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOptionValue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $bedding = Category::updateOrCreate(
                ['slug' => 'bedding'],
                [
                    'name_en' => 'Bedding',
                    'name_ar' => 'مفروشات السرير',
                    'status' => 'active',
                    'sort_order' => 1,
                ],
            );

            $towels = Category::updateOrCreate(
                ['slug' => 'towels'],
                [
                    'name_en' => 'Towels',
                    'name_ar' => 'مناشف',
                    'status' => 'active',
                    'sort_order' => 2,
                ],
            );

            $duvet = Product::updateOrCreate(
                ['slug' => 'luxury-cotton-duvet'],
                [
                    'name_en' => 'Luxury Cotton Duvet',
                    'name_ar' => 'لحاف قطني فاخر',
                    'description_en' => 'Soft premium cotton duvet designed for everyday comfort.',
                    'description_ar' => 'لحاف من القطن الناعم عالي الجودة ومصمم للراحة اليومية.',
                    'features' => [
                        'Soft cotton fabric',
                        'Easy to clean',
                        'Suitable for daily use',
                    ],
                    'specifications' => [
                        'material' => 'Cotton',
                        'care' => 'Machine wash',
                    ],
                    'badge' => 'discount',
                    'status' => 'active',
                    'is_featured' => true,
                    'published_at' => null,
                ],
            );

            $duvet->categories()->syncWithoutDetaching([
                $bedding->getKey() => ['is_primary' => true],
            ]);

            $color = $duvet->options()->updateOrCreate(
                ['code' => 'color'],
                [
                    'name_en' => 'Color',
                    'name_ar' => 'اللون',
                    'sort_order' => 1,
                ],
            );

            $size = $duvet->options()->updateOrCreate(
                ['code' => 'size'],
                [
                    'name_en' => 'Size',
                    'name_ar' => 'المقاس',
                    'sort_order' => 2,
                ],
            );

            $grey = $color->values()->updateOrCreate(
                ['code' => 'grey'],
                [
                    'value_en' => 'Grey',
                    'value_ar' => 'رمادي',
                    'metadata' => ['hex' => '#808080'],
                    'sort_order' => 1,
                ],
            );

            $beige = $color->values()->updateOrCreate(
                ['code' => 'beige'],
                [
                    'value_en' => 'Beige',
                    'value_ar' => 'بيج',
                    'metadata' => ['hex' => '#D8C3A5'],
                    'sort_order' => 2,
                ],
            );

            $queen = $size->values()->updateOrCreate(
                ['code' => 'queen'],
                [
                    'value_en' => 'Queen',
                    'value_ar' => 'كوين',
                    'sort_order' => 1,
                ],
            );

            $king = $size->values()->updateOrCreate(
                ['code' => 'king'],
                [
                    'value_en' => 'King',
                    'value_ar' => 'كينج',
                    'sort_order' => 2,
                ],
            );

            $duvetItems = [
                [
                    'sku' => 'DUV-GRY-QN',
                    'price' => 299.00,
                    'original_price' => 380.00,
                    'stock_quantity' => 12,
                    'is_default' => true,
                    'sort_order' => 1,
                    'option_values' => [$grey, $queen],
                ],
                [
                    'sku' => 'DUV-GRY-KG',
                    'price' => 349.00,
                    'original_price' => 430.00,
                    'stock_quantity' => 8,
                    'is_default' => false,
                    'sort_order' => 2,
                    'option_values' => [$grey, $king],
                ],
                [
                    'sku' => 'DUV-BGE-QN',
                    'price' => 299.00,
                    'original_price' => 380.00,
                    'stock_quantity' => 6,
                    'is_default' => false,
                    'sort_order' => 3,
                    'option_values' => [$beige, $queen],
                ],
                [
                    'sku' => 'DUV-BGE-KG',
                    'price' => 349.00,
                    'original_price' => 430.00,
                    'stock_quantity' => 4,
                    'is_default' => false,
                    'sort_order' => 4,
                    'option_values' => [$beige, $king],
                ],
            ];

            foreach ($duvetItems as $item) {
                $sellableItem = $duvet->sellableItems()->updateOrCreate(
                    ['sku' => $item['sku']],
                    [
                        'price' => $item['price'],
                        'original_price' => $item['original_price'],
                        'stock_quantity' => $item['stock_quantity'],
                        'status' => 'active',
                        'is_default' => $item['is_default'],
                        'sort_order' => $item['sort_order'],
                    ],
                );

                $sellableItem->optionValues()->syncWithoutDetaching(
                    collect($item['option_values'])->mapWithKeys(
                        fn (ProductOptionValue $value): array => [$value->getKey() => []],
                    )->all(),
                );
            }

            $towel = Product::updateOrCreate(
                ['slug' => 'premium-cotton-towel'],
                [
                    'name_en' => 'Premium Cotton Towel',
                    'name_ar' => 'منشفة قطنية فاخرة',
                    'description_en' => 'Absorbent premium cotton towel for everyday use.',
                    'description_ar' => 'منشفة قطنية عالية الامتصاص للاستخدام اليومي.',
                    'features' => [
                        'Highly absorbent',
                        'Soft cotton',
                    ],
                    'specifications' => [
                        'material' => 'Cotton',
                    ],
                    'badge' => null,
                    'status' => 'active',
                    'is_featured' => false,
                    'published_at' => null,
                ],
            );

            $towel->categories()->syncWithoutDetaching([
                $towels->getKey() => ['is_primary' => true],
            ]);

            $towel->sellableItems()->updateOrCreate(
                ['sku' => 'TWL-STD-001'],
                [
                    'price' => 150.00,
                    'original_price' => null,
                    'stock_quantity' => 20,
                    'status' => 'active',
                    'is_default' => true,
                    'sort_order' => 1,
                ],
            );
        });
    }
}
