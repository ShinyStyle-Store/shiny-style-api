<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProductReviewImageBulkRequest;
use App\Http\Requests\AdminProductReviewImageReorderRequest;
use App\Http\Requests\AdminProductReviewImageUpdateRequest;
use App\Http\Requests\AdminProductReviewImageUploadRequest;
use App\Http\Resources\AdminProductReviewImageResource;
use App\Models\Product;
use App\Services\AdminProductReviewImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class AdminProductReviewImageController extends Controller
{
    public function index(Product $product, AdminProductReviewImageService $service): AnonymousResourceCollection
    {
        return AdminProductReviewImageResource::collection($service->list($product));
    }

    public function archived(Product $product, AdminProductReviewImageService $service): AnonymousResourceCollection
    {
        return AdminProductReviewImageResource::collection($service->list($product, true));
    }

    public function store(AdminProductReviewImageUploadRequest $request, Product $product, AdminProductReviewImageService $service): JsonResponse
    {
        $data = $request->validated();
        $review = $service->upload($product, $data['image'], $data, $request->user());

        return (new AdminProductReviewImageResource($review))->response()->setStatusCode(201);
    }

    public function bulk(AdminProductReviewImageBulkRequest $request, Product $product, AdminProductReviewImageService $service): JsonResponse
    {
        $data = $request->validated();
        $reviews = $service->bulk($product, $data['images'], $data, $request->user());

        return AdminProductReviewImageResource::collection($reviews)->response()->setStatusCode(201);
    }

    public function update(
        AdminProductReviewImageUpdateRequest $request,
        Product $product,
        int $reviewImage,
        AdminProductReviewImageService $service,
    ): AdminProductReviewImageResource {
        $review = $service->find($product, $reviewImage);
        $data = $request->validated();
        if ($request->hasFile('image')) {
            $file = $data['image'];
            unset($data['image'], $data['_method']);

            return new AdminProductReviewImageResource($service->replace($review, $file, $data, $request->user()));
        }

        unset($data['image'], $data['_method']);

        return new AdminProductReviewImageResource($service->update($review, $data));
    }

    public function reorder(
        AdminProductReviewImageReorderRequest $request,
        Product $product,
        AdminProductReviewImageService $service,
    ): AnonymousResourceCollection {
        return AdminProductReviewImageResource::collection($service->reorder($product, $request->validated()['items']));
    }

    public function destroy(Product $product, int $reviewImage, AdminProductReviewImageService $service): Response
    {
        $service->delete($service->find($product, $reviewImage));

        return response()->noContent();
    }

    public function restore(Product $product, int $reviewImage, AdminProductReviewImageService $service): AdminProductReviewImageResource
    {
        return new AdminProductReviewImageResource($service->restore($product, $reviewImage));
    }

    public function publicIndex(Request $request, string $slug): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $visibleIds = app(\App\Services\CategoryHierarchyService::class)->effectiveVisibleIds();
        $product = Product::query()->visible($visibleIds)->where('slug', $slug)->firstOrFail();
        $images = $product->reviewImages()->published()
            ->whereHas('mediaAttachment.mediaAsset')
            ->with('mediaAttachment.mediaAsset')->ordered()
            ->paginate($validated['per_page'] ?? 10)->withQueryString();

        return \App\Http\Resources\ProductReviewImageResource::collection($images);
    }
}
