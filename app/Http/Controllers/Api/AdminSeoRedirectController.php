<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminSeoRedirectIndexRequest;
use App\Http\Requests\AdminSeoRedirectStoreRequest;
use App\Http\Requests\AdminSeoRedirectUpdateRequest;
use App\Http\Resources\SeoRedirectResource;
use App\Models\SeoRedirect;
use App\Services\SeoRedirectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminSeoRedirectController extends Controller
{
    public function index(AdminSeoRedirectIndexRequest $request): AnonymousResourceCollection
    {
        return SeoRedirectResource::collection(
            SeoRedirect::query()
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate($request->validated('per_page', 20))
                ->withQueryString(),
        );
    }

    public function store(AdminSeoRedirectStoreRequest $request, SeoRedirectService $redirects): JsonResponse|SeoRedirectResource
    {
        $redirect = $redirects->createManual($request->validated(), $request->user());

        return (new SeoRedirectResource($redirect))->response()->setStatusCode(201);
    }

    public function update(
        AdminSeoRedirectUpdateRequest $request,
        SeoRedirect $redirect,
        SeoRedirectService $redirects,
    ): SeoRedirectResource {
        return new SeoRedirectResource($redirects->updateManual($redirect, $request->validated(), $request->user()));
    }

    public function destroy(SeoRedirect $redirect, SeoRedirectService $redirects): SeoRedirectResource
    {
        return new SeoRedirectResource($redirects->deactivate($redirect, request()->user()));
    }
}
