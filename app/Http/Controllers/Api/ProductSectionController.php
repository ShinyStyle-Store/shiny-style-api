<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductSectionRequest;
use App\Http\Resources\ProductListResource;
use App\Services\HomeProductSectionService;
use App\Services\OfferPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductSectionController extends Controller
{
    public function index(
        ProductSectionRequest $request,
        string $section,
        HomeProductSectionService $sections,
        OfferPricingService $pricing,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $instant = CarbonImmutable::now()->utc();
        $products = $sections->paginateSection(
            $section,
            (int) ($validated['per_page'] ?? 24),
            (int) ($validated['page'] ?? 1),
            $instant,
        );
        $pricing->attachToProducts($products->getCollection(), $instant);

        return ProductListResource::collection($products);
    }
}
