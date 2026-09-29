<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HomeProductsRequest;
use App\Http\Resources\ProductListResource;
use App\Services\HomeProductSectionService;
use App\Services\OfferPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class HomeProductController extends Controller
{
    public function products(
        HomeProductsRequest $request,
        HomeProductSectionService $sections,
        OfferPricingService $pricing,
    ): JsonResponse
    {
        $instant = CarbonImmutable::now()->utc();
        $products = $sections->sections((int) ($request->validated()['limit'] ?? 8), $instant);
        $pricing->attachToProducts(
            collect($products)->flatMap(fn ($section) => $section)->values(),
            $instant,
        );

        return response()->json(['data' => [
            'featured' => $this->cards($products['featured'], $request),
            'bestSelling' => $this->cards($products['bestSelling'], $request),
            'newest' => $this->cards($products['newest'], $request),
            'offers' => $this->cards($products['offers'], $request),
        ]]);
    }

    private function cards(iterable $products, HomeProductsRequest $request): array
    {
        return collect($products)->map(fn ($product): array => (new ProductListResource($product))->resolve($request))->values()->all();
    }
}
