<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProductOptionRequest;
use App\Http\Requests\AdminProductOptionValueRequest;
use App\Http\Resources\AdminProductOptionResource;
use App\Http\Resources\AdminProductOptionValueResource;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\ProductOptionManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AdminProductOptionController extends Controller
{
    public function index(Product $product)
    {
        $options = $product->options()
            ->with(['values' => fn (HasMany $value) => $value->ordered()])
            ->withCount(['values', 'values as used_by_variants_count' => fn (Builder $value) => $value->whereHas('sellableItems')])
            ->ordered()
            ->get();

        return AdminProductOptionResource::collection($options);
    }

    public function store(
        AdminProductOptionRequest $request,
        Product $product,
        ProductOptionManagementService $options,
    ): JsonResponse|AdminProductOptionResource {
        try {
            $option = $options->createOption($product, $request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicate($exception, 'option', $request->validated(), $product->getKey());
            throw $exception;
        }

        return (new AdminProductOptionResource($this->loadOption($option)))->response()->setStatusCode(201);
    }

    public function show(Product $product, int $option): AdminProductOptionResource
    {
        return new AdminProductOptionResource($this->loadOption($this->findOption($product, $option)));
    }

    public function update(
        AdminProductOptionRequest $request,
        Product $product,
        int $option,
        ProductOptionManagementService $options,
    ): AdminProductOptionResource {
        $model = $this->findOption($product, $option);
        try {
            $updated = $options->updateOption($model, $request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicate($exception, 'option', $request->validated(), $product->getKey(), $model->getKey());
            throw $exception;
        }

        return new AdminProductOptionResource($this->loadOption($updated));
    }

    public function archive(
        Product $product,
        int $option,
        ProductOptionManagementService $options,
    ): JsonResponse|Response {
        $model = $this->findOption($product, $option);
        $conflict = $options->archiveOption($model);
        if ($conflict !== null) {
            return response()->json([
                'code' => $conflict,
                'message' => 'The option is used by an existing sellable item and cannot be archived.',
            ], 409);
        }

        return response()->noContent();
    }

    public function restore(
        Product $product,
        int $option,
        ProductOptionManagementService $options,
    ): JsonResponse|AdminProductOptionResource {
        $model = ProductOption::withTrashed()
            ->whereKey($option)
            ->where('product_id', $product->getKey())
            ->firstOrFail();
        $result = $options->restoreOption($model->getKey());
        if (is_string($result)) {
            return response()->json([
                'code' => $result,
                'message' => 'The option is not archived.',
            ], 409);
        }

        return new AdminProductOptionResource($this->loadOption($result));
    }

    public function values(Product $product, int $option)
    {
        $model = $this->findOption($product, $option);
        $values = $model->values()->withCount('sellableItems')->ordered()->get();

        return AdminProductOptionValueResource::collection($values);
    }

    public function storeValue(
        AdminProductOptionValueRequest $request,
        Product $product,
        int $option,
        ProductOptionManagementService $options,
    ): JsonResponse|AdminProductOptionValueResource {
        $parent = $this->findOption($product, $option);
        try {
            $value = $options->createValue($parent, $request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicate($exception, 'value', $request->validated(), $parent->getKey());
            throw $exception;
        }

        return (new AdminProductOptionValueResource($value->loadCount('sellableItems')))
            ->response()->setStatusCode(201);
    }

    public function showValue(Product $product, int $option, int $value): AdminProductOptionValueResource
    {
        return new AdminProductOptionValueResource(
            $this->findValue($product, $option, $value)->loadCount('sellableItems'),
        );
    }

    public function updateValue(
        AdminProductOptionValueRequest $request,
        Product $product,
        int $option,
        int $value,
        ProductOptionManagementService $options,
    ): AdminProductOptionValueResource {
        $model = $this->findValue($product, $option, $value);
        try {
            $updated = $options->updateValue($model, $request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicate($exception, 'value', $request->validated(), $model->product_option_id, $model->getKey());
            throw $exception;
        }

        return new AdminProductOptionValueResource($updated->loadCount('sellableItems'));
    }

    public function archiveValue(
        Product $product,
        int $option,
        int $value,
        ProductOptionManagementService $options,
    ): JsonResponse|Response {
        $model = $this->findValue($product, $option, $value);
        $conflict = $options->archiveValue($model);
        if ($conflict !== null) {
            return response()->json([
                'code' => $conflict,
                'message' => 'The value is used by an existing sellable item and cannot be archived.',
            ], 409);
        }

        return response()->noContent();
    }

    public function restoreValue(
        Product $product,
        int $option,
        int $value,
        ProductOptionManagementService $options,
    ): JsonResponse|AdminProductOptionValueResource {
        $this->findOption($product, $option);
        $model = ProductOptionValue::withTrashed()
            ->whereKey($value)
            ->where('product_option_id', $option)
            ->firstOrFail();
        $result = $options->restoreValue($model->getKey());
        if (is_string($result)) {
            return response()->json([
                'code' => $result,
                'message' => 'The value is not archived.',
            ], 409);
        }

        return new AdminProductOptionValueResource($result->loadCount('sellableItems'));
    }

    private function findOption(Product $product, int $option): ProductOption
    {
        return $product->options()->whereKey($option)->firstOrFail();
    }

    private function findValue(Product $product, int $option, int $value): ProductOptionValue
    {
        $parent = $this->findOption($product, $option);

        return $parent->values()->whereKey($value)->firstOrFail();
    }

    private function loadOption(ProductOption $option): ProductOption
    {
        return $option->load([
            'values' => fn (HasMany $value) => $value->ordered(),
        ])->loadCount([
            'values',
            'values as used_by_variants_count' => fn (Builder $value) => $value->whereHas('sellableItems'),
        ]);
    }

    private function convertDuplicate(
        QueryException $exception,
        string $resource,
        array $data = [],
        ?int $parentId = null,
        ?int $ignoreId = null,
    ): void
    {
        $message = strtolower($exception->getMessage());
        if ($parentId !== null) {
            $model = $resource === 'option' ? ProductOption::withTrashed() : ProductOptionValue::withTrashed();
            $parentColumn = $resource === 'option' ? 'product_id' : 'product_option_id';
            $fields = $resource === 'option'
                ? ['code' => 'code', 'name_ar' => 'name_ar_normalized', 'name_en' => 'name_en_normalized']
                : ['code' => 'code', 'value_ar' => 'value_ar_normalized', 'value_en' => 'value_en_normalized'];

            foreach ($fields as $field => $column) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $value = $field === 'code'
                    ? strtolower(trim((string) $data[$field]))
                    : (str_ends_with($field, '_ar')
                        ? trim((string) $data[$field])
                        : mb_strtolower(trim((string) $data[$field])));
                $duplicate = $model->where($parentColumn, $parentId)->where($column, $value);
                if ($ignoreId !== null) {
                    $duplicate->whereKeyNot($ignoreId);
                }
                if ($duplicate->exists()) {
                    throw ValidationException::withMessages([
                        $field => 'The '.$field.' has already been taken within this parent.',
                    ]);
                }
            }
        }

        $field = match (true) {
            str_contains($message, 'product_options_product_id_code_unique'),
            str_contains($message, 'product_options.product_id, product_options.code') => 'code',
            str_contains($message, 'product_option_values_product_option_id_code_unique'),
            str_contains($message, 'product_option_values.product_option_id, product_option_values.code') => 'code',
            default => $resource === 'option' ? 'name_en' : 'value_en',
        };

        if (str_contains($message, 'unique')) {
            throw ValidationException::withMessages([
                $field => 'The '.$field.' has already been taken within this parent.',
            ]);
        }
    }
}
