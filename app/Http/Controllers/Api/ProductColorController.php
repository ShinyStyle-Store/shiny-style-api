<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductColorCatalogRequest;
use App\Services\CategoryHierarchyService;
use App\Services\ProductColorCatalogService;
use Illuminate\Http\JsonResponse;

final class ProductColorController extends Controller
{
    public function adminIndex(ProductColorCatalogRequest $request, ProductColorCatalogService $colors): JsonResponse
    {
        $result = $colors->adminSuggestions();
        if ($result['conflicts'] !== []) {
            return response()->json([
                'message' => 'Legacy color records require canonical codes and consistent translations.',
                'conflicts' => $result['conflicts'],
            ], 409);
        }

        return response()->json([
            'data' => array_map(static fn (array $color): array => [
                'code' => $color['code'],
                'nameAr' => $color['nameAr'],
                'nameEn' => $color['nameEn'],
            ], $result['colors']),
        ]);
    }

    public function publicFilterOptions(
        ProductColorCatalogRequest $request,
        ProductColorCatalogService $colors,
        CategoryHierarchyService $hierarchy,
    ): JsonResponse {
        $result = $colors->publicFilterOptions($hierarchy->visibilitySnapshot()->visibleIds(), app()->getLocale());
        if ($result['conflicts'] !== []) {
            return response()->json([
                'message' => 'Public color records require canonical codes and consistent translations.',
                'conflicts' => $result['conflicts'],
            ], 409);
        }

        return response()->json(['data' => ['colors' => $result['colors']]]);
    }
}
