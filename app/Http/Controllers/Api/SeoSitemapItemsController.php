<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeoSitemapItemsRequest;
use App\Services\SeoSitemapItemsService;
use Illuminate\Http\JsonResponse;

class SeoSitemapItemsController extends Controller
{
    public function index(SeoSitemapItemsRequest $request, SeoSitemapItemsService $sitemap): JsonResponse
    {
        $validated = $request->validated();
        $type = $validated['type'];

        return response()->json([
            'data' => $sitemap->page(
                $type,
                $validated['cursor'] ?? null,
                (int) ($validated['limit'] ?? 50),
            ),
        ]);
    }
}
