<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use App\Support\ExactMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class GuestOrderService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{order: Order, created: bool}
     */
    public function create(array $data, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint($data);

        try {
            return DB::transaction(fn (): array => $this->createWithinTransaction(
                $data,
                $idempotencyKey,
                $fingerprint,
            ));
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = Order::query()->where('idempotency_key', $idempotencyKey)->with(['shippingArea', 'items'])->first();
            if ($existing === null) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['The order could not be created.'],
                ]);
            }

            $this->assertFingerprintMatches($existing, $fingerprint);

            return ['order' => $existing, 'created' => false];
        }
    }

    /** @param array<string, mixed> $data */
    private function createWithinTransaction(array $data, string $idempotencyKey, string $fingerprint): array
    {
        $existing = Order::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->with(['shippingArea', 'items'])
            ->first();

        if ($existing !== null) {
            $this->assertFingerprintMatches($existing, $fingerprint);

            return ['order' => $existing, 'created' => false];
        }

        $shippingArea = ShippingArea::query()
            ->whereKey($data['shipping']['shipping_area_id'])
            ->where('is_active', true)
            ->where('is_selectable', true)
            ->whereNotNull('shipping_fee')
            ->lockForUpdate()
            ->first();

        if ($shippingArea === null) {
            throw ValidationException::withMessages([
                'shipping.shipping_area_id' => ['The selected shipping area is unavailable.'],
            ]);
        }

        try {
            $shippingFee = ExactMoney::toMinorUnits((string) $shippingArea->shipping_fee);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'shipping.shipping_area_id' => ['The selected shipping area is unavailable.'],
            ]);
        }

        $requestedItems = collect($data['items'])
            ->sortBy('sellable_item_id')
            ->values();
        $ids = $requestedItems->pluck('sellable_item_id')->map(fn ($id): int => (int) $id)->all();

        $sellableItems = SellableItem::query()
            ->whereIn('id', $ids)
            ->active()
            ->whereHas('product', fn ($query) => $query->visible())
            ->with(['product', 'optionValues.option'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SellableItem $item): int => $item->getKey());

        $missing = collect($ids)->first(fn (int $id): bool => ! $sellableItems->has($id));
        if ($missing !== null) {
            throw ValidationException::withMessages([
                'items' => ['One or more requested items are unavailable.'],
            ]);
        }

        $lines = [];
        $subtotal = '0';

        foreach ($requestedItems as $requestedItem) {
            /** @var SellableItem $sellableItem */
            $sellableItem = $sellableItems->get((int) $requestedItem['sellable_item_id']);
            $quantity = (int) $requestedItem['quantity'];

            if ($sellableItem->availableQuantity() < $quantity) {
                throw ValidationException::withMessages([
                    'items' => ['One or more requested items are unavailable.'],
                ]);
            }

            try {
                $unitPrice = ExactMoney::toMinorUnits((string) $sellableItem->price);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'items' => ['One or more requested items are unavailable.'],
                ]);
            }

            $lineTotal = ExactMoney::multiply($unitPrice, $quantity);
            $subtotal = ExactMoney::add($subtotal, $lineTotal);
            $lines[] = [
                'item' => $sellableItem,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'options_snapshot' => $this->optionsSnapshot($sellableItem),
            ];
        }

        $order = Order::create([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'user_id' => null,
            'customer_name' => $data['customer']['name'],
            'customer_phone' => $data['customer']['phone'],
            'alternate_phone' => $data['customer']['alternate_phone'] ?? null,
            'customer_email' => null,
            'shipping_area_id' => $shippingArea->getKey(),
            'shipping_area_code' => $shippingArea->code,
            'shipping_area_name_ar' => $shippingArea->name_ar,
            'shipping_area_name_en' => $shippingArea->name_en,
            'shipping_address' => $data['shipping']['address'],
            'shipping_landmark' => $data['shipping']['landmark'] ?? null,
            'customer_note' => $data['order_note'] ?? null,
            'currency' => 'EGP',
            'subtotal' => ExactMoney::formatMinorUnits($subtotal),
            'shipping_fee' => ExactMoney::formatMinorUnits($shippingFee),
            'total' => ExactMoney::formatMinorUnits(ExactMoney::add($subtotal, $shippingFee)),
            'status' => 'pending_confirmation',
            'contact_status' => 'not_contacted',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid',
        ]);

        foreach ($lines as $line) {
            /** @var SellableItem $sellableItem */
            $sellableItem = $line['item'];
            OrderItem::create([
                'order_id' => $order->getKey(),
                'product_id' => $sellableItem->product_id,
                'sellable_item_id' => $sellableItem->getKey(),
                'sku' => $sellableItem->sku,
                'product_name_ar' => $sellableItem->product->name_ar,
                'product_name_en' => $sellableItem->product->name_en,
                'options_snapshot' => $line['options_snapshot'],
                'unit_price' => ExactMoney::formatMinorUnits($line['unit_price']),
                'quantity' => $line['quantity'],
                'line_total' => ExactMoney::formatMinorUnits($line['line_total']),
            ]);

            $sellableItem->reserved_quantity += $line['quantity'];
            $sellableItem->saveQuietly();
        }

        $order->load(['shippingArea', 'items']);

        return ['order' => $order, 'created' => true];
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(array $data): string
    {
        $canonical = [
            'customer' => [
                'name' => $data['customer']['name'],
                'phone' => $data['customer']['phone'],
                'alternate_phone' => $data['customer']['alternate_phone'] ?? null,
            ],
            'shipping' => [
                'shipping_area_id' => (int) $data['shipping']['shipping_area_id'],
                'address' => $data['shipping']['address'],
                'landmark' => $data['shipping']['landmark'] ?? null,
            ],
            'items' => collect($data['items'])
                ->sortBy('sellable_item_id')
                ->map(fn (array $item): array => [
                    'sellable_item_id' => (int) $item['sellable_item_id'],
                    'quantity' => (int) $item['quantity'],
                ])->values()->all(),
            'payment_method' => $data['payment_method'],
            'order_note' => $data['order_note'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assertFingerprintMatches(Order $order, string $fingerprint): void
    {
        if ($order->request_fingerprint !== $fingerprint) {
            throw new IdempotencyConflictException('The Idempotency-Key was already used for a different order request.');
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['19', '23000', '23505'], true);
    }

    /** @return array<int, array<string, string>> */
    private function optionsSnapshot(SellableItem $sellableItem): array
    {
        return $sellableItem->optionValues
            ->sortBy([
                ['option.sort_order', 'asc'],
                ['option.id', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(fn ($value): array => [
                'option_name_ar' => $value->option->name_ar,
                'option_name_en' => $value->option->name_en,
                'value_ar' => $value->value_ar,
                'value_en' => $value->value_en,
            ])->values()->all();
    }
}
