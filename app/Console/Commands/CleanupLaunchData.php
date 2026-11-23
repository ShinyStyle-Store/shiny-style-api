<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CleanupLaunchData extends Command
{
    protected $signature = 'maintenance:cleanup-launch-data
        {--list : List current orders, products, and categories without making any changes}
        {--all-orders : Target all orders in the database}
        {--order-ids= : Comma-separated order IDs to delete}
        {--product-ids= : Comma-separated fake product IDs to permanently delete}
        {--category-ids= : Comma-separated fake category IDs to permanently delete}
        {--restore-shipped-stock : Also restore stock_quantity for deleted shipped/delivered orders on remaining products}
        {--execute : Actually execute the cleanup (default is dry-run preview)}
        {--force : Skip confirmation prompt (required with --execute in production)}';

    protected $description = 'Safely inspect and permanently remove test orders, fake products, and fake categories before handover';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->renderInventoryList();
        }

        $allOrders = (bool) $this->option('all-orders');
        $orderIds = $this->parseIds($this->option('order-ids'));
        $productIds = $this->parseIds($this->option('product-ids'));
        $categoryIds = $this->parseIds($this->option('category-ids'));

        if ($orderIds === null || $productIds === null || $categoryIds === null) {
            $this->error('ID options must be comma-separated positive integers (e.g. --category-ids=5,6).');

            return self::FAILURE;
        }

        if ($allOrders && $orderIds !== []) {
            $this->error('Use either --all-orders or --order-ids, not both.');

            return self::FAILURE;
        }

        if (! $allOrders && $orderIds === [] && $productIds === [] && $categoryIds === []) {
            $this->warn('No cleanup targets selected. Pass --list to inspect records, or provide --all-orders / --order-ids / --product-ids / --category-ids.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $force = (bool) $this->option('force');

        if ($execute && app()->isProduction() && ! $force) {
            $this->error('Refusing to execute destructive cleanup in production without --force. Take a DB backup first, review the dry-run output, then re-run with --execute --force.');

            return self::FAILURE;
        }

        $plan = $this->buildPlan($allOrders, $orderIds, $productIds, $categoryIds);
        $this->renderPlan($plan, $execute);

        if ($plan['blockers'] !== []) {
            $this->newLine();
            $this->error('Cleanup blocked due to safety checks:');
            foreach ($plan['blockers'] as $blocker) {
                $this->line(' - '.$blocker);
            }

            return self::FAILURE;
        }

        if (! $execute) {
            $this->newLine();
            $this->info('DRY-RUN complete. No rows were modified. Re-run with --execute (and --force in production) to apply.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('This will PERMANENTLY delete the listed records. Have you taken a database backup?')) {
            $this->warn('Aborted. No rows were modified.');

            return self::SUCCESS;
        }

        $restoreShippedStock = (bool) $this->option('restore-shipped-stock');

        DB::transaction(function () use ($plan, $restoreShippedStock): void {
            $this->executePlan($plan, $restoreShippedStock);
        });

        $this->newLine();
        $this->info('Cleanup completed successfully inside a database transaction.');

        return self::SUCCESS;
    }

    private function renderInventoryList(): int
    {
        $orders = Order::query()
            ->orderBy('id')
            ->get(['id', 'order_number', 'status', 'payment_method', 'payment_status', 'total', 'created_at']);

        $this->info("Orders ({$orders->count()} total):");
        if ($orders->isNotEmpty()) {
            $this->table(
                ['ID', 'Order #', 'Status', 'Method', 'Payment', 'Total', 'Created At'],
                $orders->map(fn (Order $o): array => [
                    $o->getKey(),
                    $o->order_number,
                    $o->status->value,
                    $o->payment_method->value,
                    $o->payment_status->value,
                    (string) $o->total,
                    $o->created_at?->toDateTimeString() ?? '',
                ])->all(),
            );
        }

        $categories = Category::withTrashed()
            ->orderBy('id')
            ->get(['id', 'parent_id', 'slug', 'name_en', 'name_ar', 'status', 'deleted_at']);
        $categoryProductCounts = DB::table('category_product')
            ->selectRaw('category_id, COUNT(*) as cnt')
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id');

        $this->newLine();
        $this->info("Categories ({$categories->count()} total, including archived):");
        if ($categories->isNotEmpty()) {
            $this->table(
                ['ID', 'Parent ID', 'Slug', 'Name (EN)', 'Name (AR)', 'Products', 'Status', 'Archived'],
                $categories->map(fn (Category $c): array => [
                    $c->getKey(),
                    $c->parent_id ?? '-',
                    $c->slug,
                    $c->name_en,
                    $c->name_ar,
                    (int) ($categoryProductCounts[$c->getKey()] ?? 0),
                    (string) $c->status,
                    $c->deleted_at ? 'YES' : 'no',
                ])->all(),
            );
        }

        $products = Product::withTrashed()
            ->orderBy('id')
            ->get(['id', 'slug', 'name_en', 'name_ar', 'status', 'deleted_at']);
        $productOrderCounts = DB::table('order_items')
            ->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as cnt')
            ->groupBy('product_id')
            ->pluck('cnt', 'product_id');

        $this->newLine();
        $this->info("Products ({$products->count()} total, including archived):");
        if ($products->isNotEmpty()) {
            $this->table(
                ['ID', 'Slug', 'Name (EN)', 'Name (AR)', 'Order Items', 'Status', 'Archived'],
                $products->map(fn (Product $p): array => [
                    $p->getKey(),
                    $p->slug,
                    $p->name_en,
                    $p->name_ar,
                    (int) ($productOrderCounts[$p->getKey()] ?? 0),
                    (string) $p->status,
                    $p->deleted_at ? 'YES' : 'no',
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $requestedOrderIds
     * @param  list<int>  $requestedProductIds
     * @param  list<int>  $requestedCategoryIds
     * @return array<string, mixed>
     */
    private function buildPlan(
        bool $allOrders,
        array $requestedOrderIds,
        array $requestedProductIds,
        array $requestedCategoryIds,
    ): array {
        $blockers = [];

        $orders = $allOrders
            ? Order::query()->orderBy('id')->get()
            : ($requestedOrderIds !== [] ? Order::query()->whereIn('id', $requestedOrderIds)->orderBy('id')->get() : collect());
        $orderIds = $orders->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (! $allOrders) {
            foreach (array_diff($requestedOrderIds, $orderIds) as $missingId) {
                $blockers[] = "Order ID {$missingId} does not exist.";
            }
        }

        $products = $requestedProductIds !== []
            ? Product::withTrashed()->whereIn('id', $requestedProductIds)->orderBy('id')->get()
            : collect();
        $productIds = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_diff($requestedProductIds, $productIds) as $missingId) {
            $blockers[] = "Product ID {$missingId} does not exist.";
        }

        $categories = $requestedCategoryIds !== []
            ? Category::withTrashed()->whereIn('id', $requestedCategoryIds)->orderBy('id')->get()
            : collect();
        $categoryIds = $categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_diff($requestedCategoryIds, $categoryIds) as $missingId) {
            $blockers[] = "Category ID {$missingId} does not exist.";
        }

        $sellableItemIds = $productIds !== []
            ? SellableItem::withTrashed()->whereIn('product_id', $productIds)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];

        // Safety check: fake products must not be linked to orders that are NOT being deleted.
        if ($productIds !== []) {
            $remainingOrderLinks = DB::table('order_items')
                ->where(function (Builder $query) use ($productIds, $sellableItemIds): void {
                    $query->whereIn('product_id', $productIds);
                    if ($sellableItemIds !== []) {
                        $query->orWhereIn('sellable_item_id', $sellableItemIds);
                    }
                })
                ->when($orderIds !== [], fn (Builder $q) => $q->whereNotIn('order_id', $orderIds))
                ->count();

            if ($remainingOrderLinks > 0) {
                $blockers[] = "Selected product(s) are still referenced by {$remainingOrderLinks} order item(s) belonging to orders NOT selected for deletion.";
            }

            if ($sellableItemIds !== []) {
                $remainingTransfers = DB::table('return_inventory_transfers')
                    ->where(function (Builder $query) use ($sellableItemIds): void {
                        $query->whereIn('source_sellable_item_id', $sellableItemIds)
                            ->orWhereIn('target_sellable_item_id', $sellableItemIds);
                    })
                    ->when($orderIds !== [], fn (Builder $q) => $q->whereNotIn('order_id', $orderIds))
                    ->count();

                if ($remainingTransfers > 0) {
                    $blockers[] = "Selected product variant(s) are still referenced by {$remainingTransfers} return inventory transfer(s) on orders NOT selected for deletion.";
                }
            }
        }

        // Safety check: fake categories must not still have non-deleted products or child categories.
        if ($categoryIds !== []) {
            $remainingCategoryProducts = DB::table('category_product')
                ->whereIn('category_id', $categoryIds)
                ->when($productIds !== [], fn (Builder $q) => $q->whereNotIn('product_id', $productIds))
                ->get(['category_id', 'product_id']);

            if ($remainingCategoryProducts->isNotEmpty()) {
                $summary = $remainingCategoryProducts
                    ->groupBy('category_id')
                    ->map(fn ($rows, $catId): string => "category #{$catId} -> product IDs [".implode(',', $rows->pluck('product_id')->all()).']')
                    ->implode('; ');
                $blockers[] = "Selected category(ies) still have remaining products not in --product-ids: {$summary}.";
            }

            $remainingChildCategories = DB::table('categories')
                ->whereIn('parent_id', $categoryIds)
                ->whereNotIn('id', $categoryIds)
                ->pluck('id')
                ->all();

            if ($remainingChildCategories !== []) {
                $blockers[] = 'Selected category(ies) have child categories not in --category-ids: ['.implode(',', $remainingChildCategories).'].';
            }
        }

        // Compute inventory adjustments needed for remaining (non-deleted) sellable items.
        $reservedReleases = [];
        $shippedStockRestores = [];
        if ($orderIds !== []) {
            $activeReservationStatuses = [
                OrderStatus::PendingConfirmation->value,
                OrderStatus::Confirmed->value,
                OrderStatus::Preparing->value,
            ];
            $shippedStatuses = [
                OrderStatus::Shipped->value,
                OrderStatus::Delivered->value,
            ];

            $orderItemsForInventory = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.order_id', $orderIds)
                ->whereNotNull('order_items.sellable_item_id')
                ->when($sellableItemIds !== [], fn (Builder $q) => $q->whereNotIn('order_items.sellable_item_id', $sellableItemIds))
                ->get(['order_items.sellable_item_id', 'order_items.quantity', 'orders.status']);

            foreach ($orderItemsForInventory as $row) {
                $itemId = (int) $row->sellable_item_id;
                $qty = (int) $row->quantity;
                if (in_array($row->status, $activeReservationStatuses, true)) {
                    $reservedReleases[$itemId] = ($reservedReleases[$itemId] ?? 0) + $qty;
                } elseif (in_array($row->status, $shippedStatuses, true)) {
                    $shippedStockRestores[$itemId] = ($shippedStockRestores[$itemId] ?? 0) + $qty;
                }
            }
        }

        // Gather dependent counts for preview.
        $returnReceiptIds = $orderIds !== []
            ? DB::table('return_receipts')->whereIn('order_id', $orderIds)->pluck('id')->all()
            : [];
        $returnTransferIds = $orderIds !== []
            ? DB::table('return_inventory_transfers')->whereIn('order_id', $orderIds)->pluck('id')->all()
            : [];
        $returnCorrectionIds = $returnReceiptIds !== []
            ? DB::table('return_corrections')->whereIn('return_receipt_id', $returnReceiptIds)->pluck('id')->all()
            : [];
        $refundRecordIds = $orderIds !== []
            ? DB::table('refund_records')->whereIn('order_id', $orderIds)->pluck('id')->all()
            : [];
        $reviewImageIds = $productIds !== []
            ? DB::table('product_review_images')->whereIn('product_id', $productIds)->pluck('id')->all()
            : [];
        $optionIds = $productIds !== []
            ? DB::table('product_options')->whereIn('product_id', $productIds)->pluck('id')->all()
            : [];

        $counts = [
            'orders' => count($orderIds),
            'order_items' => $orderIds !== [] ? DB::table('order_items')->whereIn('order_id', $orderIds)->count() : 0,
            'payment_attempts' => $orderIds !== [] ? DB::table('payment_attempts')->whereIn('order_id', $orderIds)->count() : 0,
            'paymob_payment_transactions' => $orderIds !== [] ? DB::table('paymob_payment_transactions')->whereIn('order_id', $orderIds)->count() : 0,
            'order_returns' => $orderIds !== [] ? DB::table('order_returns')->whereIn('order_id', $orderIds)->count() : 0,
            'return_receipts' => count($returnReceiptIds),
            'return_inventory_transfers' => count($returnTransferIds),
            'refund_records' => count($refundRecordIds),
            'products' => count($productIds),
            'sellable_items' => count($sellableItemIds),
            'product_options' => count($optionIds),
            'product_review_images' => count($reviewImageIds),
            'product_conversions' => $productIds !== [] ? DB::table('product_conversions')->whereIn('product_id', $productIds)->count() : 0,
            'categories' => count($categoryIds),
        ];

        return [
            'order_ids' => $orderIds,
            'orders' => $orders,
            'product_ids' => $productIds,
            'products' => $products,
            'category_ids' => $categoryIds,
            'categories' => $categories,
            'sellable_item_ids' => $sellableItemIds,
            'return_receipt_ids' => $returnReceiptIds,
            'return_transfer_ids' => $returnTransferIds,
            'return_correction_ids' => $returnCorrectionIds,
            'refund_record_ids' => $refundRecordIds,
            'review_image_ids' => $reviewImageIds,
            'option_ids' => $optionIds,
            'reserved_releases' => $reservedReleases,
            'shipped_stock_restores' => $shippedStockRestores,
            'counts' => $counts,
            'blockers' => $blockers,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function renderPlan(array $plan, bool $execute): void
    {
        $mode = $execute ? 'EXECUTE' : 'DRY-RUN';
        $this->info("=== Launch Data Cleanup Plan [{$mode}] ===");

        $rows = [];
        foreach ($plan['counts'] as $table => $count) {
            if ($count > 0) {
                $rows[] = [$table, $count];
            }
        }
        if ($rows !== []) {
            $this->table(['Table', 'Rows to Delete'], $rows);
        }

        if ($plan['products']->isNotEmpty()) {
            $this->line('Target products: '.$plan['products']->map(fn (Product $p): string => "#{$p->getKey()} ({$p->slug})".($p->trashed() ? ' [archived]' : ''))->implode(', '));
        }
        if ($plan['categories']->isNotEmpty()) {
            $this->line('Target categories: '.$plan['categories']->map(fn (Category $c): string => "#{$c->getKey()} ({$c->slug})".($c->trashed() ? ' [archived]' : ''))->implode(', '));
        }

        if ($plan['reserved_releases'] !== []) {
            $this->warn('Will release reserved_quantity on remaining real sellable items: '.json_encode($plan['reserved_releases']));
        }
        if ($plan['shipped_stock_restores'] !== []) {
            $restoreFlag = (bool) $this->option('restore-shipped-stock');
            $this->warn(
                ($restoreFlag ? 'Will restore' : 'Shipped/delivered test orders touched stock on remaining items (pass --restore-shipped-stock to restore): ')
                .json_encode($plan['shipped_stock_restores']),
            );
        }
    }

    /** @param array<string, mixed> $plan */
    private function executePlan(array $plan, bool $restoreShippedStock): void
    {
        $orderIds = $plan['order_ids'];
        $productIds = $plan['product_ids'];
        $categoryIds = $plan['category_ids'];
        $sellableItemIds = $plan['sellable_item_ids'];
        $returnReceiptIds = $plan['return_receipt_ids'];
        $returnTransferIds = $plan['return_transfer_ids'];
        $returnCorrectionIds = $plan['return_correction_ids'];
        $refundRecordIds = $plan['refund_record_ids'];
        $reviewImageIds = $plan['review_image_ids'];
        $optionIds = $plan['option_ids'];

        // 1. Release inventory reservations (and optionally restore shipped stock) on remaining products.
        foreach ($plan['reserved_releases'] as $itemId => $qty) {
            $item = SellableItem::withTrashed()->whereKey($itemId)->lockForUpdate()->first();
            if ($item !== null) {
                $item->reserved_quantity = max(0, (int) $item->reserved_quantity - (int) $qty);
                $item->saveQuietly();
            }
        }
        if ($restoreShippedStock) {
            foreach ($plan['shipped_stock_restores'] as $itemId => $qty) {
                $item = SellableItem::withTrashed()->whereKey($itemId)->lockForUpdate()->first();
                if ($item !== null) {
                    $item->stock_quantity = (int) $item->stock_quantity + (int) $qty;
                    $item->saveQuietly();
                }
            }
        }

        // 2. Delete orders and all restricted child records in strict dependency order.
        if ($orderIds !== []) {
            if ($returnTransferIds !== []) {
                DB::table('return_inventory_transfer_reversals')
                    ->whereIn('return_inventory_transfer_id', $returnTransferIds)
                    ->delete();
                DB::table('return_inventory_transfers')
                    ->whereIn('id', $returnTransferIds)
                    ->delete();
            }

            if ($returnCorrectionIds !== []) {
                DB::table('return_correction_items')
                    ->whereIn('return_correction_id', $returnCorrectionIds)
                    ->delete();
                DB::table('return_corrections')
                    ->whereIn('id', $returnCorrectionIds)
                    ->delete();
            }

            if ($returnReceiptIds !== []) {
                DB::table('return_receipt_items')
                    ->whereIn('return_receipt_id', $returnReceiptIds)
                    ->delete();
                DB::table('return_receipts')
                    ->whereIn('id', $returnReceiptIds)
                    ->delete();
            }

            DB::table('order_returns')->whereIn('order_id', $orderIds)->delete();

            if ($refundRecordIds !== []) {
                DB::table('refund_record_corrections')
                    ->whereIn('refund_record_id', $refundRecordIds)
                    ->delete();
                DB::table('media_attachments')
                    ->where('mediable_type', 'refund_record')
                    ->whereIn('mediable_id', $refundRecordIds)
                    ->delete();
                DB::table('refund_records')
                    ->whereIn('id', $refundRecordIds)
                    ->delete();
            }

            DB::table('paymob_payment_transactions')->whereIn('order_id', $orderIds)->delete();
            DB::table('payment_attempts')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('orders')->whereIn('id', $orderIds)->delete();
        }

        // 3. Permanently delete fake products and all dependent records.
        if ($productIds !== []) {
            DB::table('product_conversions')
                ->where(function (Builder $query) use ($productIds, $sellableItemIds): void {
                    $query->whereIn('product_id', $productIds);
                    if ($sellableItemIds !== []) {
                        $query->orWhereIn('source_sellable_item_id', $sellableItemIds);
                    }
                })
                ->delete();

            DB::table('media_attachments')
                ->where('mediable_type', 'product')
                ->whereIn('mediable_id', $productIds)
                ->delete();

            if ($sellableItemIds !== []) {
                DB::table('media_attachments')
                    ->where('mediable_type', 'sellable_item')
                    ->whereIn('mediable_id', $sellableItemIds)
                    ->delete();
            }

            if ($reviewImageIds !== []) {
                DB::table('media_attachments')
                    ->where('mediable_type', 'product_review_image')
                    ->whereIn('mediable_id', $reviewImageIds)
                    ->delete();
                DB::table('product_review_images')->whereIn('id', $reviewImageIds)->delete();
            }

            DB::table('seo_records')
                ->where('seoable_type', 'product')
                ->whereIn('seoable_id', $productIds)
                ->delete();
            DB::table('seo_redirects')
                ->where('entity_type', 'product')
                ->whereIn('entity_id', $productIds)
                ->delete();

            if ($sellableItemIds !== []) {
                DB::table('sellable_item_option_values')
                    ->whereIn('sellable_item_id', $sellableItemIds)
                    ->delete();
                DB::table('sellable_items')->whereIn('id', $sellableItemIds)->delete();
            }

            if ($optionIds !== []) {
                DB::table('product_option_values')->whereIn('product_option_id', $optionIds)->delete();
                DB::table('product_options')->whereIn('id', $optionIds)->delete();
            }

            DB::table('category_product')->whereIn('product_id', $productIds)->delete();
            DB::table('offer_product')->whereIn('product_id', $productIds)->delete();
            DB::table('products')->whereIn('id', $productIds)->delete();
        }

        // 4. Permanently delete fake categories and their dependent records.
        if ($categoryIds !== []) {
            DB::table('media_attachments')
                ->where('mediable_type', 'category')
                ->whereIn('mediable_id', $categoryIds)
                ->delete();
            DB::table('seo_records')
                ->where('seoable_type', 'category')
                ->whereIn('seoable_id', $categoryIds)
                ->delete();
            DB::table('seo_redirects')
                ->where('entity_type', 'category')
                ->whereIn('entity_id', $categoryIds)
                ->delete();
            DB::table('category_product')->whereIn('category_id', $categoryIds)->delete();
            DB::table('categories')->whereIn('id', $categoryIds)->delete();
        }
    }

    /** @return list<int>|null */
    private function parseIds(mixed $raw): ?array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', (string) $raw) as $part) {
            $trimmed = trim($part);
            if ($trimmed === '' || preg_match('/^[1-9][0-9]*$/', $trimmed) !== 1) {
                return null;
            }
            $ids[] = (int) $trimmed;
        }

        return array_values(array_unique($ids));
    }
}
