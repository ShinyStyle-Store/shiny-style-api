<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $homeTextiles = $this->category('home-textiles', 'bedding', [
                'name_en' => 'Home Textiles',
                'name_ar' => 'منسوجات منزلية',
                'status' => 'active',
                'sort_order' => 1,
            ]);

            $kitchenAppliances = $this->category('kitchen-appliances', 'towels', [
                'name_en' => 'Kitchen Appliances',
                'name_ar' => 'أجهزة المطبخ',
                'status' => 'active',
                'sort_order' => 2,
            ]);

            $blanket = $this->product('soft-sofa-throw-blanket', 'luxury-cotton-duvet', [
                'name_en' => 'Soft Sofa Throw Blanket',
                'name_ar' => 'بطانية كنبة ناعمة',
                'description_en' => 'A soft throw blanket that adds warmth and comfort to any sofa.',
                'description_ar' => 'بطانية ناعمة تضيف الدفء والراحة إلى أي كنبة.',
                'features' => [
                    'Soft woven fabric',
                    'Lightweight and comfortable',
                    'Easy to care for',
                ],
                'specifications' => [
                    'material' => 'Cotton blend',
                    'use' => 'Sofa throw',
                ],
                'badge' => 'new',
                'status' => 'active',
                'is_featured' => true,
                'published_at' => now()->subDay(),
            ]);

            $blanket->categories()->sync([
                $homeTextiles->getKey() => ['is_primary' => true],
            ]);

            $color = $this->option($blanket, 'color', null, [
                'name_en' => 'Color',
                'name_ar' => 'اللون',
                'sort_order' => 1,
            ]);

            $size = $this->option($blanket, 'size', null, [
                'name_en' => 'Size',
                'name_ar' => 'المقاس',
                'sort_order' => 2,
            ]);

            $beige = $this->optionValue($color, 'beige', null, [
                'value_en' => 'Beige',
                'value_ar' => 'بيج',
                'metadata' => ['hex' => '#D8C3A5'],
                'sort_order' => 1,
            ]);

            $grey = $this->optionValue($color, 'grey', null, [
                'value_en' => 'Grey',
                'value_ar' => 'رمادي',
                'metadata' => ['hex' => '#808080'],
                'sort_order' => 2,
            ]);

            $medium = $this->optionValue($size, 'medium', 'queen', [
                'value_en' => 'Medium',
                'value_ar' => 'متوسط',
                'metadata' => null,
                'sort_order' => 1,
            ]);

            $large = $this->optionValue($size, 'large', 'king', [
                'value_en' => 'Large',
                'value_ar' => 'كبير',
                'metadata' => null,
                'sort_order' => 2,
            ]);

            $this->sellableItem($blanket, 'THROW-BEIGE-M', 'DUV-GRY-QN', [
                'price' => 650.00,
                'original_price' => 750.00,
                'stock_quantity' => 12,
                'status' => 'active',
                'is_default' => true,
                'sort_order' => 1,
            ])->optionValues()->sync([$beige->getKey(), $medium->getKey()]);

            $this->sellableItem($blanket, 'THROW-BEIGE-L', 'DUV-GRY-KG', [
                'price' => 750.00,
                'original_price' => 850.00,
                'stock_quantity' => 8,
                'status' => 'active',
                'is_default' => false,
                'sort_order' => 2,
            ])->optionValues()->sync([$beige->getKey(), $large->getKey()]);

            $this->sellableItem($blanket, 'THROW-GREY-M', 'DUV-BGE-QN', [
                'price' => 650.00,
                'original_price' => 750.00,
                'stock_quantity' => 10,
                'status' => 'active',
                'is_default' => false,
                'sort_order' => 3,
            ])->optionValues()->sync([$grey->getKey(), $medium->getKey()]);

            $this->sellableItem($blanket, 'THROW-GREY-L', 'DUV-BGE-KG', [
                'price' => 750.00,
                'original_price' => 850.00,
                'stock_quantity' => 6,
                'status' => 'active',
                'is_default' => false,
                'sort_order' => 4,
            ])->optionValues()->sync([$grey->getKey(), $large->getKey()]);

            $coffeeMachine = $this->product('espresso-coffee-machine', 'premium-cotton-towel', [
                'name_en' => 'Espresso Coffee Machine',
                'name_ar' => 'ماكينة قهوة إسبريسو',
                'description_en' => 'A compact espresso coffee machine for rich coffee at home.',
                'description_ar' => 'ماكينة قهوة إسبريسو صغيرة لتحضير قهوة غنية في المنزل.',
                'features' => [
                    'Compact countertop design',
                    'Fast espresso preparation',
                    'Easy to use and clean',
                ],
                'specifications' => [
                    'type' => 'Espresso machine',
                    'use' => 'Home coffee preparation',
                ],
                'badge' => null,
                'status' => 'active',
                'is_featured' => false,
                'published_at' => now()->subDay(),
            ]);

            $coffeeMachine->categories()->sync([
                $kitchenAppliances->getKey() => ['is_primary' => true],
            ]);

            $coffeeItem = $this->sellableItem($coffeeMachine, 'COFFEE-MACHINE-001', 'TWL-STD-001', [
                'price' => 8500.00,
                'original_price' => 9500.00,
                'stock_quantity' => 8,
                'status' => 'active',
                'is_default' => true,
                'sort_order' => 1,
            ]);

            $coffeeItem->optionValues()->sync([]);

            $this->media($blanket, [
                'provider' => 'cloudinary',
                'type' => 'image',
                'public_id' => 'img2_ponchv',
                'secure_url' => 'https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg',
                'sellable_item_id' => null,
                'alt_text_en' => 'Soft sofa throw blanket',
                'alt_text_ar' => 'بطانية كنبة ناعمة',
                'sort_order' => 1,
                'is_primary' => true,
            ]);

            $this->media($coffeeMachine, [
                'provider' => 'cloudinary',
                'type' => 'image',
                'public_id' => 'img1_vcatzv',
                'secure_url' => 'https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454185/img1_vcatzv.jpg',
                'sellable_item_id' => null,
                'alt_text_en' => 'Espresso coffee machine',
                'alt_text_ar' => 'ماكينة قهوة إسبريسو',
                'sort_order' => 1,
                'is_primary' => true,
            ]);
        });
    }

    private function category(string $slug, ?string $legacySlug, array $attributes): Category
    {
        $category = Category::withTrashed()->where('slug', $slug)->first();

        if (! $category && $legacySlug) {
            $category = Category::withTrashed()->where('slug', $legacySlug)->first();
        }

        $category ??= new Category;
        $category->fill([...$attributes, 'slug' => $slug]);
        $this->restoreAndSave($category);

        return $category;
    }

    private function product(string $slug, ?string $legacySlug, array $attributes): Product
    {
        $product = Product::withTrashed()->where('slug', $slug)->first();

        if (! $product && $legacySlug) {
            $product = Product::withTrashed()->where('slug', $legacySlug)->first();
        }

        $product ??= new Product;
        $product->fill([...$attributes, 'slug' => $slug]);
        $this->restoreAndSave($product);

        return $product;
    }

    private function option(Product $product, string $code, ?string $legacyCode, array $attributes): ProductOption
    {
        $option = $product->options()->where('code', $code)->first();

        if (! $option && $legacyCode) {
            $option = $product->options()->where('code', $legacyCode)->first();
        }

        $option ??= new ProductOption;
        $option->fill([...$attributes, 'product_id' => $product->getKey(), 'code' => $code]);
        $option->save();

        return $option;
    }

    private function optionValue(ProductOption $option, string $code, ?string $legacyCode, array $attributes): ProductOptionValue
    {
        $value = $option->values()->where('code', $code)->first();

        if (! $value && $legacyCode) {
            $value = $option->values()->where('code', $legacyCode)->first();
        }

        $value ??= new ProductOptionValue;
        $value->fill([...$attributes, 'product_option_id' => $option->getKey(), 'code' => $code]);
        $value->save();

        return $value;
    }

    private function sellableItem(Product $product, string $sku, ?string $legacySku, array $attributes): SellableItem
    {
        $item = SellableItem::withTrashed()->where('sku', $sku)->first();

        if (! $item && $legacySku) {
            $item = SellableItem::withTrashed()->where('sku', $legacySku)->first();
        }

        $item ??= new SellableItem;
        $item->fill([...$attributes, 'product_id' => $product->getKey(), 'sku' => $sku]);
        $this->restoreAndSave($item);

        return $item;
    }

    private function media(Product $product, array $attributes): ProductMedia
    {
        $media = ProductMedia::withTrashed()
            ->where('provider', $attributes['provider'])
            ->where('public_id', $attributes['public_id'])
            ->first() ?? new ProductMedia;

        $media->fill([...$attributes, 'product_id' => $product->getKey()]);
        $this->restoreAndSave($media);

        return $media;
    }

    private function restoreAndSave(Model $model): void
    {
        if (method_exists($model, 'trashed') && $model->trashed()) {
            $model->restore();
        }

        $model->save();
    }
}
