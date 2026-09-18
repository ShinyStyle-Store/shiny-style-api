<?php

namespace App\Services;

use App\Models\SellableItem;
use App\Models\ShippingArea;
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

            if ($sellableItem === null || $sellableItem->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    "items.{$index}.sellable_item_id" => ['The selected item is unavailable.'],
                ]);
            }

            $unitPrice = $this->moneyToMinorUnits((string) $sellableItem->price);
            $lineTotal = $this->multiplyIntegers($unitPrice, (string) $quantity);
            $subtotal = $this->addIntegers($subtotal, $lineTotal);

            $lines[] = [
                'sellable_item_id' => $sellableItem->getKey(),
                'sku' => $sellableItem->sku,
                'product_name' => $this->localizedValue(
                    $sellableItem->product->name_ar,
                    $sellableItem->product->name_en,
                ),
                'selected_options' => $this->selectedOptions($sellableItem),
                'quantity' => $quantity,
                'unit_price' => $this->formatMinorUnits($unitPrice),
                'line_total' => $this->formatMinorUnits($lineTotal),
            ];
        }

        $shippingFee = $this->moneyToMinorUnits((string) $shippingArea->shipping_fee);

        return [
            'currency' => 'EGP',
            'items' => $lines,
            'subtotal' => $this->formatMinorUnits($subtotal),
            'shipping_fee' => $this->formatMinorUnits($shippingFee),
            'total' => $this->formatMinorUnits($this->addIntegers($subtotal, $shippingFee)),
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

    private function moneyToMinorUnits(string $money): string
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $money, $matches)) {
            throw ValidationException::withMessages([
                'items' => ['A quoted price is invalid.'],
            ]);
        }

        $whole = ltrim($matches[1], '0') ?: '0';
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ltrim($whole.$fraction, '0') ?: '0';
    }

    private function formatMinorUnits(string $minorUnits): string
    {
        $minorUnits = ltrim($minorUnits, '0') ?: '0';
        $minorUnits = str_pad($minorUnits, 3, '0', STR_PAD_LEFT);

        $whole = ltrim(substr($minorUnits, 0, -2), '0') ?: '0';

        return $whole.'.'.substr($minorUnits, -2);
    }

    private function addIntegers(string $left, string $right): string
    {
        $result = '';
        $carry = 0;
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry
                + ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0);
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function multiplyIntegers(string $left, string $right): string
    {
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $digits = array_fill(0, strlen($left) + strlen($right), 0);

        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; $leftIndex--) {
            for ($rightIndex = strlen($right) - 1; $rightIndex >= 0; $rightIndex--) {
                $position = $leftIndex + $rightIndex + 1;
                $digits[$position] += (int) $left[$leftIndex] * (int) $right[$rightIndex];
            }
        }

        for ($index = count($digits) - 1; $index > 0; $index--) {
            $digits[$index - 1] += intdiv($digits[$index], 10);
            $digits[$index] %= 10;
        }

        return ltrim(implode('', $digits), '0') ?: '0';
    }

    private function isNegativeMoney(string $money): bool
    {
        return str_starts_with(trim($money), '-');
    }
}
