<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Services\BannerService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BannerController extends Controller
{
    public function index(BannerService $banners): AnonymousResourceCollection
    {
        return BannerResource::collection($banners->publicList());
    }
}
