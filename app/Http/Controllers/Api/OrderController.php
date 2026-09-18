<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuestOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\GuestOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(StoreGuestOrderRequest $request, GuestOrderService $orderService): JsonResponse
    {
        try {
            $result = $orderService->create($request->validated(), $request->idempotencyKey());
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (QueryException) {
            return response()->json(['message' => 'The order could not be created.'], 500);
        }

        return (new OrderResource($result['order']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }
}
