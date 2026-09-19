<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShippingAreaResource;
use App\Models\ShippingArea;

class ShippingAreaController extends Controller
{
    public function index()
    {
        $shippingAreas = ShippingArea::query()
            ->active()
            ->selectable()
            ->ordered()
            ->get();

        return ShippingAreaResource::collection($shippingAreas);
    }
}
