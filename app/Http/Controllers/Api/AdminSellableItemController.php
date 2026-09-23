<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminSellableItemRequest;
use App\Http\Resources\AdminSellableItemResource;
use App\Models\Product;
use App\Models\SellableItem;
use App\Services\SellableItemManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AdminSellableItemController extends Controller
{
    public function index(Product $product)
    {
        $items = $product->sellableItems()
            ->with(['optionValues.option'])
            ->ordered()
            ->get();

        return AdminSellableItemResource::collection($items);
    }

    public function store(
        AdminSellableItemRequest $request,
        Product $product,
        SellableItemManagementService $items,
    ): JsonResponse|AdminSellableItemResource {
        try {
            $item = $items->create($product, $request->validated());
        } catch (QueryException $exception) {
            $this->convertIntegrityFailure($exception);
            throw $exception;
        }

        return (new AdminSellableItemResource($this->loadItem($item)))->response()->setStatusCode(201);
    }

    public function show(Product $product, int $variant): AdminSellableItemResource
    {
        return new AdminSellableItemResource($this->loadItem($this->findItem($product, $variant)));
    }

    public function update(
        AdminSellableItemRequest $request,
        Product $product,
        int $variant,
        SellableItemManagementService $items,
    ): AdminSellableItemResource {
        $item = $this->findItem($product, $variant);
        try {
            $updated = $items->update($item, $request->validated());
        } catch (QueryException $exception) {
            $this->convertIntegrityFailure($exception);
            throw $exception;
        }

        return new AdminSellableItemResource($this->loadItem($updated));
    }

    public function archive(
        Product $product,
        int $variant,
        SellableItemManagementService $items,
    ): JsonResponse|Response {
        $item = $this->findItem($product, $variant);
        $conflict = $items->archive($item);
        if ($conflict !== null) {
            return response()->json([
                'code' => $conflict,
                'message' => 'A variant with reservations cannot be archived.',
            ], 409);
        }

        return response()->noContent();
    }

    public function restore(
        Product $product,
        int $variant,
        SellableItemManagementService $items,
    ): JsonResponse|AdminSellableItemResource {
        $result = $items->restore($variant, $product->getKey());
        if (is_string($result)) {
            return response()->json([
                'code' => $result,
                'message' => match ($result) {
                    'variant_not_archived' => 'The variant is not archived.',
                    'variant_default_conflict' => 'The variant cannot be restored while another default variant exists.',
                    default => 'The variant cannot be restored in its current state.',
                },
            ], 409);
        }

        return new AdminSellableItemResource($this->loadItem($result));
    }

    private function findItem(Product $product, int $variant): SellableItem
    {
        return $product->sellableItems()->whereKey($variant)->firstOrFail();
    }

    private function loadItem(SellableItem $item): SellableItem
    {
        return $item->load(['optionValues.option']);
    }

    private function convertIntegrityFailure(QueryException $exception): void
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'sku') || str_contains($message, 'sellable_items_sku_unique')) {
            throw ValidationException::withMessages(['sku' => 'The SKU has already been taken.']);
        }
        if (str_contains($message, 'sellable_items_product_combination_unique')
            || str_contains($message, 'sellable_items.product_id, sellable_items.combination_key')) {
            throw ValidationException::withMessages(['option_value_ids' => 'This option value combination already exists.']);
        }
        if (str_contains($message, 'default') || str_contains($message, 'sellable_items_one_default_per_product_unique')) {
            throw ValidationException::withMessages(['is_default' => 'The product may have only one default variant.']);
        }
    }
}
