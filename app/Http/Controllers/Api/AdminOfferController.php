<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OfferProductConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminOfferIndexRequest;
use App\Http\Requests\AdminOfferRequest;
use App\Http\Resources\AdminOfferResource;
use App\Models\Offer;
use App\Services\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AdminOfferController extends Controller
{
    public function index(AdminOfferIndexRequest $request, OfferService $offers): AnonymousResourceCollection
    {
        return AdminOfferResource::collection($offers->adminList((int) ($request->validated()['per_page'] ?? 20)));
    }

    public function store(AdminOfferRequest $request, OfferService $offers): JsonResponse
    {
        try {
            $offer = $offers->create($request->validated());
        } catch (OfferProductConflictException $exception) {
            return $this->conflict($exception);
        }

        return (new AdminOfferResource($offer))->response()->setStatusCode(201);
    }

    public function show(Offer $offer): AdminOfferResource
    {
        return new AdminOfferResource($offer->load('products'));
    }

    public function update(AdminOfferRequest $request, Offer $offer, OfferService $offers): AdminOfferResource|JsonResponse
    {
        try {
            return new AdminOfferResource($offers->update($offer, $request->validated()));
        } catch (OfferProductConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function deactivate(Offer $offer, OfferService $offers): AdminOfferResource
    {
        return new AdminOfferResource($offers->deactivate($offer));
    }

    private function conflict(OfferProductConflictException $exception): JsonResponse
    {
        return response()->json([
            'code' => 'offer_product_overlap',
            'message' => 'The selected product overlaps another enabled offer.',
            'conflict' => [
                'productId' => $exception->productId,
                'offerId' => $exception->offerId,
            ],
        ], 409);
    }
}
