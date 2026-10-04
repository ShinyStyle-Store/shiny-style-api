<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPrimaryImageResource;
use App\Services\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminDashboardController extends Controller
{
    public function overview(Request $request, AdminDashboardService $dashboard): JsonResponse
    {
        $validated = $request->validate([
            'chart_days' => ['sometimes', 'integer', Rule::in([7, 30])],
        ]);
        $data = $dashboard->overview((int) ($validated['chart_days'] ?? 7));
        $data['top_selling_products'] = collect($data['top_selling_products'])
            ->map(function (array $product) use ($request): array {
                $model = $product['product'];
                $primaryImage = $model?->getRelation('primaryProductImage');
                $image = $primaryImage !== null && $primaryImage->relationLoaded('mediaAsset') && $primaryImage->mediaAsset !== null
                    ? (new AdminPrimaryImageResource($primaryImage))->resolve($request)
                    : null;
                $locale = app()->getLocale();
                $name = $locale === 'en'
                    ? (($product['name_en'] ?? '') !== '' ? $product['name_en'] : $product['name_ar'])
                    : (($product['name_ar'] ?? '') !== '' ? $product['name_ar'] : $product['name_en']);

                return [
                    'product_id' => $product['product_id'],
                    'historical_order_item_id' => $product['historical_order_item_id'],
                    'name' => $name,
                    'net_units_sold' => $product['net_units_sold'],
                    'image' => $image,
                ];
            })->values()->all();

        return response()->json(['data' => $data]);
    }
}
