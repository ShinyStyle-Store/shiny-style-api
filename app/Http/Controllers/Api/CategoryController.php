<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryHierarchyService;

class CategoryController extends Controller
{
    public function index(CategoryHierarchyService $hierarchy)
    {
        $visibleIds = $hierarchy->effectiveVisibleIds();
        $categories = Category::query()
            ->whereIn('id', $visibleIds)
            ->ordered()
            ->get();

        return CategoryResource::collection($categories);
    }

    public function show(string $slug, CategoryHierarchyService $hierarchy)
    {
        $visibleIds = $hierarchy->effectiveVisibleIds();
        $category = Category::query()
            ->whereIn('id', $visibleIds)
            ->where('slug', $slug)
            ->firstOrFail();

        return new CategoryResource($category);
    }
}
