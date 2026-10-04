<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Support\ExactMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AdminDashboardService
{
    /** @return array<string, mixed> */
    public function overview(int $chartDays = 7): array
    {
        $now = CarbonImmutable::now('Africa/Cairo');
        $periodStart = $now->startOfMonth();
        $periodStartUtc = $periodStart->setTimezone('UTC');
        $nowUtc = $now->setTimezone('UTC');

        $ordersCreated = Order::query()
            ->whereBetween('created_at', [$periodStartUtc, $nowUtc])
            ->count();

        $delivered = DB::table('orders')
            ->where('status', OrderStatus::Delivered->value)
            ->whereBetween('delivered_at', [$periodStartUtc, $nowUtc])
            ->select('currency')
            ->selectRaw('COUNT(*) AS delivered_count')
            ->selectRaw('COALESCE(SUM(total), 0) AS delivered_value')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        $currencyTotals = $delivered->map(function (object $summary): array {
            $count = (int) $summary->delivered_count;
            $value = ExactMoney::toMinorUnits((string) $summary->delivered_value);

            return [
                'currency' => strtoupper((string) $summary->currency),
                'delivered_orders' => $count,
                'delivered_order_value' => ExactMoney::formatMinorUnits($value),
                'average_delivered_order_value' => $count === 0
                    ? '0.00'
                    : ExactMoney::formatMinorUnits($this->averageMinorUnits($value, $count)),
            ];
        })->values()->all();

        $currencyCount = count($currencyTotals);
        $currency = $currencyCount === 1 ? $currencyTotals[0]['currency'] : ($currencyCount === 0 ? 'EGP' : null);
        $deliveredCount = array_sum(array_column($currencyTotals, 'delivered_orders'));

        $activeProducts = Product::query()->active()->count();
        $unavailableActiveProducts = Product::query()
            ->active()
            ->whereDoesntHave('sellableItems', function ($query): void {
                $query->where('status', 'active')
                    ->whereColumn('stock_quantity', '>', 'reserved_quantity');
            })
            ->count();

        return [
            'period' => [
                'timezone' => 'Africa/Cairo',
                'start' => $periodStart->toIso8601String(),
                'end' => $now->toIso8601String(),
            ],
            'currency' => $currency,
            'metrics' => [
                'orders_created_this_month' => $ordersCreated,
                'delivered_orders' => $deliveredCount,
                'delivered_order_value' => $currencyCount === 1 ? $currencyTotals[0]['delivered_order_value'] : ($currencyCount === 0 ? '0.00' : null),
                'average_delivered_order_value' => $currencyCount === 1 ? $currencyTotals[0]['average_delivered_order_value'] : ($currencyCount === 0 ? '0.00' : null),
                'active_products' => $activeProducts,
                'unavailable_active_products' => $unavailableActiveProducts,
            ],
            'currency_totals' => $currencyTotals,
            'top_selling_products' => $this->topSellingProducts($periodStartUtc, $nowUtc),
            'orders_chart' => $this->ordersChart($now, $chartDays),
        ];
    }

    /** @return array{days: int, timezone: string, start_date: string, end_date: string, points: list<array{date: string, orders: int}>} */
    private function ordersChart(CarbonImmutable $now, int $chartDays): array
    {
        $chartStart = $now->startOfDay()->subDays($chartDays - 1);
        $selects = [];
        $bindings = [];

        for ($index = 0; $index < $chartDays; $index++) {
            $dayStartUtc = $chartStart->addDays($index)->startOfDay()->utc();
            $isToday = $index === $chartDays - 1;
            $dayEndUtc = $isToday
                ? $now->utc()
                : $chartStart->addDays($index + 1)->startOfDay()->utc();
            $operator = $isToday ? '<=' : '<';

            $selects[] = "SUM(CASE WHEN created_at >= ? AND created_at {$operator} ? THEN 1 ELSE 0 END) AS day_{$index}";
            $bindings[] = $dayStartUtc;
            $bindings[] = $dayEndUtc;
        }

        $counts = DB::table('orders')
            ->where('created_at', '>=', $chartStart->startOfDay()->utc())
            ->where('created_at', '<=', $now->utc())
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $points = [];
        for ($index = 0; $index < $chartDays; $index++) {
            $points[] = [
                'date' => $chartStart->addDays($index)->toDateString(),
                'orders' => (int) $counts->{"day_{$index}"},
            ];
        }

        return [
            'days' => $chartDays,
            'timezone' => 'Africa/Cairo',
            'start_date' => $chartStart->toDateString(),
            'end_date' => $now->toDateString(),
            'points' => $points,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function topSellingProducts(CarbonImmutable $periodStart, CarbonImmutable $now): array
    {
        $returnedQuantities = DB::table('return_receipt_items AS return_items')
            ->join('return_receipts AS receipts', 'receipts.id', '=', 'return_items.return_receipt_id')
            ->whereNull('receipts.reversed_at')
            ->select('return_items.order_item_id')
            ->selectRaw('SUM(return_items.effective_received_quantity) AS returned_quantity')
            ->groupBy('return_items.order_item_id');

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoinSub($returnedQuantities, 'returned_quantities', function ($join): void {
                $join->on('returned_quantities.order_item_id', '=', 'order_items.id');
            })
            ->where('orders.status', OrderStatus::Delivered->value)
            ->whereBetween('orders.delivered_at', [$periodStart, $now])
            ->select('order_items.product_id')
            ->selectRaw('CASE WHEN order_items.product_id IS NULL THEN order_items.id ELSE NULL END AS historical_order_item_id')
            ->selectRaw('MAX(order_items.product_name_ar) AS product_name_ar')
            ->selectRaw('MAX(order_items.product_name_en) AS product_name_en')
            ->selectRaw('SUM(order_items.quantity - COALESCE(returned_quantities.returned_quantity, 0)) AS net_units_sold')
            ->groupBy('order_items.product_id')
            ->groupByRaw('CASE WHEN order_items.product_id IS NULL THEN order_items.id ELSE NULL END')
            ->havingRaw('SUM(order_items.quantity - COALESCE(returned_quantities.returned_quantity, 0)) > 0')
            ->orderByDesc('net_units_sold')
            ->orderByRaw('CASE WHEN order_items.product_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('order_items.product_id')
            ->orderBy('historical_order_item_id')
            ->limit(5)
            ->get();

        $productIds = $rows->pluck('product_id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $products = $productIds->isEmpty()
            ? collect()
            : Product::withTrashed()
                ->with('primaryProductImage.mediaAsset')
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

        return $rows->map(function (object $row) use ($products): array {
            $product = $row->product_id === null ? null : $products->get((int) $row->product_id);

            return [
                'product' => $product,
                'product_id' => $product?->getKey(),
                'historical_order_item_id' => $row->historical_order_item_id === null ? null : (int) $row->historical_order_item_id,
                'name_ar' => $product?->name_ar ?? $row->product_name_ar,
                'name_en' => $product?->name_en ?? $row->product_name_en,
                'net_units_sold' => (int) $row->net_units_sold,
            ];
        })->all();
    }

    private function averageMinorUnits(string $minorUnits, int $count): string
    {
        return (string) intdiv((int) $minorUnits + intdiv($count, 2), $count);
    }
}
