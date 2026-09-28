<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialLinkRequest;
use App\Http\Resources\AdminSocialLinkResource;
use App\Http\Resources\SocialLinkResource;
use App\Models\SocialLink;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class StoreSocialLinkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminSocialLinkResource::collection(SocialLink::query()->ordered()->get());
    }

    public function publicIndex(): AnonymousResourceCollection
    {
        return SocialLinkResource::collection(SocialLink::query()->enabled()->ordered()->get());
    }

    public function store(SocialLinkRequest $request): JsonResponse
    {
        try {
            $link = SocialLink::query()->create($request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicatePlatform($exception);
            throw $exception;
        }

        return (new AdminSocialLinkResource($link))->response()->setStatusCode(201);
    }

    public function update(SocialLinkRequest $request, SocialLink $socialLink): AdminSocialLinkResource
    {
        try {
            $socialLink->update($request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicatePlatform($exception);
            throw $exception;
        }

        return new AdminSocialLinkResource($socialLink->refresh());
    }

    public function destroy(SocialLink $socialLink): Response
    {
        $socialLink->delete();

        return response()->noContent();
    }

    private function convertDuplicatePlatform(QueryException $exception): void
    {
        $message = strtolower($exception->getMessage());
        if ($exception->getCode() === '23000'
            || str_contains($message, 'social_links.platform_code')
            || str_contains($message, 'social_links_platform_code_unique')) {
            throw ValidationException::withMessages([
                'platform_code' => 'A social link for this platform already exists.',
            ]);
        }
    }
}
