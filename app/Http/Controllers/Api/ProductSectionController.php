<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductSectionRequest;
use App\Http\Resources\ProductListResource;
use App\Services\HomeProductSectionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductSectionController extends Controller
{
    public function index(
        ProductSectionRequest $request,
        string $section,
        HomeProductSectionService $sections,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $products = $sections->paginateSection(
            $section,
            (int) ($validated['per_page'] ?? 24),
            (int) ($validated['page'] ?? 1),
        );

        return ProductListResource::collection($products);
    }
}
