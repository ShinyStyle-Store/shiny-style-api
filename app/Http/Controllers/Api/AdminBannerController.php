<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BannerImageRequest;
use App\Http\Requests\BannerRequest;
use App\Http\Resources\AdminBannerResource;
use App\Models\Banner;
use App\Services\BannerService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class AdminBannerController extends Controller
{
    public function index(BannerService $banners): AnonymousResourceCollection
    {
        return AdminBannerResource::collection($banners->adminList());
    }

    public function store(BannerRequest $request, BannerService $banners): JsonResponse
    {
        $data = $request->validated();
        $image = $data['image'];
        unset($data['image']);
        $banner = $banners->create($data, $image, $request->user());

        return (new AdminBannerResource($banner))->response()->setStatusCode(201);
    }

    public function show(Banner $banner, BannerService $banners): AdminBannerResource
    {
        return new AdminBannerResource($banner->load('bannerImageAttachment.mediaAsset'));
    }

    public function update(BannerRequest $request, Banner $banner, BannerService $banners): AdminBannerResource
    {
        return new AdminBannerResource($banners->update($banner, $request->validated()));
    }

    public function image(BannerImageRequest $request, Banner $banner, BannerService $banners, MediaService $media): AdminBannerResource
    {
        $media->validateImage($request->file('image'), 'image');

        return new AdminBannerResource($banners->replaceImage($banner, $request->file('image'), $request->user()));
    }

    public function destroy(Banner $banner, BannerService $banners): Response
    {
        $banners->delete($banner);

        return response()->noContent();
    }
}
