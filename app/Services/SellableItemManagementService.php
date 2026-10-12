<?php

namespace App\Services;

use App\Exceptions\ProductConversionConflictException;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellableItemManagementService
{
    public function create(Product $product, array $data): SellableItem
    {
        return DB::transaction(function () use ($product, $data): SellableItem {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $valueIds = array_map('intval', $data['option_value_ids'] ?? []);
            $this->assertCombination($lockedProduct, $valueIds);
            $this->assertStock($data['stock_quantity'], 0);

            $item = new SellableItem;
            $item->fill($data);
            $item->product_id = $lockedProduct->getKey();
            $item->reserved_quantity = 0;
            $item->combination_key = $this->combinationKey($valueIds);

            if (($item->is_default ?? false) === true) {
                $lockedProduct->sellableItems()->where('is_default', true)->update(['is_default' => false]);
            }

            $item->save();
            $item->optionValues()->sync($valueIds);
            $this->assertReadyIfRequired($lockedProduct);

            return $item;
        });
    }

    public function update(SellableItem $item, array $data): SellableItem
    {
        return DB::transaction(function () use ($item, $data): SellableItem {
            $lockedProduct = Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
            $locked = SellableItem::query()->whereKey($item->getKey())->where('product_id', $lockedProduct->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->superseded_at !== null) {
                throw new ProductConversionConflictException('variant_superseded', 'A superseded source item cannot be changed or reactivated.');
            }
            $hasValues = array_key_exists('option_value_ids', $data);
            $valueIds = $hasValues
                ? array_map('intval', $data['option_value_ids'])
                : $locked->optionValues()->pluck('product_option_values.id')->map(fn ($id): int => (int) $id)->all();

            if ($hasValues || ($data['status'] ?? $locked->status) === 'active') {
                $this->assertCombination($lockedProduct, $valueIds, $locked->getKey());
            }

            $stock = array_key_exists('stock_quantity', $data)
                ? (int) $data['stock_quantity']
                : (int) $locked->stock_quantity;
            $this->assertStock($stock, (int) $locked->reserved_quantity);

            $locked->fill($data);
            $locked->combination_key = $this->combinationKey($valueIds);
            if (($locked->is_default ?? false) === true) {
                $lockedProduct->sellableItems()->whereKeyNot($locked->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
            $locked->save();
            if ($hasValues) {
                $locked->optionValues()->sync($valueIds);
            }
            if ($hasValues || array_key_exists('status', $data) || array_key_exists('is_default', $data)) {
                $this->assertReadyIfRequired($lockedProduct);
            }

            return $locked;
        });
    }

    public function archive(Product $product, SellableItem $item): ?string
    {
        return DB::transaction(function () use ($product, $item): ?string {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $locked = SellableItem::query()->whereKey($item->getKey())->where('product_id', $lockedProduct->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->superseded_at !== null) {
                return 'variant_superseded';
            }
            if ((int) $locked->reserved_quantity > 0) {
                return 'variant_has_reservations';
            }
            $locked->delete();
            $this->assertReadyIfRequired($lockedProduct);

            return null;
        });
    }

    public function restore(Product $product, int $itemId): SellableItem|string
    {
        return DB::transaction(function () use ($product, $itemId): SellableItem|string {
            $lockedProduct = Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $item = SellableItem::withTrashed()->whereKey($itemId)->where('product_id', $lockedProduct->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedProduct->trashed()) {
                return 'product_archived';
            }
            if (! $item->trashed()) {
                return 'variant_not_archived';
            }
            if ($item->superseded_at !== null) {
                return 'variant_superseded';
            }
            $valueIds = $item->optionValues()->pluck('product_option_values.id')->map(fn ($id): int => (int) $id)->all();
            $this->assertCombination($lockedProduct, $valueIds, $item->getKey());
            $this->assertStock((int) $item->stock_quantity, (int) $item->reserved_quantity);
            if ($item->is_default && $lockedProduct->sellableItems()->where('is_default', true)->exists()) {
                return 'variant_default_conflict';
            }
            $item->status = 'inactive';
            $item->restore();
            $this->assertReadyIfRequired($lockedProduct);

            return $item;
        });
    }

    private function assertCombination(Product $product, array $valueIds, ?int $ignoreItemId = null): void
    {
        $valueIds = array_values(array_unique(array_map('intval', $valueIds)));
        $options = $product->options()->get();
        if ($options->isEmpty()) {
            if ($valueIds !== []) {
                throw ValidationException::withMessages(['option_value_ids' => 'This product has no selectable options.']);
            }

            return;
        }

        if (count($valueIds) !== $options->count()) {
            throw ValidationException::withMessages(['option_value_ids' => 'Exactly one value is required for every active product option.']);
        }

        $values = ProductOptionValue::query()
            ->whereIn('id', $valueIds)
            ->with('option')
            ->lockForUpdate()
            ->get();
        if ($values->count() !== count($valueIds) || $values->contains(fn (ProductOptionValue $value): bool => $value->option === null || $value->option->product_id !== $product->getKey())) {
            throw ValidationException::withMessages(['option_value_ids' => 'All selected values must belong to this product.']);
        }

        $optionIds = $values->pluck('product_option_id')->unique();
        if ($optionIds->count() !== $values->count() || $optionIds->diff($options->modelKeys())->isNotEmpty()) {
            throw ValidationException::withMessages(['option_value_ids' => 'Select exactly one value from every product option.']);
        }

        $key = $this->combinationKey($valueIds);
        $duplicate = SellableItem::query()
            ->where('product_id', $product->getKey())
            ->whereNull('deleted_at')
            ->where('combination_key', $key);
        if ($ignoreItemId !== null) {
            $duplicate->whereKeyNot($ignoreItemId);
        }
        if ($duplicate->exists()) {
            throw ValidationException::withMessages(['option_value_ids' => 'This option value combination already exists.']);
        }
    }

    private function assertStock(int $stock, int $reserved): void
    {
        if ($stock < $reserved) {
            throw ValidationException::withMessages(['stock_quantity' => 'Stock quantity cannot be lower than reserved quantity.']);
        }
    }

    private function assertReadyIfRequired(Product $product): void
    {
        if (app(ProductReadinessService::class)->isRequired($product)) {
            app(ProductReadinessService::class)->assertReady($product);
        }
    }

    private function combinationKey(array $valueIds): string
    {
        $valueIds = array_values(array_unique(array_map('intval', $valueIds)));
        sort($valueIds, SORT_NUMERIC);

        return implode(',', $valueIds);
    }
}
