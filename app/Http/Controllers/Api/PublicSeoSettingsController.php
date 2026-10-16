<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSeoSettingsRequest;
use App\Services\SeoPublicSettingsService;
use Illuminate\Http\JsonResponse;

class PublicSeoSettingsController extends Controller
{
    public function show(PublicSeoSettingsRequest $request, SeoPublicSettingsService $settings): JsonResponse
    {
        return response()->json(['data' => $settings->resolve()]);
    }
}
