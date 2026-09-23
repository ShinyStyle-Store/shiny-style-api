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

            return $this->saveOption($lockedProduct, $data);
        });
    }

    public function updateOption(ProductOption $option, array $data): ProductOption
    {
        return DB::transaction(function () use ($option, $data): ProductOption {
            $locked = ProductOption::query()->whereKey($option->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNotArchived($locked);
            $this->fillOption($locked, $data);
            $locked->save();

            return $locked;
        });
    }

    public function createValue(ProductOption $option, array $data): ProductOptionValue
    {
        return DB::transaction(function () use ($option, $data): ProductOptionValue {
            $locked = ProductOption::query()->whereKey($option->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNotArchived($locked);

            $value = new ProductOptionValue;
            $value->fill($data);
            $value->product_option_id = $locked->getKey();
            $this->fillValueNormalized($value);
            $value->save();

            return $value;
        });
    }

    public function updateValue(ProductOptionValue $value, array $data): ProductOptionValue
    {
        return DB::transaction(function () use ($value, $data): ProductOptionValue {
            $locked = ProductOptionValue::query()->whereKey($value->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNotArchived($locked);
            $locked->fill($data);
            $this->fillValueNormalized($locked);
            $locked->save();

            return $locked;
        });
    }

    public function archiveOption(ProductOption $option): ?string
    {
        return DB::transaction(function () use ($option): ?string {
            $locked = ProductOption::query()->whereKey($option->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->values()->withTrashed()->whereHas('sellableItems')->exists()) {
                return 'option_in_use';
            }
            $locked->delete();

            return null;
        });
    }

    public function restoreOption(int $optionId): ProductOption|string
    {
        return DB::transaction(function () use ($optionId): ProductOption|string {
            $option = ProductOption::withTrashed()->whereKey($optionId)->lockForUpdate()->firstOrFail();
            if (! $option->trashed()) {
                return 'option_not_archived';
            }
            $option->restore();

            return $option;
        });
    }

    public function archiveValue(ProductOptionValue $value): ?string
    {
        return DB::transaction(function () use ($value): ?string {
            $locked = ProductOptionValue::query()->whereKey($value->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->sellableItems()->exists()) {
                return 'value_in_use';
            }
            $locked->delete();

            return null;
        });
    }

    public function restoreValue(int $valueId): ProductOptionValue|string
    {
        return DB::transaction(function () use ($valueId): ProductOptionValue|string {
            $value = ProductOptionValue::withTrashed()->whereKey($valueId)->lockForUpdate()->firstOrFail();
            if (! $value->trashed()) {
                return 'value_not_archived';
            }
            $value->restore();

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
}
