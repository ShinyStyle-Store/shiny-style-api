<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreStructuredDataResource;
use App\Services\StoreStructuredDataService;
use Illuminate\Http\Request;

class StoreStructuredDataController extends Controller
{
    public function show(Request $request, StoreStructuredDataService $store): StoreStructuredDataResource
    {
        return new StoreStructuredDataResource($store->publicProfile($request));
    }
}
