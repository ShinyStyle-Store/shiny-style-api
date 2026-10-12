<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductConversion;
use App\Models\ReturnInventoryTransfer;
use App\Models\ReturnInventoryTransferReversal;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\SellableItem;
use App\Services\ReturnInventoryTransferService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DedicatedPostgresDatabase;

#[Group('postgres-inventory')]
class PostgreSqlInventoryUpgradeTest extends PostgreSqlInventoryTestCase
{
    public function test_pre_transfer_schema_with_historical_data_upgrades_without_losing_history_and_unknown_baselines_stay_ineligible(): void
    {
        DedicatedPostgresDatabase::assertConfiguredAndConnected();
        $latestMigration = database_path('migrations/2026_10_12_000002_create_return_inventory_transfers_table.php');

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $latestMigration, '--realpath' => true, '--force' => true]));
        $this->assertFalse(Schema::hasColumn('product_conversions', 'return_restock_baseline'));

        $suffix = Str::lower(Str::random(8));
        $product = Product::query()->create([
            'slug' => 'upgrade-'.$suffix,
            'name_ar' => 'منتج تاريخي',
            'name_en' => 'Historical upgrade product',
            'status' => 'active',
            'published_at' => now()->subDay(),
        ]);
        $category = Category::query()->create([
            'slug' => 'upgrade-category-'.$suffix,
            'name_ar' => 'تصنيف تاريخي',
            'name_en' => 'Historical category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $source = SellableItem::query()->create([
            'product_id' => $product->id,
            'sku' => 'UPGRADE-SOURCE-'.$suffix,
            'price' => '41.00',
            'stock_quantity' => 2,
            'reserved_quantity' => 0,
            'status' => 'inactive',
            'is_default' => true,
            'superseded_at' => now()->subHour(),
        ]);
        $order = Order::factory()->shipped()->create();
        $snapshot = [['option_name_en' => 'Finish', 'value_en' => 'Old walnut']];
        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $source->id,
            'sku' => $source->sku,
            'quantity' => 1,
            'options_snapshot' => $snapshot,
        ]);
        $conversion = ProductConversion::query()->create([
            'product_id' => $product->id,
            'source_sellable_item_id' => $source->id,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'historical-conversion'),
            'context_token' => hash('sha256', 'old-context'),
            'old_stock' => 2,
            'allocated_stock' => 2,
            'stock_difference' => 0,
            'allocation' => [],
            'result' => ['historical' => true],
        ]);
        $receipt = ReturnReceipt::query()->create([
            'order_id' => $order->id,
            'received_at' => now()->subHour(),
            'default_reason' => 'other',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'historical-receipt'),
        ]);
        $receipt->forceFill(['creation_response_snapshot' => ['legacyReceiptId' => 'RR-HIST-001']])->save();
        $receiptItem = ReturnReceiptItem::query()->create([
            'return_receipt_id' => $receipt->id,
            'order_item_id' => $orderItem->id,
            'sellable_item_id' => $source->id,
            'original_quantity' => 1,
            'initial_received_quantity' => 1,
            'initial_restockable_quantity' => 1,
            'initial_reason' => 'other',
            'effective_received_quantity' => 1,
            'effective_restockable_quantity' => 1,
            'effective_reason' => 'other',
        ]);

        $this->assertSame(0, Artisan::call('migrate', ['--path' => $latestMigration, '--realpath' => true, '--force' => true]));

        $this->assertTrue(Schema::hasColumn('product_conversions', 'return_restock_baseline'));
        $this->assertDatabaseHas('product_conversions', ['id' => $conversion->id, 'return_restock_baseline' => null]);
        $this->assertSame($snapshot, $orderItem->fresh()->options_snapshot);
        $this->assertSame($source->id, (int) $receiptItem->fresh()->sellable_item_id);
        $this->assertTrue($receipt->fresh()->order->is($order));
        $this->assertTrue($receiptItem->fresh()->orderItem->is($orderItem));
        $this->assertSame(['legacyReceiptId' => 'RR-HIST-001'], $receipt->fresh()->creation_response_snapshot);
        $this->assertTrue($conversion->fresh()->product->is($product));
        $this->assertTrue($conversion->fresh()->sourceSellableItem->is($source));

        $eligibility = app(ReturnInventoryTransferService::class)->show($order->fresh(), $receipt->id, $receiptItem->id);
        $this->assertFalse($eligibility['eligibility_known']);
        $this->assertSame(0, $eligibility['eligible_quantity']);

        $indexes = collect(DB::select("select indexname from pg_indexes where schemaname = current_schema()"))
            ->pluck('indexname')->all();
        foreach ([
            'product_conversions_product_id_unique',
            'product_conversions_source_sellable_item_id_unique',
            'product_conversions_idempotency_key_unique',
            'return_inventory_transfers_idempotency_key_unique',
            'return_inventory_transfer_reversals_return_inventory_transfer_id_unique',
            'return_inventory_transfer_reversals_idempotency_key_unique',
        ] as $index) {
            $this->assertContains($index, $indexes);
        }

        $this->assertQueryRejected(fn () => ProductConversion::query()->create([
            'product_id' => $product->id,
            'source_sellable_item_id' => $source->id,
            'idempotency_key' => $conversion->idempotency_key,
            'request_fingerprint' => hash('sha256', 'duplicate'),
            'context_token' => hash('sha256', 'duplicate'),
            'old_stock' => 2,
            'allocated_stock' => 2,
            'stock_difference' => 0,
            'allocation' => [],
            'result' => [],
        ]));

        $target = SellableItem::query()->create([
            'product_id' => $product->id,
            'sku' => 'UPGRADE-TARGET-'.$suffix,
            'price' => '41.00',
            'stock_quantity' => 1,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => false,
        ]);
        $transferData = [
            'order_id' => $order->id,
            'return_receipt_id' => $receipt->id,
            'return_receipt_item_id' => $receiptItem->id,
            'source_sellable_item_id' => $source->id,
            'target_sellable_item_id' => $target->id,
            'quantity' => 1,
            'reason' => 'Constraint verification',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'transfer-1'),
            'source_stock_before' => 2,
            'source_stock_after' => 1,
            'target_stock_before' => 1,
            'target_stock_after' => 2,
        ];
        $transfer = ReturnInventoryTransfer::query()->create($transferData);
        $this->assertQueryRejected(fn () => ReturnInventoryTransfer::query()->create($transferData));

        $secondTransferData = $transferData;
        $secondTransferData['idempotency_key'] = (string) Str::uuid();
        $secondTransferData['request_fingerprint'] = hash('sha256', 'transfer-2');
        $secondTransfer = ReturnInventoryTransfer::query()->create($secondTransferData);
        $reversalKey = (string) Str::uuid();
        $reversalData = [
            'return_inventory_transfer_id' => $transfer->id,
            'reason' => 'Constraint verification',
            'idempotency_key' => $reversalKey,
            'request_fingerprint' => hash('sha256', 'reversal-1'),
            'source_stock_before' => 1,
            'source_stock_after' => 2,
            'target_stock_before' => 2,
            'target_stock_after' => 1,
        ];
        ReturnInventoryTransferReversal::query()->create($reversalData);
        $duplicateTransferReversal = $reversalData;
        $duplicateTransferReversal['idempotency_key'] = (string) Str::uuid();
        $this->assertQueryRejected(fn () => ReturnInventoryTransferReversal::query()->create($duplicateTransferReversal));

        $duplicateReversalKey = $reversalData;
        $duplicateReversalKey['return_inventory_transfer_id'] = $secondTransfer->id;
        $this->assertQueryRejected(fn () => ReturnInventoryTransferReversal::query()->create($duplicateReversalKey));
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Expected a PostgreSQL uniqueness constraint to reject the duplicate record.');
    }
}
