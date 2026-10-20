<?php

namespace Tests\Feature\Database;

use App\Enums\OrderReturnKind;
use App\Enums\ReturnReason;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductConversion;
use App\Models\ProductOption;
use App\Models\ReturnInventoryTransfer;
use App\Models\ReturnInventoryTransferReversal;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use App\Models\User;
use App\Services\AdminProductConversionService;
use App\Services\OrderReturnDecisionService;
use App\Services\PhysicalReturnService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;

#[Group('postgres-inventory')]
class PostgreSqlInventoryConcurrencyTest extends PostgreSqlInventoryTestCase
{
    use DatabaseMigrations;

    private const WORKER_TIMEOUT_SECONDS = 20;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_conversion_and_order_creation_serialize_in_both_lock_orders(): void
    {
        $fixture = $this->simpleProduct();
        $context = app(AdminProductConversionService::class)->context($fixture['product'], $fixture['source']->id);
        $conversion = $this->conversionOperation($fixture, $context['contextToken']);
        $order = $this->orderOperation($fixture['source'], 2, $fixture['shipping_area']);

        [$conversionResult, $orderResult] = $this->runContendedPair($fixture['product']->id, $conversion, $order);
        $this->assertTrue($conversionResult['ok']);
        $this->assertFalse($orderResult['ok']);
        $this->assertSame('validation_failed', $orderResult['code']);
        $this->assertSame(1, ProductConversion::query()->where('product_id', $fixture['product']->id)->count());
        $this->assertSame(0, (int) $fixture['source']->fresh()->reserved_quantity);
        $this->assertNotNull($fixture['source']->fresh()->superseded_at);
        $this->assertSame(0, Order::query()->count());

        $second = $this->simpleProduct();
        $secondContext = app(AdminProductConversionService::class)->context($second['product'], $second['source']->id);
        $secondConversion = $this->conversionOperation($second, $secondContext['contextToken']);
        $secondOrder = $this->orderOperation($second['source'], 2, $second['shipping_area']);
        [$orderFirst, $conversionAfterOrder] = $this->runContendedPair($second['product']->id, $secondOrder, $secondConversion);

        $this->assertTrue($orderFirst['ok']);
        $this->assertFalse($conversionAfterOrder['ok']);
        $this->assertSame('product_has_reservations', $conversionAfterOrder['code']);
        $this->assertSame(0, ProductConversion::query()->where('product_id', $second['product']->id)->count());
        $this->assertSame(2, (int) $second['source']->fresh()->reserved_quantity);
        $this->assertSame(8, $second['source']->fresh()->availableQuantity());
        $this->assertSame(10, (int) $second['source']->fresh()->stock_quantity);
        $this->assertSame(1, Order::query()->whereHas('items', fn ($query) => $query->where('sellable_item_id', $second['source']->id))->count());
        $this->assertSame(0, SellableItem::query()->where('product_id', $second['product']->id)->where('sku', 'like', 'RACE-%')->count());
    }

    public function test_concurrent_orders_cannot_reserve_more_than_available_stock(): void
    {
        $fixture = $this->simpleProduct();
        $firstOrder = $this->orderOperation($fixture['source'], 6, $fixture['shipping_area']);
        $secondOrder = $this->orderOperation($fixture['source'], 6, $fixture['shipping_area']);

        [$firstResult, $secondResult] = $this->runContendedPair(
            $fixture['product']->id,
            $firstOrder,
            $secondOrder,
        );

        $this->assertTrue($firstResult['ok']);
        $this->assertFalse($secondResult['ok']);
        $this->assertSame('insufficient_stock', $secondResult['code']);
        $this->assertSame(6, $secondResult['requested_quantity']);
        $this->assertSame(4, $secondResult['available_quantity']);
        $this->assertSame(6, (int) $fixture['source']->fresh()->reserved_quantity);
        $this->assertSame(4, $fixture['source']->fresh()->availableQuantity());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_source_edit_serializes_with_conversion_and_stale_context_does_not_partially_convert(): void
    {
        $fixture = $this->simpleProduct();
        $context = app(AdminProductConversionService::class)->context($fixture['product'], $fixture['source']->id);
        $edit = [
            'type' => 'update_source', 'product_id' => $fixture['product']->id,
            'source_id' => $fixture['source']->id, 'stock_quantity' => 11,
        ];
        [$editResult, $conversionResult] = $this->runContendedPair(
            $fixture['product']->id,
            $edit,
            $this->conversionOperation($fixture, $context['contextToken']),
        );

        $this->assertTrue($editResult['ok']);
        $this->assertFalse($conversionResult['ok']);
        $this->assertSame('stale_context', $conversionResult['code']);
        $this->assertSame(11, (int) $fixture['source']->fresh()->stock_quantity);
        $this->assertSame(0, ProductConversion::query()->count());
        $this->assertSame(0, DB::table('product_options')->count());

    }

    public function test_concurrent_conversions_replay_matching_keys_and_reject_conflicts_and_second_sources(): void
    {
        $sameKeyFixture = $this->simpleProduct();
        $sameKeyContext = app(AdminProductConversionService::class)->context($sameKeyFixture['product'], $sameKeyFixture['source']->id);
        $sameKeyOperation = $this->conversionOperation($sameKeyFixture, $sameKeyContext['contextToken'], (string) Str::uuid());
        [$first, $replay] = $this->runContendedPair($sameKeyFixture['product']->id, $sameKeyOperation, $sameKeyOperation);
        $this->assertTrue($first['ok']);
        $this->assertTrue($replay['ok']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, ProductConversion::query()->where('product_id', $sameKeyFixture['product']->id)->count());
        $this->assertSame(10, (int) SellableItem::query()->where('product_id', $sameKeyFixture['product']->id)->whereNull('superseded_at')->sum('stock_quantity'));

        $sameKeyConflictFixture = $this->simpleProduct();
        $sameKeyConflictContext = app(AdminProductConversionService::class)->context($sameKeyConflictFixture['product'], $sameKeyConflictFixture['source']->id);
        $key = (string) Str::uuid();
        $original = $this->conversionOperation($sameKeyConflictFixture, $sameKeyConflictContext['contextToken'], $key);
        $differentData = $original;
        $differentData['payload']['variants'][0]['price'] = '26.00';
        [$originalResult, $conflict] = $this->runContendedPair($sameKeyConflictFixture['product']->id, $original, $differentData);
        $this->assertTrue($originalResult['ok']);
        $this->assertFalse($conflict['ok']);
        $this->assertSame('idempotency_key_conflict', $conflict['code']);
        $this->assertSame(1, ProductConversion::query()->where('product_id', $sameKeyConflictFixture['product']->id)->count());
        $this->assertSame(10, (int) SellableItem::query()->where('product_id', $sameKeyConflictFixture['product']->id)->whereNull('superseded_at')->sum('stock_quantity'));

        $differentKeyFixture = $this->simpleProduct();
        $differentKeyContext = app(AdminProductConversionService::class)->context($differentKeyFixture['product'], $differentKeyFixture['source']->id);
        [$winner, $alreadyConverted] = $this->runContendedPair(
            $differentKeyFixture['product']->id,
            $this->conversionOperation($differentKeyFixture, $differentKeyContext['contextToken']),
            $this->conversionOperation($differentKeyFixture, $differentKeyContext['contextToken']),
        );
        $this->assertTrue($winner['ok']);
        $this->assertFalse($alreadyConverted['ok']);
        $this->assertSame('already_converted', $alreadyConverted['code']);
        $this->assertSame(1, ProductConversion::query()->where('product_id', $differentKeyFixture['product']->id)->count());
        $this->assertSame(10, (int) SellableItem::query()->where('product_id', $differentKeyFixture['product']->id)->whereNull('superseded_at')->sum('stock_quantity'));
    }

    public function test_competing_transfers_recalculate_eligibility_and_matching_retries_apply_inventory_once(): void
    {
        $fixture = $this->returnedProduct();
        [$larger, $other] = $this->runContendedPair(
            $fixture['product']->id,
            $this->transferOperation($fixture, 2),
            $this->transferOperation($fixture, 2),
            'orders', $fixture['order']->id,
        );
        $this->assertTrue($larger['ok']);
        $this->assertFalse($other['ok']);
        $this->assertSame('return_inventory_eligible_quantity_insufficient', $other['code']);
        $this->assertSame(1, ReturnInventoryTransfer::query()->where('return_receipt_item_id', $fixture['receipt_item']->id)->count());
        $this->assertSame(1, (int) $fixture['source']->fresh()->stock_quantity);
        $this->assertSame(0, (int) $fixture['source']->fresh()->reserved_quantity);
        $this->assertSame(4, (int) $fixture['target']->fresh()->stock_quantity);
        $this->assertSame(0, (int) $fixture['target']->fresh()->reserved_quantity);
        $this->assertSame(4, $fixture['target']->fresh()->availableQuantity());
        $this->assertSame(13, (int) $fixture['source']->fresh()->stock_quantity
            + (int) $fixture['target']->fresh()->stock_quantity
            + (int) $fixture['targets'][1]->fresh()->stock_quantity);
        $this->assertHistoricalOrderUnchanged($fixture);

        $retryFixture = $this->returnedProduct();
        $retry = $this->transferOperation($retryFixture, 1, (string) Str::uuid());
        [$created, $replayed] = $this->runContendedPair($retryFixture['product']->id, $retry, $retry, 'orders', $retryFixture['order']->id);
        $this->assertTrue($created['ok']);
        $this->assertTrue($replayed['ok']);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame(1, ReturnInventoryTransfer::query()->where('return_receipt_item_id', $retryFixture['receipt_item']->id)->count());
        $this->assertSame(2, (int) $retryFixture['source']->fresh()->stock_quantity);
        $this->assertSame(3, (int) $retryFixture['target']->fresh()->stock_quantity);
        $this->assertHistoricalOrderUnchanged($retryFixture);
    }

    public function test_transfer_serializes_with_correction_and_receipt_reversal_in_both_orders(): void
    {
        $fixture = $this->returnedProduct();
        [$transfer, $correction] = $this->runContendedPair(
            $fixture['product']->id,
            $this->transferOperation($fixture, 2),
            $this->correctionOperation($fixture, 1),
            'orders', $fixture['order']->id,
        );
        $this->assertTrue($transfer['ok']);
        $this->assertFalse($correction['ok']);
        $this->assertSame('return_inventory_transfer_requires_reversal', $correction['code']);
        $this->assertSame(3, (int) $fixture['receipt_item']->fresh()->effective_restockable_quantity);
        $this->assertSame(0, (int) $fixture['receipt']->fresh()->revision);
        $this->assertSame(1, ReturnInventoryTransfer::query()->where('return_receipt_item_id', $fixture['receipt_item']->id)->count());

        $correctionFixture = $this->returnedProduct();
        [$correctionFirst, $insufficientTransfer] = $this->runContendedPair(
            $correctionFixture['product']->id,
            $this->correctionOperation($correctionFixture, 1),
            $this->transferOperation($correctionFixture, 2),
            'orders', $correctionFixture['order']->id,
        );
        $this->assertTrue($correctionFirst['ok']);
        $this->assertFalse($insufficientTransfer['ok']);
        $this->assertSame('return_inventory_eligible_quantity_insufficient', $insufficientTransfer['code']);
        $this->assertSame(1, (int) $correctionFixture['receipt_item']->fresh()->effective_restockable_quantity);
        $this->assertSame(0, ReturnInventoryTransfer::query()->where('return_receipt_item_id', $correctionFixture['receipt_item']->id)->count());
        $this->assertSame(1, (int) $correctionFixture['source']->fresh()->stock_quantity);
        $this->assertHistoricalOrderUnchanged($fixture);
        $this->assertHistoricalOrderUnchanged($correctionFixture);

        $reverseFixture = $this->returnedProduct();
        [$transferFirst, $receiptReverse] = $this->runContendedPair(
            $reverseFixture['product']->id,
            $this->transferOperation($reverseFixture, 2),
            $this->receiptReversalOperation($reverseFixture),
            'orders', $reverseFixture['order']->id,
        );
        $this->assertTrue($transferFirst['ok']);
        $this->assertFalse($receiptReverse['ok']);
        $this->assertSame('return_inventory_transfer_requires_reversal', $receiptReverse['code']);
        $this->assertNull($reverseFixture['receipt']->fresh()->reversed_at);
        $this->assertSame(1, (int) $reverseFixture['source']->fresh()->stock_quantity);
        $this->assertHistoricalOrderUnchanged($reverseFixture);

        $reverseFirstFixture = $this->returnedProduct();
        [$receiptFirst, $transferAfter] = $this->runContendedPair(
            $reverseFirstFixture['product']->id,
            $this->receiptReversalOperation($reverseFirstFixture),
            $this->transferOperation($reverseFirstFixture, 1),
            'orders', $reverseFirstFixture['order']->id,
        );
        $this->assertTrue($receiptFirst['ok']);
        $this->assertFalse($transferAfter['ok']);
        $this->assertSame('return_receipt_reversed', $transferAfter['code']);
        $this->assertNotNull($reverseFirstFixture['receipt']->fresh()->reversed_at);
        $this->assertSame(0, ReturnInventoryTransfer::query()->where('return_receipt_item_id', $reverseFirstFixture['receipt_item']->id)->count());
        $this->assertSame(0, (int) $reverseFirstFixture['source']->fresh()->stock_quantity);
        $this->assertHistoricalOrderUnchanged($reverseFirstFixture);
    }

    public function test_transfer_reversal_serializes_with_target_reservation_in_both_orders(): void
    {
        $fixture = $this->returnedProduct();
        $transfer = $this->createTransfer($fixture, 2);
        [$reserve, $reversal] = $this->runContendedPair(
            $fixture['product']->id,
            $this->orderOperation($fixture['target'], 3, $fixture['shipping_area']),
            $this->transferReversalOperation($fixture, $transfer),
            'products', $fixture['product']->id,
        );
        $this->assertTrue($reserve['ok']);
        $this->assertFalse($reversal['ok']);
        $this->assertSame('return_inventory_target_unavailable', $reversal['code']);
        $this->assertSame(4, (int) $fixture['target']->fresh()->stock_quantity);
        $this->assertSame(3, (int) $fixture['target']->fresh()->reserved_quantity);
        $this->assertSame(1, $fixture['target']->fresh()->availableQuantity());
        $this->assertSame(1, (int) $fixture['source']->fresh()->stock_quantity);
        $this->assertSame(0, ReturnInventoryTransferReversal::query()->where('return_inventory_transfer_id', $transfer->id)->count());

        $reverseFirstFixture = $this->returnedProduct();
        $reverseFirstTransfer = $this->createTransfer($reverseFirstFixture, 2);
        [$reversalFirst, $reservationAfter] = $this->runContendedPair(
            $reverseFirstFixture['product']->id,
            $this->transferReversalOperation($reverseFirstFixture, $reverseFirstTransfer),
            $this->orderOperation($reverseFirstFixture['target'], 3, $reverseFirstFixture['shipping_area']),
            'products', $reverseFirstFixture['product']->id,
        );
        $this->assertTrue($reversalFirst['ok']);
        $this->assertFalse($reservationAfter['ok']);
        $this->assertSame('validation_failed', $reservationAfter['code']);
        $this->assertSame(2, (int) $reverseFirstFixture['target']->fresh()->stock_quantity);
        $this->assertSame(0, (int) $reverseFirstFixture['target']->fresh()->reserved_quantity);
        $this->assertSame(2, $reverseFirstFixture['target']->fresh()->availableQuantity());
        $this->assertSame(3, (int) $reverseFirstFixture['source']->fresh()->stock_quantity);
        $this->assertSame(0, Order::query()->whereHas('items', fn ($query) => $query->where('sellable_item_id', $reverseFirstFixture['target']->id))->count());
        $this->assertSame(1, ReturnInventoryTransferReversal::query()->where('return_inventory_transfer_id', $reverseFirstTransfer->id)->count());
    }

    public function test_simultaneous_transfer_reversals_write_one_reversal_and_change_stock_once(): void
    {
        $fixture = $this->returnedProduct();
        $transfer = $this->createTransfer($fixture, 2);
        [$first, $second] = $this->runContendedPair(
            $fixture['product']->id,
            $this->transferReversalOperation($fixture, $transfer),
            $this->transferReversalOperation($fixture, $transfer),
            'orders', $fixture['order']->id,
        );
        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('return_inventory_transfer_already_reversed', $second['code']);
        $this->assertSame(1, ReturnInventoryTransferReversal::query()->where('return_inventory_transfer_id', $transfer->id)->count());
        $this->assertSame(3, (int) $fixture['source']->fresh()->stock_quantity);
        $this->assertSame(2, (int) $fixture['target']->fresh()->stock_quantity);
        $this->assertSame(0, (int) $fixture['source']->fresh()->reserved_quantity);
        $this->assertSame(0, (int) $fixture['target']->fresh()->reserved_quantity);
        $this->assertSame(13, (int) $fixture['source']->fresh()->stock_quantity
            + (int) $fixture['target']->fresh()->stock_quantity
            + (int) $fixture['targets'][1]->fresh()->stock_quantity);
        $this->assertHistoricalOrderUnchanged($fixture);
    }

    /** @return array{product: Product, source: SellableItem, shipping_area: ShippingArea} */
    private function simpleProduct(): array
    {
        $product = Product::query()->create([
            'slug' => 'pg-inventory-'.Str::lower(Str::random(10)),
            'name_ar' => 'منتج اختبار',
            'name_en' => 'PostgreSQL Inventory Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $category = Category::query()->create([
            'slug' => 'pg-category-'.Str::lower(Str::random(10)),
            'name_ar' => 'تصنيف اختبار',
            'name_en' => 'PostgreSQL Test Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $source = SellableItem::query()->create([
            'product_id' => $product->id,
            'sku' => 'PG-SOURCE-'.Str::upper(Str::random(8)),
            'price' => '25.00',
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [
            'product' => $product,
            'source' => $source,
            'shipping_area' => ShippingArea::factory()->create(['shipping_fee' => '5.00']),
        ];
    }

    /** @return array<string, mixed> */
    private function convertedProduct(): array
    {
        $fixture = $this->simpleProduct();
        $context = app(AdminProductConversionService::class)->context($fixture['product'], $fixture['source']->id);
        $conversion = app(AdminProductConversionService::class)->convert(
            $fixture['product'],
            $fixture['source']->id,
            $this->conversionPayload($context['contextToken']),
            (string) Str::uuid(),
        );
        $fixture['targets'] = SellableItem::query()
            ->where('product_id', $fixture['product']->id)
            ->where('status', 'active')
            ->whereNull('superseded_at')
            ->orderBy('sort_order')
            ->get();
        $fixture['target'] = $fixture['targets']->firstOrFail();
        $activeOptionIds = ProductOption::query()
            ->where('product_id', $fixture['product']->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->assertNotEmpty($activeOptionIds);
        foreach ($fixture['targets'] as $target) {
            $target->load('optionValues.option');
            $this->assertSame('active', $target->status);
            $this->assertFalse($target->trashed());
            $this->assertNull($target->superseded_at);
            $this->assertSame((int) $fixture['product']->id, (int) $target->product_id);
            $targetOptionIds = $target->optionValues
                ->map(fn ($value): int => (int) $value->product_option_id)
                ->sort()
                ->values()
                ->all();
            $this->assertSame($activeOptionIds, $targetOptionIds);
            $this->assertTrue($target->optionValues->every(fn ($value): bool =>
                ! $value->trashed()
                && $value->option !== null
                && (int) $value->option->product_id === (int) $fixture['product']->id
            ));
        }
        $fixture['conversion'] = $conversion['data'];

        return $fixture;
    }

    /** @return array<string, mixed> */
    private function returnedProduct(): array
    {
        $fixture = $this->convertedProduct();
        $order = Order::factory()->shipped()->create();
        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $fixture['product']->id,
            'sellable_item_id' => $fixture['source']->id,
            'sku' => $fixture['source']->sku,
            'quantity' => 3,
            'options_snapshot' => [['option_name_en' => 'Historical', 'value_en' => 'Snapshot']],
        ]);
        app(OrderReturnDecisionService::class)->create(
            $order,
            OrderReturnKind::DeliveryRefusal,
            ['reason' => ReturnReason::RefusedDelivery->value],
            (int) $this->actor->id,
            (string) Str::uuid(),
        );
        $return = app(PhysicalReturnService::class)->create($order, [
            'default_reason' => ReturnReason::RefusedDelivery->value,
            'items' => [[
                'order_item_id' => $orderItem->id,
                'received_quantity' => 3,
                'restockable_quantity' => 3,
            ]],
        ], (int) $this->actor->id, (string) Str::uuid());

        $fixture['order'] = $order->fresh();
        $fixture['order_item'] = $orderItem->fresh();
        $fixture['receipt'] = $return['receipt'];
        $fixture['receipt_item'] = $return['receipt']->items()->firstOrFail();
        $fixture['order_snapshot'] = $orderItem->fresh()->only([
            'sellable_item_id', 'sku', 'product_name_en', 'options_snapshot', 'unit_price', 'line_total',
        ]);
        $fixture['order_state'] = $order->fresh()->only(['status', 'payment_status', 'total']);

        return $fixture;
    }

    private function conversionOperation(array $fixture, string $contextToken, ?string $key = null): array
    {
        return [
            'type' => 'convert',
            'product_id' => $fixture['product']->id,
            'source_id' => $fixture['source']->id,
            'payload' => $this->conversionPayload($contextToken),
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ];
    }

    private function conversionPayload(string $contextToken): array
    {
        return [
            'context_token' => $contextToken,
            'options' => [[
                'ref' => 'size-option',
                'code' => 'size',
                'name_ar' => 'المقاس',
                'name_en' => 'Size',
                'sort_order' => 0,
                'values' => [
                    ['ref' => 'small', 'code' => 'small', 'value_ar' => 'صغير', 'value_en' => 'Small', 'sort_order' => 0],
                    ['ref' => 'large', 'code' => 'large', 'value_ar' => 'كبير', 'value_en' => 'Large', 'sort_order' => 1],
                ],
            ]],
            'variants' => [
                ['ref' => 'small-variant', 'sku' => 'PG-SMALL-'.Str::upper(Str::random(8)), 'price' => '25.00', 'stock_quantity' => 2, 'sort_order' => 0, 'option_value_refs' => ['small']],
                ['ref' => 'large-variant', 'sku' => 'PG-LARGE-'.Str::upper(Str::random(8)), 'price' => '25.00', 'stock_quantity' => 8, 'sort_order' => 1, 'option_value_refs' => ['large']],
            ],
            'default_variant_ref' => 'small-variant',
        ];
    }

    private function orderOperation(SellableItem $item, int $quantity, ShippingArea $area): array
    {
        return [
            'type' => 'order',
            'product_id' => $item->product_id,
            'idempotency_key' => (string) Str::uuid(),
            'payload' => [
                'customer' => ['name' => 'Race Customer', 'phone' => '01110007513'],
                'shipping' => ['shipping_area_id' => $area->id, 'address' => 'Concurrent test address'],
                'items' => [['sellable_item_id' => $item->id, 'quantity' => $quantity]],
                'payment_method' => 'cash_on_delivery',
            ],
        ];
    }

    private function sourceUpdateOperation(SellableItem $source, int $stock): array
    {
        return ['type' => 'update_source', 'product_id' => $source->product_id, 'source_id' => $source->id, 'stock_quantity' => $stock];
    }

    private function transferOperation(array $fixture, int $quantity, ?string $key = null): array
    {
        return [
            'type' => 'transfer', 'product_id' => $fixture['product']->id, 'order_id' => $fixture['order']->id,
            'receipt_id' => $fixture['receipt']->id, 'receipt_item_id' => $fixture['receipt_item']->id,
            'target_id' => $fixture['target']->id, 'quantity' => $quantity,
            'reason' => 'PostgreSQL concurrency transfer', 'actor_id' => $this->actor->id,
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ];
    }

    private function correctionOperation(array $fixture, int $restock): array
    {
        return [
            'type' => 'correct', 'product_id' => $fixture['product']->id, 'receipt_id' => $fixture['receipt']->id,
            'actor_id' => $this->actor->id, 'idempotency_key' => (string) Str::uuid(),
            'payload' => [
                'expected_revision' => 0, 'explanation' => 'Concurrent return correction',
                'items' => [[
                    'return_receipt_item_id' => $fixture['receipt_item']->id,
                    'received_quantity' => 3, 'restockable_quantity' => $restock,
                ]],
            ],
        ];
    }

    private function receiptReversalOperation(array $fixture): array
    {
        return [
            'type' => 'reverse_receipt', 'product_id' => $fixture['product']->id,
            'receipt_id' => $fixture['receipt']->id, 'actor_id' => $this->actor->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload' => ['expected_revision' => 0, 'reversal_reason' => 'Concurrent receipt reversal'],
        ];
    }

    private function createTransfer(array $fixture, int $quantity): ReturnInventoryTransfer
    {
        $result = app(\App\Services\ReturnInventoryTransferService::class)->transfer(
            $fixture['order'], $fixture['receipt']->id, $fixture['receipt_item']->id,
            [
                'target_sellable_item_id' => $fixture['target']->id,
                'quantity' => $quantity,
                'reason' => 'Seed transfer for concurrency reversal.',
            ],
            (int) $this->actor->id,
            (string) Str::uuid(),
        );

        return $result['transfer'];
    }

    private function transferReversalOperation(array $fixture, ReturnInventoryTransfer $transfer): array
    {
        return [
            'type' => 'reverse_transfer', 'product_id' => $fixture['product']->id,
            'order_id' => $fixture['order']->id, 'receipt_id' => $fixture['receipt']->id,
            'receipt_item_id' => $fixture['receipt_item']->id, 'transfer_id' => $transfer->id,
            'actor_id' => $this->actor->id, 'reason' => 'Concurrent inventory transfer reversal',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function runContendedPair(int $productId, array $firstOperation, array $secondOperation, string $lockTable = 'products', ?int $lockId = null): array
    {
        DedicatedPostgresDatabase::assertConfiguredAndConnected();
        $timeout = self::WORKER_TIMEOUT_SECONDS;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'shiny-postgres-concurrency-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create the temporary worker barrier directory.');
        }

        $firstGoPath = $directory.DIRECTORY_SEPARATOR.'first-go';
        $secondGoPath = $directory.DIRECTORY_SEPARATOR.'second-go';
        $firstReleasePath = $directory.DIRECTORY_SEPARATOR.'release-first';
        $firstLockedPath = $directory.DIRECTORY_SEPARATOR.'first-locked.json';
        $workers = [];
        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/postgres-inventory-worker.php');
        $nonce = bin2hex(random_bytes(8));

        try {
            foreach ([['first', $firstOperation, $firstReleasePath, $firstLockedPath], ['second', $secondOperation, '', '']] as [$name, $operation, $releasePath, $lockedPath]) {
                $appName = 'shinyinv_'.$nonce.'_'.$name;
                $job = [
                    'application_name' => $appName,
                    'ready_path' => $directory.DIRECTORY_SEPARATOR.$name.'-ready.json',
                    'go_path' => $name === 'first' ? $firstGoPath : $secondGoPath,
                    'release_path' => $releasePath,
                    'locked_path' => $lockedPath,
                    'result_path' => $directory.DIRECTORY_SEPARATOR.$name.'-result.json',
                    'timeout_seconds' => $timeout,
                    'lock_id' => $name === 'first' ? ($lockId ?? $productId) : null,
                    'lock_table' => $lockTable,
                    'operation' => $operation,
                ];
                $encodedJob = base64_encode(json_encode($job, JSON_THROW_ON_ERROR));
                $process = new Process([$phpBinary, $workerScript, $encodedJob], base_path());
                $process->setTimeout($timeout + 5);
                $process->start();
                $workers[$name] = ['process' => $process, 'job' => $job];
            }

            $this->waitForWorkerFiles(array_column(array_column($workers, 'job'), 'ready_path'), $workers, $timeout);
            file_put_contents($firstGoPath, 'go');
            $this->waitForPath($firstLockedPath, $workers, $timeout, 'First worker did not acquire the product lock.');
            file_put_contents($secondGoPath, 'go');
            $this->waitForPostgresLock($workers['second']['job']['application_name'], $workers, $timeout);
            file_put_contents($firstReleasePath, 'release');

            $this->waitForWorkerFiles(array_column(array_column($workers, 'job'), 'result_path'), $workers, $timeout);

            return [
                json_decode((string) file_get_contents($workers['first']['job']['result_path']), true, flags: JSON_THROW_ON_ERROR),
                json_decode((string) file_get_contents($workers['second']['job']['result_path']), true, flags: JSON_THROW_ON_ERROR),
            ];
        } finally {
            file_put_contents($firstGoPath, 'cleanup');
            file_put_contents($secondGoPath, 'cleanup');
            file_put_contents($firstReleasePath, 'cleanup');
            foreach ($workers as $worker) {
                $process = $worker['process'];
                if ($process->isRunning()) {
                    $process->stop(1.0);
                }
            }
            $resolvedDirectory = realpath($directory);
            $resolvedTemp = realpath(sys_get_temp_dir());
            if ($resolvedDirectory !== false && $resolvedTemp !== false
                && str_starts_with($resolvedDirectory, $resolvedTemp.DIRECTORY_SEPARATOR)
                && str_contains(basename($resolvedDirectory), 'shiny-postgres-concurrency-')) {
                foreach (glob($resolvedDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                rmdir($resolvedDirectory);
            }
        }
    }

    private function waitForPostgresLock(string $applicationName, array $workers, int $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $waitEvent = DB::table('pg_stat_activity')->where('application_name', $applicationName)->value('wait_event_type');
            if ($waitEvent === 'Lock') {
                return;
            }
            $this->assertWorkersAlive($workers);
            usleep(25_000);
        }

        throw new RuntimeException('Second worker never appeared as blocked on a PostgreSQL lock.');
    }

    private function waitForWorkerFiles(array $paths, array $workers, int $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }
            $this->assertWorkersAlive($workers);
            usleep(25_000);
        }

        throw new RuntimeException('Timed out waiting for independent PostgreSQL workers.');
    }

    private function waitForPath(string $path, array $workers, int $timeout, string $failureMessage): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return;
            }
            $this->assertWorkersAlive($workers);
            usleep(25_000);
        }

        throw new RuntimeException($failureMessage);
    }

    private function assertWorkersAlive(array $workers): void
    {
        foreach ($workers as $name => $worker) {
            if (! $worker['process']->isRunning() && ! is_file($worker['job']['result_path'])) {
                throw new RuntimeException("{$name} PostgreSQL worker exited before publishing a result: ".$worker['process']->getErrorOutput());
            }
        }
    }

    private function assertHistoricalOrderUnchanged(array $fixture): void
    {
        $this->assertSame(
            $fixture['order_snapshot'],
            $fixture['order_item']->fresh()->only(array_keys($fixture['order_snapshot'])),
        );
        $this->assertSame(
            $fixture['order_state'],
            $fixture['order']->fresh()->only(array_keys($fixture['order_state'])),
        );
    }
}
