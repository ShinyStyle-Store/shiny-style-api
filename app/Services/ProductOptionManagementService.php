<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductOptionManagementService
{
    public function createOption(Product $product, array $data): ProductOption
    {
        return DB::transaction(function () use ($product, $data): ProductOption {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedProduct->status === 'active' && $lockedProduct->sellableItems()->exists()) {
                throw ValidationException::withMessages([
                    'product' => 'A new option cannot be added to an active product with existing sellable items.',
                ]);
            }

            $option = $this->saveOption($lockedProduct, $data);
            $this->assertReadyIfRequired($lockedProduct);

            return $option;
        });
    }

    public function updateOption(Product $product, ProductOption $option, array $data): ProductOption
    {
        return DB::transaction(function () use ($product, $option, $data): ProductOption {
            $lockedProduct = $this->lockProduct($product);
            $locked = ProductOption::query()->where('product_id', $lockedProduct->getKey())
                ->whereKey($option->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNotArchived($locked);
            $this->fillOption($locked, $data);
            $locked->save();

            return $locked;
        });
    }

    public function createValue(Product $product, ProductOption $option, array $data): ProductOptionValue
    {
        return DB::transaction(function () use ($product, $option, $data): ProductOptionValue {
            $lockedProduct = $this->lockProduct($product);
            $locked = ProductOption::query()->where('product_id', $lockedProduct->getKey())
                ->whereKey($option->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNotArchived($locked);

            $value = new ProductOptionValue;
            $value->fill($data);
            $value->product_option_id = $locked->getKey();
            $this->fillValueNormalized($value);
            $value->save();

            return $value;
        });
    }

    public function updateValue(Product $product, ProductOptionValue $value, array $data): ProductOptionValue
    {
        return DB::transaction(function () use ($product, $value, $data): ProductOptionValue {
            $lockedProduct = $this->lockProduct($product);
            $option = ProductOption::query()->where('product_id', $lockedProduct->getKey())
                ->whereKey($value->product_option_id)->lockForUpdate()->firstOrFail();
            $locked = ProductOptionValue::query()->where('product_option_id', $option->getKey())
                ->whereKey($value->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNotArchived($locked);
            $locked->fill($data);
            $this->fillValueNormalized($locked);
            $locked->save();

            return $locked;
        });
    }

    public function archiveOption(Product $product, ProductOption $option): ?string
    {
        return DB::transaction(function () use ($product, $option): ?string {
            $lockedProduct = $this->lockProduct($product);
            $locked = ProductOption::query()->where('product_id', $lockedProduct->getKey())
                ->whereKey($option->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->values()->withTrashed()->whereHas('sellableItems')->exists()) {
                return 'option_in_use';
            }
            $locked->delete();
            $this->assertReadyIfRequired($lockedProduct);

            return null;
        });
    }

    public function restoreOption(Product $product, int $optionId): ProductOption|string
    {
        return DB::transaction(function () use ($product, $optionId): ProductOption|string {
            $lockedProduct = $this->lockProduct($product);
            $option = ProductOption::withTrashed()->where('product_id', $lockedProduct->getKey())
                ->whereKey($optionId)->lockForUpdate()->firstOrFail();
            if (! $option->trashed()) {
                return 'option_not_archived';
            }
            $option->restore();
            if (app(ProductReadinessService::class)->isRequired($lockedProduct)) {
                app(ProductReadinessService::class)->assertReady($lockedProduct);
            }

            return $option;
        });
    }

    public function archiveValue(Product $product, ProductOptionValue $value): ?string
    {
        return DB::transaction(function () use ($product, $value): ?string {
            $lockedProduct = $this->lockProduct($product);
            $option = ProductOption::query()->where('product_id', $lockedProduct->getKey())
                ->whereKey($value->product_option_id)->lockForUpdate()->firstOrFail();
            $locked = ProductOptionValue::query()->where('product_option_id', $option->getKey())
                ->whereKey($value->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->sellableItems()->exists()) {
                return 'value_in_use';
            }
            $locked->delete();
            $this->assertReadyIfRequired($lockedProduct);

            return null;
        });
    }

    public function restoreValue(Product $product, int $valueId, int $optionId): ProductOptionValue|string
    {
        return DB::transaction(function () use ($product, $valueId, $optionId): ProductOptionValue|string {
            $lockedProduct = $this->lockProduct($product);
            $option = ProductOption::withTrashed()->where('product_id', $lockedProduct->getKey())
                ->whereKey($optionId)->lockForUpdate()->firstOrFail();
            $value = ProductOptionValue::withTrashed()->where('product_option_id', $option->getKey())
                ->whereKey($valueId)->lockForUpdate()->firstOrFail();
            if (! $value->trashed()) {
                return 'value_not_archived';
            }
            $value->restore();
            $this->assertReadyIfRequired($lockedProduct);

            return $value;
        });
    }

    private function saveOption(Product $product, array $data): ProductOption
    {
        $option = new ProductOption;
        $option->fill($data);
        $option->product_id = $product->getKey();
        $this->fillOptionNormalized($option);
        $option->save();

        return $option;
    }

    private function fillOption(ProductOption $option, array $data): void
    {
        $option->fill($data);
        $this->fillOptionNormalized($option);
    }

    private function fillOptionNormalized(ProductOption $option): void
    {
        $option->name_ar_normalized = trim((string) $option->name_ar);
        $option->name_en_normalized = mb_strtolower(trim((string) $option->name_en));
    }

    private function fillValueNormalized(ProductOptionValue $value): void
    {
        $value->value_ar_normalized = trim((string) $value->value_ar);
        $value->value_en_normalized = mb_strtolower(trim((string) $value->value_en));
    }

    private function assertNotArchived(ProductOption|ProductOptionValue $model): void
    {
        if ($model->trashed()) {
            throw ValidationException::withMessages([
                'resource' => 'Archived option resources cannot be modified.',
            ]);
        }
    }

    private function lockProduct(Product $product): Product
    {
        return Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertReadyIfRequired(Product $product): void
    {
        if (app(ProductReadinessService::class)->isRequired($product)) {
            app(ProductReadinessService::class)->assertReady($product);
        }
    }
}
