<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderLifecycleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellableItem;
use Illuminate\Support\Facades\DB;

class OrderLifecycleService
{
    /**
     * @return array<string, list<OrderStatus>>
     */
    private function transitionMap(): array
    {
        return [
            OrderStatus::PendingConfirmation->value => [OrderStatus::Confirmed, OrderStatus::Cancelled],
            OrderStatus::Confirmed->value => [OrderStatus::Preparing, OrderStatus::Shipped, OrderStatus::Cancelled],
            OrderStatus::Preparing->value => [OrderStatus::Shipped, OrderStatus::Cancelled],
            OrderStatus::Shipped->value => [OrderStatus::Delivered],
            OrderStatus::Delivered->value => [],
            OrderStatus::Cancelled->value => [],
        ];
    }

    public function transition(
        Order $order,
        OrderStatus $target,
        ?CancellationReason $reason = null,
        ?string $note = null,
    ): Order {
        $note = $this->normalizeNote($note);

        return DB::transaction(function () use ($order, $target, $reason, $note): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if ($lockedOrder === null) {
                throw new InvalidOrderLifecycleException('The order no longer exists.');
            }

            $this->validateCancellationMetadata($target, $reason, $note);

            if ($lockedOrder->status === $target) {
                if ($target === OrderStatus::Delivered) {
                    $this->validateDeliveryPaymentState($lockedOrder);
                }

                $this->validateRepeatedTarget($lockedOrder, $target, $reason, $note);

                return $lockedOrder->refresh();
            }

            $allowed = $this->transitionMap()[$lockedOrder->status->value] ?? [];
            if (! in_array($target, $allowed, true)) {
                throw new InvalidOrderLifecycleException('The requested order transition is not allowed.');
            }

            if ($target === OrderStatus::Delivered) {
                $this->validateDeliveryPaymentState($lockedOrder);
            }

            if ($target === OrderStatus::Shipped || $target === OrderStatus::Cancelled) {
                $quantities = $this->aggregateQuantities($lockedOrder);
                $items = $this->lockSellableItems($quantities);
                $this->validateInventory($items, $quantities, $target);
                $this->mutateInventory($items, $quantities, $target);
            }

            $this->applyOrderState($lockedOrder, $target, $reason, $note);

            if ($target === OrderStatus::Delivered
                && $lockedOrder->payment_method === PaymentMethod::CashOnDelivery
                && $lockedOrder->payment_status === PaymentStatus::Unpaid) {
                $lockedOrder->payment_status = PaymentStatus::Paid;
            }

            $lockedOrder->save();

            return $lockedOrder->refresh();
        });
    }

    public function markNoResponse(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if ($lockedOrder === null) {
                throw new InvalidOrderLifecycleException('The order no longer exists.');
            }

            if ($lockedOrder->contact_status !== ContactStatus::NoResponse) {
                $lockedOrder->contact_status = ContactStatus::NoResponse;
                $lockedOrder->save();
            }

            return $lockedOrder->refresh();
        });
    }

    private function aggregateQuantities(Order $order): array
    {
        $quantities = [];
        $items = OrderItem::query()->where('order_id', $order->getKey())->lockForUpdate()->get();

        foreach ($items as $item) {
            if ($item->quantity <= 0) {
                throw new InvalidOrderLifecycleException('Order item quantity must be positive.');
            }
            if ($item->sellable_item_id === null) {
                throw new InvalidOrderLifecycleException('Order item sellable reference is missing.');
            }

            $id = (int) $item->sellable_item_id;
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item->quantity;
        }

        ksort($quantities);

        return $quantities;
    }

    /** @param array<int, int> $quantities @return array<int, SellableItem> */
    private function lockSellableItems(array $quantities): array
    {
        if ($quantities === []) {
            return [];
        }

        $items = SellableItem::withTrashed()
            ->whereIn('id', array_keys($quantities))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SellableItem $item): int => $item->getKey())
            ->all();

        if (count($items) !== count($quantities)) {
            throw new InvalidOrderLifecycleException('One or more referenced sellable items no longer exist.');
        }

        return $items;
    }

    /** @param array<int, SellableItem> $items @param array<int, int> $quantities */
    private function validateInventory(array $items, array $quantities, OrderStatus $target): void
    {
        foreach ($quantities as $id => $quantity) {
            $item = $items[$id];
            if ($item->stock_quantity < 0 || $item->reserved_quantity < 0) {
                throw new InvalidOrderLifecycleException('Sellable item inventory cannot be negative.');
            }
            if ($item->reserved_quantity < $quantity) {
                throw new InvalidOrderLifecycleException('Sellable item reservation is insufficient.');
            }
            if ($target === OrderStatus::Shipped && $item->stock_quantity < $quantity) {
                throw new InvalidOrderLifecycleException('Sellable item stock is insufficient.');
            }
        }
    }

    /** @param array<int, SellableItem> $items @param array<int, int> $quantities */
    private function mutateInventory(array $items, array $quantities, OrderStatus $target): void
    {
        foreach ($quantities as $id => $quantity) {
            $item = $items[$id];
            $item->reserved_quantity -= $quantity;

            if ($target === OrderStatus::Shipped) {
                $item->stock_quantity -= $quantity;
            }

            $item->saveQuietly();
        }
    }

    private function applyOrderState(
        Order $order,
        OrderStatus $target,
        ?CancellationReason $reason,
        ?string $note,
    ): void {
        $order->status = $target;

        match ($target) {
            OrderStatus::Confirmed => $this->applyConfirmation($order),
            OrderStatus::Preparing => $order->preparing_at = now(),
            OrderStatus::Shipped => $order->shipped_at = now(),
            OrderStatus::Delivered => $order->delivered_at = now(),
            OrderStatus::Cancelled => $this->applyCancellation($order, $reason, $note),
            OrderStatus::PendingConfirmation => null,
        };
    }

    private function applyConfirmation(Order $order): void
    {
        $order->contact_status = ContactStatus::Responded;
        if ($order->last_contacted_at === null) {
            $order->last_contacted_at = now();
        }
        $order->confirmed_at = now();
    }

    private function applyCancellation(Order $order, ?CancellationReason $reason, ?string $note): void
    {
        $order->cancellation_reason = $reason;
        $order->cancellation_note = $note;
        $order->cancelled_at = now();
    }

    private function validateDeliveryPaymentState(Order $order): void
    {
        if ($order->payment_method !== PaymentMethod::CashOnDelivery) {
            return;
        }

        if (! in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Paid], true)
            || ($order->status === OrderStatus::Delivered && $order->payment_status !== PaymentStatus::Paid)) {
            throw new InvalidOrderLifecycleException('The COD payment state is inconsistent with delivery.');
        }
    }

    private function validateCancellationMetadata(
        OrderStatus $target,
        ?CancellationReason $reason,
        ?string $note,
    ): void {
        if ($target !== OrderStatus::Cancelled && ($reason !== null || $note !== null)) {
            throw new InvalidOrderLifecycleException('Cancellation metadata is only valid for cancellation.');
        }
        if ($target === OrderStatus::Cancelled && $reason === null) {
            throw new InvalidOrderLifecycleException('Cancellation reason is required.');
        }
        if ($reason === CancellationReason::Other && $note === null) {
            throw new InvalidOrderLifecycleException('A cancellation note is required for the other reason.');
        }
    }

    private function validateRepeatedTarget(
        Order $order,
        OrderStatus $target,
        ?CancellationReason $reason,
        ?string $note,
    ): void {
        if ($target === OrderStatus::Cancelled) {
            if ($order->cancellation_reason !== $reason || $order->cancellation_note !== $note) {
                throw new InvalidOrderLifecycleException('Repeated cancellation metadata does not match.');
            }

            if ($order->cancelled_at === null) {
                throw new InvalidOrderLifecycleException('The cancelled order has inconsistent lifecycle data.');
            }

            return;
        }

        $timestamp = match ($target) {
            OrderStatus::Confirmed => $order->confirmed_at,
            OrderStatus::Preparing => $order->preparing_at,
            OrderStatus::Shipped => $order->shipped_at,
            OrderStatus::Delivered => $order->delivered_at,
            OrderStatus::PendingConfirmation => true,
            OrderStatus::Cancelled => null,
        };

        if ($timestamp === null) {
            throw new InvalidOrderLifecycleException('The order has inconsistent lifecycle data.');
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : $note;
    }
}
