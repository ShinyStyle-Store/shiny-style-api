<?php

namespace App\Services;

use App\Models\SellableItem;
use App\Models\ShippingArea;
use App\Support\ExactMoney;
use Illuminate\Validation\ValidationException;

class CheckoutQuoteService
{
    /**
     * @param  array<int, array{sellable_item_id: int, quantity: int}>  $items
     * @return array<string, mixed>
     */
    public function quote(int $shippingAreaId, array $items): array
    {
        $shippingArea = ShippingArea::query()
            ->active()
            ->selectable()
            ->whereNotNull('shipping_fee')
            ->find($shippingAreaId);

        if ($shippingArea === null || $this->isNegativeMoney((string) $shippingArea->shipping_fee)) {
            throw ValidationException::withMessages([
                'shipping_area_id' => ['The selected shipping area is unavailable.'],
            ]);
        }

        $ids = array_map(
            static fn (array $item): int => (int) $item['sellable_item_id'],
            $items,
        );

        $sellableItems = SellableItem::query()
            ->active()
            ->whereIn('id', $ids)
            ->whereHas('product', fn ($query) => $query->visible())
            ->with([
                'product',
                'optionValues.option',
            ])
            ->get()
            ->keyBy(fn (SellableItem $item): int => $item->getKey());

        $lines = [];
        $subtotal = '0';

        foreach ($items as $index => $item) {
            $sellableItem = $sellableItems->get((int) $item['sellable_item_id']);
            $quantity = (int) $item['quantity'];

            if ($sellableItem === null || $sellableItem->availableQuantity() < $quantity) {
                throw ValidationException::withMessages([
                    "items.{$index}.sellable_item_id" => ['The selected item is unavailable.'],
                ]);
            }

            $unitPrice = ExactMoney::toMinorUnits((string) $sellableItem->price);
            $lineTotal = ExactMoney::multiply($unitPrice, $quantity);
            $subtotal = ExactMoney::add($subtotal, $lineTotal);

            $lines[] = [
                'sellable_item_id' => $sellableItem->getKey(),
                'sku' => $sellableItem->sku,
                'product_name' => $this->localizedValue(
                    $sellableItem->product->name_ar,
                    $sellableItem->product->name_en,
                ),
                'selected_options' => $this->selectedOptions($sellableItem),
                'quantity' => $quantity,
                'unit_price' => ExactMoney::formatMinorUnits($unitPrice),
                'line_total' => ExactMoney::formatMinorUnits($lineTotal),
            ];
        }

        $shippingFee = ExactMoney::toMinorUnits((string) $shippingArea->shipping_fee);

        return [
            'currency' => 'EGP',
            'items' => $lines,
            'subtotal' => ExactMoney::formatMinorUnits($subtotal),
            'shipping_fee' => ExactMoney::formatMinorUnits($shippingFee),
            'total' => ExactMoney::formatMinorUnits(ExactMoney::add($subtotal, $shippingFee)),
        ];
    }

    /** @return array<int, array{name: string, value: string}> */
    private function selectedOptions(SellableItem $sellableItem): array
    {
        return $sellableItem->optionValues
            ->sortBy([
                ['option.sort_order', 'asc'],
                ['option.id', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(fn ($value): array => [
                'name' => $this->localizedValue($value->option->name_ar, $value->option->name_en),
                'value' => $this->localizedValue($value->value_ar, $value->value_en),
            ])
            ->values()
            ->all();
    }

    private function localizedValue(?string $arabic, ?string $english): string
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : (string) $arabic;
        }

        return $arabic !== null && $arabic !== '' ? $arabic : (string) $english;
    }

    private function isNegativeMoney(string $money): bool
    {
        return str_starts_with(trim($money), '-');
    }
}
