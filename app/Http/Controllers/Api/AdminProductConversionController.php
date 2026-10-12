<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProductConversionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProductConversionRequest;
use App\Models\Product;
use App\Services\AdminProductConversionService;
use Illuminate\Http\JsonResponse;

class AdminProductConversionController extends Controller
{
    public function context(int $product, int $source, AdminProductConversionService $conversions): JsonResponse
    {
        $product = Product::query()->findOrFail($product);

        try {
            return response()->json(['data' => $conversions->context($product, $source)]);
        } catch (ProductConversionConflictException $exception) {
            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], 409);
        }
    }

    public function convert(
        AdminProductConversionRequest $request,
        int $product,
        int $source,
        AdminProductConversionService $conversions,
    ): JsonResponse {
        $product = Product::query()->findOrFail($product);

        try {
            $result = $conversions->convert($product, $source, $request->validated(), $request->idempotencyKey());
        } catch (ProductConversionConflictException $exception) {
            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'data' => $result['data'],
            'meta' => ['replayed' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }
}
