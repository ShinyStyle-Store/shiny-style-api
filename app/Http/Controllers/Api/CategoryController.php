<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Services\CategoryHierarchyService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(CategoryHierarchyService $hierarchy): AnonymousResourceCollection
    {
        return CategoryResource::collection($hierarchy->publicList());
    }

    public function show(string $slug, CategoryHierarchyService $hierarchy): CategoryResource
    {
        $category = $hierarchy->publicDetail($slug);
        abort_if($category === null, 404);

        return new CategoryResource($category);
    }
}
