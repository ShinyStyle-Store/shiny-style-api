<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminDefaultOgImageRequest;
use App\Services\AdminDefaultOgImageService;
use Illuminate\Http\JsonResponse;

final class AdminDefaultOgImageController extends Controller
{
    public function store(
        AdminDefaultOgImageRequest $request,
        AdminDefaultOgImageService $images,
    ): JsonResponse {
        return response()->json([
            'data' => $images->upload($request->file('image'), $request->user()),
        ], 201);
    }
}
