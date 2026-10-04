<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RefundRecordConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRefundCorrectionRequest;
use App\Http\Requests\AdminRefundRecordRequest;
use App\Http\Resources\AdminRefundCorrectionResource;
use App\Http\Resources\AdminRefundRecordResource;
use App\Models\Order;
use App\Services\RefundRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AdminRefundRecordController extends Controller
{
    public function index(string $public_id): AnonymousResourceCollection
    {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();
        $records = $order->refundRecords()
            ->with('mediaAttachments.mediaAsset')
            ->with('corrections')
            ->latest('created_at')->latest('id')
            ->paginate(20)->withQueryString();

        return AdminRefundRecordResource::collection($records);
    }

    public function show(string $public_id, int $refund_record): AdminRefundRecordResource
    {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();
        $record = $order->refundRecords()->with([
            'mediaAttachments.mediaAsset', 'corrections',
        ])->whereKey($refund_record)->firstOrFail();

        return new AdminRefundRecordResource($record);
    }

    public function store(
        AdminRefundRecordRequest $request,
        string $public_id,
        RefundRecordService $refunds,
    ): JsonResponse {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();

        try {
            $result = $refunds->create(
                $order,
                $request->validated(),
                $request->file('evidence'),
                (int) $request->user()->getKey(),
                $request->idempotencyKey(),
            );
        } catch (RefundRecordConflictException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        }

        $response = (new AdminRefundRecordResource($result['record']))->response();

        return $result['replayed'] ? $response : $response->setStatusCode(201);
    }

    public function correct(
        AdminRefundCorrectionRequest $request,
        string $public_id,
        int $refund_record,
        RefundRecordService $refunds,
    ): JsonResponse {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();
        $record = $order->refundRecords()->whereKey($refund_record)->firstOrFail();

        try {
            $data = $request->validated();
            $data['expected_revision'] = $data['expected_version'];
            unset($data['expected_version']);
            $result = $refunds->correct(
                $record,
                $data,
                $request->file('evidence'),
                (int) $request->user()->getKey(),
                $request->idempotencyKey(),
            );
        } catch (RefundRecordConflictException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'record' => (new AdminRefundRecordResource($result['record']))->resolve($request),
                'correction' => (new AdminRefundCorrectionResource($result['correction']))->resolve($request),
            ],
        ]);
    }
}
