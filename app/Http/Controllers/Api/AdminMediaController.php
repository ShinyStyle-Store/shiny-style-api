<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminMediaPrimaryRequest;
use App\Http\Requests\AdminMediaUpdateRequest;
use App\Http\Requests\AdminMediaUploadRequest;
use App\Http\Resources\AdminMediaAttachmentResource;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\SellableItem;
use App\Services\AdminMediaManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class AdminMediaController extends Controller
{
    public function productIndex(Product $product, AdminMediaManagementService $media): AnonymousResourceCollection
    {
        return AdminMediaAttachmentResource::collection($media->listing($product));
    }

    public function productStore(AdminMediaUploadRequest $request, Product $product, AdminMediaManagementService $media): JsonResponse
    {
        $attachment = $this->store($request, $product, $media);

        return (new AdminMediaAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function productUpdate(AdminMediaUpdateRequest $request, Product $product, int $attachment, AdminMediaManagementService $media): AdminMediaAttachmentResource
    {
        $scoped = $media->findAttachment($product, $attachment);

        return new AdminMediaAttachmentResource($media->update($product, $scoped, $request->validated()));
    }

    public function productPrimary(AdminMediaPrimaryRequest $request, Product $product, int $attachment, AdminMediaManagementService $media): AdminMediaAttachmentResource
    {
        $scoped = $media->findAttachment($product, $attachment);

        return new AdminMediaAttachmentResource($media->setPrimary($product, $scoped));
    }

    public function productDestroy(Product $product, int $attachment, AdminMediaManagementService $media): Response
    {
        $media->delete($product, $media->findAttachment($product, $attachment));

        return response()->noContent();
    }

    public function sellableItemIndex(SellableItem $sellableItem, AdminMediaManagementService $media): AnonymousResourceCollection
    {
        return AdminMediaAttachmentResource::collection($media->listing($sellableItem));
    }

    public function sellableItemStore(AdminMediaUploadRequest $request, SellableItem $sellableItem, AdminMediaManagementService $media): JsonResponse
    {
        $attachment = $this->store($request, $sellableItem, $media);

        return (new AdminMediaAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function sellableItemUpdate(AdminMediaUpdateRequest $request, SellableItem $sellableItem, int $attachment, AdminMediaManagementService $media): AdminMediaAttachmentResource
    {
        $scoped = $media->findAttachment($sellableItem, $attachment);

        return new AdminMediaAttachmentResource($media->update($sellableItem, $scoped, $request->validated()));
    }

    public function sellableItemPrimary(AdminMediaPrimaryRequest $request, SellableItem $sellableItem, int $attachment, AdminMediaManagementService $media): AdminMediaAttachmentResource
    {
        $scoped = $media->findAttachment($sellableItem, $attachment);

        return new AdminMediaAttachmentResource($media->setPrimary($sellableItem, $scoped));
    }

    public function sellableItemDestroy(SellableItem $sellableItem, int $attachment, AdminMediaManagementService $media): Response
    {
        $media->delete($sellableItem, $media->findAttachment($sellableItem, $attachment));

        return response()->noContent();
    }

    private function store(
        AdminMediaUploadRequest $request,
        Product|SellableItem $owner,
        AdminMediaManagementService $media,
    ): MediaAttachment {
        $data = $request->validated();
        $file = $data['file'];
        $kind = $data['kind'];
        $makePrimary = (bool) ($data['is_primary'] ?? false);
        unset($data['file'], $data['kind'], $data['is_primary']);

        return $media->upload($owner, $file, $kind, $data, $makePrimary, $request->user());
    }
}
