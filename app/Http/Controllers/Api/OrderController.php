<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\PaymobConfigurationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuestOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\GuestOrderService;
use App\Services\PaymobCardIntentionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(StoreGuestOrderRequest $request, GuestOrderService $orderService, PaymobCardIntentionService $intentions): JsonResponse
    {
        try {
            if ($request->validated('payment_method') === 'card') {
                $intentions->validateCardCheckoutConfiguration();
            }
            $result = $orderService->create($request->validated(), $request->idempotencyKey());
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (QueryException) {
            return response()->json(['message' => 'The order could not be created.'], 500);
        } catch (PaymobConfigurationException) {
            return response()->json([
                'code' => 'payment_configuration_unavailable',
                'message' => 'Card payment is temporarily unavailable.',
            ], 503);
        }

        return (new OrderResource($result['order']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }
}
