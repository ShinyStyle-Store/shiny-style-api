<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeoRedirectResolveRequest;
use App\Services\SeoRedirectService;
use Illuminate\Http\JsonResponse;

class SeoRedirectResolverController extends Controller
{
    public function show(SeoRedirectResolveRequest $request, SeoRedirectService $redirects): JsonResponse
    {
        return response()->json([
            'data' => $redirects->resolve($request->validated('path')),
        ]);
    }
}
