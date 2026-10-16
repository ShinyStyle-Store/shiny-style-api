<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialLinkRequest;
use App\Http\Resources\AdminSocialLinkResource;
use App\Http\Resources\SocialLinkResource;
use App\Models\SocialLink;
use App\Services\StoreSocialLinkService;
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

    public function store(SocialLinkRequest $request, StoreSocialLinkService $links): JsonResponse
    {
        try {
            $link = $links->create($request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicatePlatform($exception);
            throw $exception;
        }

        return (new AdminSocialLinkResource($link))->response()->setStatusCode(201);
    }

    public function update(
        SocialLinkRequest $request,
        SocialLink $socialLink,
        StoreSocialLinkService $links,
    ): AdminSocialLinkResource
    {
        try {
            $socialLink = $links->update($socialLink, $request->validated());
        } catch (QueryException $exception) {
            $this->convertDuplicatePlatform($exception);
            throw $exception;
        }

        return new AdminSocialLinkResource($socialLink->refresh());
    }

    public function destroy(SocialLink $socialLink, StoreSocialLinkService $links): Response
    {
        $links->delete($socialLink);

        return response()->noContent();
    }

    private function convertDuplicatePlatform(QueryException $exception): void
    {
        $message = strtolower($exception->getMessage());
        if ($exception->getCode() === '23000'
            || str_contains($message, 'social_links.platform_code')
            || str_contains($message, 'social_links_platform_code_unique')) {
            throw ValidationException::withMessages([
                'platformCode' => 'A social link for this platform already exists.',
            ]);
        }
    }
}
