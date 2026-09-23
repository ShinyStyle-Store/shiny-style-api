<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HomeProductsRequest;
use App\Http\Resources\ProductListResource;
use App\Services\HomeProductSectionService;
use Illuminate\Http\JsonResponse;

final class HomeProductController extends Controller
{
    public function products(HomeProductsRequest $request, HomeProductSectionService $sections): JsonResponse
    {
        $products = $sections->sections((int) ($request->validated()['limit'] ?? 8));

        return response()->json(['data' => [
            'featured' => $this->cards($products['featured'], $request),
            'bestSelling' => $this->cards($products['bestSelling'], $request),
            'newest' => $this->cards($products['newest'], $request),
            'offers' => [],
        ]]);
    }

    private function cards(iterable $products, HomeProductsRequest $request): array
    {
        return collect($products)->map(fn ($product): array => (new ProductListResource($product))->resolve($request))->values()->all();
    }
}
