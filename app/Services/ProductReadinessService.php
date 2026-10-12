<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ProductReadinessService
{
    public function isRequired(Product $product): bool
    {
        return $product->status === 'active'
            || ($product->published_at !== null && $product->published_at->isFuture());
    }

    public function assertReady(Product $product): void
    {
        $options = $product->options()->ordered()->get();
        $items = $product->sellableItems()->where('status', 'active')->orderBy('id')->get();

        if ($items->isEmpty()) {
            $this->invalid('sellable_items', 'A publishable product must have at least one active sellable item.');
        }

        if ($options->isEmpty()) {
            if ($items->count() !== 1) {
                $this->invalid('sellable_items', 'A product without active options must have exactly one active sellable item.');
            }

            $this->assertItemValues($items->first(), collect());

            return;
        }

        $optionIds = collect($options->modelKeys());
        $combinations = [];
        foreach ($items as $item) {
            $this->assertItemValues($item, $optionIds);
            $valueIds = $item->optionValues()->pluck('product_option_values.id')->map(fn ($id): int => (int) $id)->all();
            sort($valueIds, SORT_NUMERIC);
            $combination = implode(',', $valueIds);

            if (isset($combinations[$combination])) {
                $this->invalid("sellable_items.{$item->getKey()}.option_value_ids", 'This option value combination already exists.');
            }

            $combinations[$combination] = true;
        }
    }

    private function assertItemValues(SellableItem $item, Collection $activeOptionIds): void
    {
        $pivotIds = $item->optionValues()->withTrashed()->pluck('product_option_values.id')->map(fn ($id): int => (int) $id);
        if ($activeOptionIds->isEmpty()) {
            if ($pivotIds->isNotEmpty()) {
                $this->invalid("sellable_items.{$item->getKey()}.option_value_ids", 'A product without active options cannot use option values.');
            }

            return;
        }

        $values = ProductOptionValue::withTrashed()->whereIn('id', $pivotIds)->withTrashed()->get();
        $optionIds = $values->pluck('product_option_id');
        $complete = $values->count() === $activeOptionIds->count()
            && $pivotIds->unique()->count() === $pivotIds->count()
            && $optionIds->unique()->count() === $values->count()
            && $optionIds->diff($activeOptionIds)->isEmpty()
            && $values->every(fn (ProductOptionValue $value): bool => ! $value->trashed()
                && $value->option !== null
                && ! $value->option->trashed()
                && (int) $value->option->product_id === (int) $item->product_id);

        if (! $complete) {
            $this->invalid("sellable_items.{$item->getKey()}.option_value_ids", 'Every active sellable item must select one active value from each active product option.');
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
