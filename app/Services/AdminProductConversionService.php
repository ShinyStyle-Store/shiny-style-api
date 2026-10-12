<?php

namespace App\Services;

use App\Exceptions\ProductConversionConflictException;
use App\Models\Product;
use App\Models\ProductConversion;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ReturnReceiptItem;
use App\Models\SellableItem;
use App\Support\ExactMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminProductConversionService
{
    public function __construct(private readonly ProductReadinessService $readiness) {}

    /** @return array<string, mixed> */
    public function context(Product $product, int $sourceId): array
    {
        return DB::transaction(function () use ($product, $sourceId): array {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            [$items, $options, $values, $pivots] = $this->lockCatalogState($lockedProduct);
            $source = $items->firstWhere('id', $sourceId);
            if (! $source instanceof SellableItem) {
                abort(404);
            }

            $this->assertSourceCanBeConverted($lockedProduct, $source, $items, $options, $pivots, false);
            $contextToken = $this->contextToken($lockedProduct, $items, $options, $values, $pivots);

            return [
                'product' => [
                    'id' => $lockedProduct->getKey(),
                    'nameAr' => $lockedProduct->name_ar,
                    'nameEn' => $lockedProduct->name_en,
                    'status' => $lockedProduct->status,
                ],
                'source' => [
                    'id' => $source->getKey(),
                    'sku' => $source->sku,
                    'price' => (string) $source->price,
                    'stockQuantity' => (int) $source->stock_quantity,
                    'reservedQuantity' => (int) $source->reserved_quantity,
                    'availableQuantity' => $source->availableQuantity(),
                ],
                'inventory' => [
                    'oldStock' => (int) $source->stock_quantity,
                    'reserved' => (int) $source->reserved_quantity,
                    'available' => $source->availableQuantity(),
                    'transferScope' => 'source_sellable_item_only',
                ],
                'options' => [],
                'variants' => [],
                'contextToken' => $contextToken,
            ];
        });
    }

    /** @param array<string, mixed> $data @return array{data: array<string, mixed>, replayed: bool} */
    public function convert(Product $product, int $sourceId, array $data, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint((int) $product->getKey(), $sourceId, $data);

        try {
            return DB::transaction(function () use ($product, $sourceId, $data, $idempotencyKey, $fingerprint): array {
                $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

                $existing = ProductConversion::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertReplayMatches($existing, $lockedProduct, $sourceId, $fingerprint);

                    return ['data' => $existing->result, 'replayed' => true];
                }

                if (ProductConversion::query()->where('product_id', $lockedProduct->getKey())->exists()) {
                    $this->conflict('already_converted', 'This product has already been converted.');
                }

                [$items, $options, $values, $pivots] = $this->lockCatalogState($lockedProduct);
                $source = $items->firstWhere('id', $sourceId);
                if (! $source instanceof SellableItem) {
                    abort(404);
                }
                $this->assertSourceCanBeConverted($lockedProduct, $source, $items, $options, $pivots);

                $currentToken = $this->contextToken($lockedProduct, $items, $options, $values, $pivots);
                if (! hash_equals($currentToken, (string) $data['context_token'])) {
                    $this->conflict('stale_context', 'The product or source changed after this conversion context was read.');
                }

                $oldStock = (int) $source->stock_quantity;
                $returnRestockBaseline = ReturnReceiptItem::query()
                    ->where('sellable_item_id', $source->getKey())
                    ->orderBy('id')
                    ->get(['id', 'effective_restockable_quantity'])
                    ->mapWithKeys(fn (ReturnReceiptItem $item): array => [
                        (string) $item->getKey() => (int) $item->effective_restockable_quantity,
                    ])
                    ->all();
                $allocatedStock = $this->allocatedStock($data['variants']);
                $difference = $allocatedStock - $oldStock;
                $reason = $this->assertStockDifference($data, $difference);

                $conversionOptions = $this->createOptions($lockedProduct, $data['options']);
                $valueReferences = $conversionOptions['value_references'];

                $source->status = 'inactive';
                $source->is_default = false;
                $source->stock_quantity = 0;
                $source->superseded_at = now();
                $source->save();

                $variants = $this->createVariants(
                    $lockedProduct,
                    $data['variants'],
                    $valueReferences,
                    (string) $data['default_variant_ref'],
                );

                if ($this->readiness->isRequired($lockedProduct)) {
                    $this->readiness->assertReady($lockedProduct);
                }

                $response = $this->conversionResponse(
                    $lockedProduct,
                    $source,
                    $conversionOptions['options'],
                    $variants,
                    $oldStock,
                    $allocatedStock,
                    $difference,
                    $reason,
                );

                $record = ProductConversion::query()->create([
                    'product_id' => $lockedProduct->getKey(),
                    'source_sellable_item_id' => $source->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'context_token' => $currentToken,
                    'old_stock' => $oldStock,
                    'allocated_stock' => $allocatedStock,
                    'stock_difference' => $difference,
                    'stock_difference_reason' => $reason,
                    'return_restock_baseline' => $returnRestockBaseline,
                    'allocation' => collect($variants)->map(fn (array $variant): array => [
                        'variant_id' => $variant['id'],
                        'sku' => $variant['sku'],
                        'stock_quantity' => $variant['stockQuantity'],
                    ])->all(),
                    'result' => [],
                ]);
                $response['conversionId'] = $record->getKey();
                $record->result = $response;
                $record->save();

                return ['data' => $response, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            return $this->resolveUniqueRace($exception, $product, $sourceId, $fingerprint, $idempotencyKey);
        }
    }

    /** @return array{0: Collection<int, SellableItem>, 1: Collection<int, ProductOption>, 2: Collection<int, ProductOptionValue>, 3: list<array{sellable_item_id: int, product_option_value_id: int}>} */
    private function lockCatalogState(Product $product): array
    {
        $items = SellableItem::withTrashed()
            ->where('product_id', $product->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $options = ProductOption::withTrashed()
            ->where('product_id', $product->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $values = ProductOptionValue::withTrashed()
            ->whereIn('product_option_id', $options->modelKeys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $pivots = DB::table('sellable_item_option_values')
            ->join('sellable_items', 'sellable_items.id', '=', 'sellable_item_option_values.sellable_item_id')
            ->where('sellable_items.product_id', $product->getKey())
            ->orderBy('sellable_item_option_values.sellable_item_id')
            ->orderBy('sellable_item_option_values.product_option_value_id')
            ->lockForUpdate()
            ->get([
                'sellable_item_option_values.sellable_item_id',
                'sellable_item_option_values.product_option_value_id',
            ])
            ->map(fn (object $pivot): array => [
                'sellable_item_id' => (int) $pivot->sellable_item_id,
                'product_option_value_id' => (int) $pivot->product_option_value_id,
            ])
            ->all();

        return [$items, $options, $values, $pivots];
    }

    /** @param Collection<int, SellableItem> $items @param \Illuminate\Support\Collection<int, ProductOption> $options @param list<array{sellable_item_id: int, product_option_value_id: int}> $pivots */
    private function assertSourceCanBeConverted(
        Product $product,
        SellableItem $source,
        Collection $items,
        Collection $options,
        array $pivots,
        bool $checkReservations = true,
    ): void {
        if (ProductConversion::query()->where('product_id', $product->getKey())->exists()
            || $source->superseded_at !== null) {
            $this->conflict('already_converted', 'This source has already been converted.');
        }

        $activeOptions = $options->whereNull('deleted_at');
        if ($activeOptions->isNotEmpty()) {
            $this->conflict('product_has_options', 'The product already has active options.');
        }

        $activeItems = $items->filter(fn (SellableItem $item): bool => $item->deleted_at === null && $item->status === 'active');
        if ($activeItems->count() !== 1 || ! $activeItems->contains('id', $source->getKey())
            || $source->deleted_at !== null || $source->status !== 'active') {
            $this->conflict('source_not_convertible', 'The source must be the only active, unarchived sellable item for this simple product.');
        }

        $sourceValueIds = collect($pivots)
            ->where('sellable_item_id', $source->getKey())
            ->pluck('product_option_value_id');
        if ($sourceValueIds->isNotEmpty()) {
            $this->conflict('source_has_options', 'The simple source cannot have option value assignments.');
        }

        if ($checkReservations && $items->contains(fn (SellableItem $item): bool => (int) $item->reserved_quantity !== 0)) {
            $this->conflict('product_has_reservations', 'The product has reserved quantities and cannot be converted.');
        }
    }

    /** @param Collection<int, SellableItem> $items @param \Illuminate\Support\Collection<int, ProductOption> $options @param \Illuminate\Support\Collection<int, ProductOptionValue> $values @param list<array{sellable_item_id: int, product_option_value_id: int}> $pivots */
    private function contextToken(Product $product, Collection $items, Collection $options, Collection $values, array $pivots): string
    {
        $state = [
            'product' => [
                'id' => (int) $product->getKey(),
                'slug' => (string) $product->slug,
                'status' => (string) $product->status,
                'published_at' => $product->published_at?->toISOString(),
                'updated_at' => $product->updated_at?->toISOString(),
                'deleted_at' => $product->deleted_at?->toISOString(),
            ],
            'sellable_items' => $items->map(fn (SellableItem $item): array => [
                'id' => (int) $item->getKey(),
                'product_id' => (int) $item->product_id,
                'sku' => (string) $item->sku,
                'price' => (string) $item->price,
                'original_price' => $item->original_price === null ? null : (string) $item->original_price,
                'stock_quantity' => (int) $item->stock_quantity,
                'reserved_quantity' => (int) $item->reserved_quantity,
                'status' => (string) $item->status,
                'is_default' => (bool) $item->is_default,
                'sort_order' => (int) $item->sort_order,
                'combination_key' => (string) $item->combination_key,
                'superseded_at' => $item->superseded_at?->toISOString(),
                'deleted_at' => $item->deleted_at?->toISOString(),
                'updated_at' => $item->updated_at?->toISOString(),
            ])->values()->all(),
            'options' => $options->map(fn (ProductOption $option): array => [
                'id' => (int) $option->getKey(),
                'product_id' => (int) $option->product_id,
                'code' => (string) $option->code,
                'name_ar' => (string) $option->name_ar,
                'name_en' => (string) $option->name_en,
                'sort_order' => (int) $option->sort_order,
                'deleted_at' => $option->deleted_at?->toISOString(),
                'updated_at' => $option->updated_at?->toISOString(),
            ])->values()->all(),
            'values' => $values->map(fn (ProductOptionValue $value): array => [
                'id' => (int) $value->getKey(),
                'product_option_id' => (int) $value->product_option_id,
                'code' => (string) $value->code,
                'value_ar' => (string) $value->value_ar,
                'value_en' => (string) $value->value_en,
                'metadata' => $value->metadata,
                'sort_order' => (int) $value->sort_order,
                'deleted_at' => $value->deleted_at?->toISOString(),
                'updated_at' => $value->updated_at?->toISOString(),
            ])->values()->all(),
            'assignments' => $pivots,
        ];

        return hash('sha256', json_encode($this->canonicalize($state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $options @return array{options: list<array<string, mixed>>, value_references: array<string, array{id: int, option_id: int}>} */
    private function createOptions(Product $product, array $options): array
    {
        $createdOptions = [];
        $valueReferences = [];
        foreach ($options as $optionInput) {
            $option = ProductOption::query()->create([
                'product_id' => $product->getKey(),
                'code' => $optionInput['code'],
                'name_ar' => $optionInput['name_ar'],
                'name_en' => $optionInput['name_en'],
                'sort_order' => (int) $optionInput['sort_order'],
            ]);
            $createdValues = [];
            foreach ($optionInput['values'] as $valueInput) {
                $value = ProductOptionValue::query()->create([
                    'product_option_id' => $option->getKey(),
                    'code' => $valueInput['code'],
                    'value_ar' => $valueInput['value_ar'],
                    'value_en' => $valueInput['value_en'],
                    'metadata' => $valueInput['metadata'] ?? null,
                    'sort_order' => (int) $valueInput['sort_order'],
                ]);
                $valueReferences[$valueInput['ref']] = [
                    'id' => (int) $value->getKey(),
                    'option_id' => (int) $option->getKey(),
                ];
                $createdValues[] = [
                    'id' => (int) $value->getKey(),
                    'ref' => $valueInput['ref'],
                    'code' => $value->code,
                    'valueAr' => $value->value_ar,
                    'valueEn' => $value->value_en,
                ];
            }
            $createdOptions[] = [
                'id' => (int) $option->getKey(),
                'ref' => $optionInput['ref'],
                'code' => $option->code,
                'nameAr' => $option->name_ar,
                'nameEn' => $option->name_en,
                'values' => $createdValues,
            ];
        }

        return ['options' => $createdOptions, 'value_references' => $valueReferences];
    }

    /** @param list<array<string, mixed>> $variantInputs @param array<string, array{id: int, option_id: int}> $valueReferences @return list<array<string, mixed>> */
    private function createVariants(Product $product, array $variantInputs, array $valueReferences, string $defaultRef): array
    {
        $created = [];
        foreach ($variantInputs as $variantInput) {
            $valueIds = array_map(fn (string $ref): int => $valueReferences[$ref]['id'], $variantInput['option_value_refs']);
            sort($valueIds, SORT_NUMERIC);
            $variant = SellableItem::query()->create([
                'product_id' => $product->getKey(),
                'sku' => $variantInput['sku'],
                'price' => $this->canonicalMoney($variantInput['price']),
                'original_price' => isset($variantInput['original_price']) ? $this->canonicalMoney($variantInput['original_price']) : null,
                'stock_quantity' => (int) $variantInput['stock_quantity'],
                'reserved_quantity' => 0,
                'status' => 'active',
                'is_default' => $variantInput['ref'] === $defaultRef,
                'sort_order' => (int) $variantInput['sort_order'],
                'combination_key' => implode(',', $valueIds),
            ]);
            $variant->optionValues()->sync($valueIds);
            $created[] = [
                'id' => (int) $variant->getKey(),
                'ref' => $variantInput['ref'],
                'sku' => $variant->sku,
                'price' => (string) $variant->price,
                'originalPrice' => $variant->original_price === null ? null : (string) $variant->original_price,
                'stockQuantity' => (int) $variant->stock_quantity,
                'reservedQuantity' => 0,
                'isDefault' => (bool) $variant->is_default,
                'optionValueIds' => $valueIds,
            ];
        }

        return $created;
    }

    /** @param list<array<string, mixed>> $variants @return array<string, mixed> */
    private function conversionResponse(
        Product $product,
        SellableItem $source,
        array $options,
        array $variants,
        int $oldStock,
        int $allocatedStock,
        int $difference,
        ?string $reason,
    ): array {
        $default = collect($variants)->firstWhere('isDefault', true);

        return [
            'product' => ['id' => (int) $product->getKey(), 'slug' => $product->slug, 'status' => $product->status],
            'source' => [
                'id' => (int) $source->getKey(),
                'stockBefore' => $oldStock,
                'stockAfter' => (int) $source->stock_quantity,
                'reservedQuantity' => (int) $source->reserved_quantity,
                'supersededAt' => $source->superseded_at?->toISOString(),
            ],
            'options' => $options,
            'variants' => $variants,
            'defaultVariantId' => $default['id'],
            'inventory' => [
                'allocatedStock' => $allocatedStock,
                'difference' => $difference,
                'reason' => $reason,
                'sourceStockTransferred' => $oldStock,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function allocatedStock(array $variants): int
    {
        $total = 0;
        foreach ($variants as $variant) {
            $quantity = (int) $variant['stock_quantity'];
            if ($total > PHP_INT_MAX - $quantity) {
                throw ValidationException::withMessages(['variants' => 'The total allocated stock exceeds the supported limit.']);
            }
            $total += $quantity;
        }

        return $total;
    }

    /** @param array<string, mixed> $data */
    private function assertStockDifference(array $data, int $difference): ?string
    {
        $confirmation = $data['stock_adjustment'] ?? null;
        if ($difference === 0) {
            if ($confirmation !== null) {
                throw ValidationException::withMessages(['stock_adjustment' => 'A stock adjustment is only accepted when the allocation differs from the source stock.']);
            }

            return null;
        }

        if (! is_array($confirmation)
            || (int) ($confirmation['confirmed_difference'] ?? PHP_INT_MAX) !== $difference
            || trim((string) ($confirmation['reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'stock_adjustment' => 'Confirm the exact current stock difference and provide a reason.',
            ]);
        }

        return trim($confirmation['reason']);
    }

    private function canonicalMoney(string $value): string
    {
        return ExactMoney::formatMinorUnits(ExactMoney::toMinorUnits($value));
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(int $productId, int $sourceId, array $data): string
    {
        $canonical = $data;
        foreach ($canonical['variants'] as &$variant) {
            $variant['price'] = $this->canonicalMoney((string) $variant['price']);
            if (isset($variant['original_price'])) {
                $variant['original_price'] = $this->canonicalMoney((string) $variant['original_price']);
            }
        }
        unset($variant);

        return hash('sha256', json_encode($this->canonicalize([
            'product_id' => $productId,
            'source_id' => $sourceId,
            'request' => $canonical,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        foreach ($value as &$item) {
            $item = $this->canonicalize($item);
        }
        unset($item);

        return $value;
    }

    private function assertReplayMatches(ProductConversion $conversion, Product $product, int $sourceId, string $fingerprint): void
    {
        if ((int) $conversion->product_id !== (int) $product->getKey()
            || (int) $conversion->source_sellable_item_id !== $sourceId
            || ! hash_equals($conversion->request_fingerprint, $fingerprint)) {
            $this->conflict('idempotency_key_conflict', 'The Idempotency-Key was already used for a different conversion request.');
        }
    }

    /** @return array{data: array<string, mixed>, replayed: bool} */
    private function resolveUniqueRace(
        QueryException $exception,
        Product $product,
        int $sourceId,
        string $fingerprint,
        string $idempotencyKey,
    ): array {
        $existing = ProductConversion::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertReplayMatches($existing, $product, $sourceId, $fingerprint);

            return ['data' => $existing->result, 'replayed' => true];
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'sellable_items.sku') || str_contains($message, 'sellable_items_sku_unique')) {
            throw ValidationException::withMessages(['variants' => 'One or more variant SKUs have already been taken.']);
        }
        if (str_contains($message, 'product_options_product_name_ar_unique')) {
            throw ValidationException::withMessages(['options' => 'An Arabic option name already exists for this product.']);
        }
        if (str_contains($message, 'product_options_product_name_en_unique')) {
            throw ValidationException::withMessages(['options' => 'An English option name already exists for this product.']);
        }
        if (str_contains($message, 'product_option_values_option_value_ar_unique')) {
            throw ValidationException::withMessages(['options' => 'An Arabic option value label already exists.']);
        }
        if (str_contains($message, 'product_option_values_option_value_en_unique')) {
            throw ValidationException::withMessages(['options' => 'An English option value label already exists.']);
        }
        if (str_contains($message, 'product_conversions_product_id_unique')
            || str_contains($message, 'product_conversions_source_sellable_item_id_unique')) {
            $this->conflict('already_converted', 'This product or source has already been converted.');
        }

        throw $exception;
    }

    private function conflict(string $code, string $message): never
    {
        throw new ProductConversionConflictException($code, $message);
    }
}
