<?php

namespace App\Http\Controllers\Api;

use App\Enums\CancellationReason;
use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderLifecycleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminOrderCancellationRequest;
use App\Http\Requests\AdminOrderContactStatusRequest;
use App\Http\Requests\AdminOrderEmptyActionRequest;
use App\Http\Requests\AdminOrderIndexRequest;
use App\Http\Requests\AdminReturnCorrectionRequest;
use App\Http\Requests\AdminReturnReceiptIndexRequest;
use App\Http\Requests\AdminReturnReceiptRequest;
use App\Http\Resources\AdminOrderDetailResource;
use App\Http\Resources\AdminOrderListResource;
use App\Http\Resources\AdminReturnCorrectionResource;
use App\Http\Resources\AdminReturnReceiptResource;
use App\Exceptions\PhysicalReturnConflictException;
use App\Models\Order;
use App\Services\OrderLifecycleService;
use App\Services\PhysicalReturnService;
use App\Support\EgyptianPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminOrderController extends Controller
{
    public function index(AdminOrderIndexRequest $request)
    {
        $filters = $request->validated();
        $query = Order::query()
            ->select([
                'id', 'public_id', 'order_number', 'status', 'contact_status', 'payment_method',
                'payment_status', 'currency', 'customer_name', 'customer_phone', 'alternate_phone',
                'shipping_area_id', 'shipping_area_code', 'shipping_area_name_ar', 'shipping_area_name_en',
                'subtotal', 'shipping_fee', 'total', 'last_contacted_at', 'created_at',
            ])
            ->withCount('items')
            ->withSum('items', 'quantity');

        foreach (['status', 'contact_status', 'payment_method', 'payment_status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['shipping_area_id'])) {
            $query->where('orders.shipping_area_id', $filters['shipping_area_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::createFromFormat('!Y-m-d', $filters['date_from'], 'UTC'));
        }
        if (isset($filters['date_to'])) {
            $query->where('created_at', '<', Carbon::createFromFormat('!Y-m-d', $filters['date_to'], 'UTC')->addDay());
        }

        if (! empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        if (($filters['sort'] ?? 'newest') === 'oldest') {
            $query->orderBy('created_at')->orderBy('id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        $orders = $query->paginate($filters['per_page'] ?? 20)->withQueryString();

        return AdminOrderListResource::collection($orders);
    }

    public function show(Request $request, string $public_id): AdminOrderDetailResource
    {
        $order = Order::query()
            ->where('public_id', $public_id)
            ->with('items')
            ->firstOrFail();
        $order->setAttribute('return_summary', app(PhysicalReturnService::class)->summary($order));

        return new AdminOrderDetailResource($order);
    }

    public function returns(AdminReturnReceiptIndexRequest $request, string $public_id)
    {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();
        $receipts = $order->returnReceipts()
            ->with('items.orderItem')
            ->orderByDesc('received_at')->orderByDesc('id')
            ->paginate($request->validated('per_page', 20))
            ->withQueryString();
        $orderSummary = app(PhysicalReturnService::class)->summary($order);
        $receipts->getCollection()->each(fn ($receipt) => $receipt->setAttribute('order_summary', $orderSummary));

        return AdminReturnReceiptResource::collection($receipts);
    }

    public function showReturn(string $public_id, int $return_receipt): AdminReturnReceiptResource
    {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();
        $receipt = $order->returnReceipts()->whereKey($return_receipt)->with([
            'items.orderItem', 'corrections.items',
        ])->firstOrFail();
        $receipt->setAttribute('order_summary', app(PhysicalReturnService::class)->summary($order));

        return new AdminReturnReceiptResource($receipt);
    }

    public function storeReturn(
        AdminReturnReceiptRequest $request,
        string $public_id,
        PhysicalReturnService $returns,
    ): JsonResponse|AdminReturnReceiptResource {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();

        try {
            $result = $returns->create($order, $request->servicePayload(), (int) $request->user()->getKey(), $request->idempotencyKey());
        } catch (PhysicalReturnConflictException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->mapReturnValidationErrors($exception->errors()));
        }

        $result['receipt']->setAttribute('order_summary', $returns->summary($order));
        $response = new AdminReturnReceiptResource($result['receipt']);
        return $result['replayed'] ? $response : $response->response()->setStatusCode(201);
    }

    public function correctReturn(
        AdminReturnCorrectionRequest $request,
        string $public_id,
        int $return_receipt,
        PhysicalReturnService $returns,
    ): JsonResponse|AdminReturnCorrectionResource {
        $order = Order::query()->where('public_id', $public_id)->firstOrFail();
        $receipt = $order->returnReceipts()->whereKey($return_receipt)->firstOrFail();

        try {
            $result = $returns->correct($receipt, $request->servicePayload(), (int) $request->user()->getKey(), $request->idempotencyKey());
        } catch (PhysicalReturnConflictException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->mapReturnValidationErrors($exception->errors()));
        }

        $result['receipt']->setAttribute('order_summary', $returns->summary($order));
        $payload = [
            'operation' => (new AdminReturnCorrectionResource($result['correction']))->resolve($request),
            'receipt' => (new AdminReturnReceiptResource($result['receipt']))->resolve($request),
        ];
        return response()->json(['data' => $payload]);
    }

    public function confirm(AdminOrderEmptyActionRequest $request, string $public_id, OrderLifecycleService $lifecycle): JsonResponse|AdminOrderDetailResource
    {
        return $this->transition($public_id, OrderStatus::Confirmed, $lifecycle);
    }

    public function prepare(AdminOrderEmptyActionRequest $request, string $public_id, OrderLifecycleService $lifecycle): JsonResponse|AdminOrderDetailResource
    {
        return $this->transition($public_id, OrderStatus::Preparing, $lifecycle);
    }

    public function ship(AdminOrderEmptyActionRequest $request, string $public_id, OrderLifecycleService $lifecycle): JsonResponse|AdminOrderDetailResource
    {
        return $this->transition($public_id, OrderStatus::Shipped, $lifecycle);
    }

    public function deliver(AdminOrderEmptyActionRequest $request, string $public_id, OrderLifecycleService $lifecycle): JsonResponse|AdminOrderDetailResource
    {
        return $this->transition($public_id, OrderStatus::Delivered, $lifecycle);
    }

    public function cancel(AdminOrderCancellationRequest $request, string $public_id, OrderLifecycleService $lifecycle): JsonResponse|AdminOrderDetailResource
    {
        $data = $request->validated();

        return $this->transition(
            $public_id,
            OrderStatus::Cancelled,
            $lifecycle,
            CancellationReason::from($data['reason']),
            $data['note'] ?? null,
        );
    }

    public function updateContactStatus(AdminOrderContactStatusRequest $request, string $public_id): JsonResponse|AdminOrderDetailResource
    {
        $data = $request->validated();

        try {
            DB::transaction(function () use ($data, $public_id): void {
                $order = Order::query()
                    ->where('public_id', $public_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($order->status, [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
                    throw new InvalidOrderLifecycleException('Contact status cannot be changed for a terminal order.');
                }

                $contactStatus = ContactStatus::from($data['contact_status']);
                $order->contact_status = $contactStatus;

                if ($contactStatus === ContactStatus::NotContacted) {
                    $order->last_contacted_at = null;
                    $order->contact_note = null;
                } else {
                    $order->last_contacted_at = now();
                    if (array_key_exists('contact_note', $data)) {
                        $order->contact_note = $data['contact_note'];
                    }
                }

                $order->save();
            });
        } catch (InvalidOrderLifecycleException) {
            return response()->json([
                'code' => 'terminal_order_contact_update_not_allowed',
                'message' => 'Contact status cannot be changed for a completed or cancelled order.',
            ], 409);
        }

        return $this->detailByPublicId($public_id);
    }

    private function transition(
        string $publicId,
        OrderStatus $target,
        OrderLifecycleService $lifecycle,
        ?CancellationReason $reason = null,
        ?string $note = null,
    ): JsonResponse|AdminOrderDetailResource {
        try {
            $order = Order::query()->where('public_id', $publicId)->firstOrFail();
            $lifecycle->transition($order, $target, $reason, $note);
        } catch (InvalidOrderLifecycleException) {
            return response()->json([
                'code' => 'invalid_order_transition',
                'message' => 'The requested order action is not allowed.',
            ], 409);
        }

        return $this->detailByPublicId($publicId);
    }

    private function detailByPublicId(string $publicId): AdminOrderDetailResource
    {
        $order = Order::query()
            ->where('public_id', $publicId)
            ->with('items')
            ->firstOrFail();
        $order->setAttribute('return_summary', app(PhysicalReturnService::class)->summary($order));

        return new AdminOrderDetailResource($order);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $pattern = "%{$escaped}%";
        $phone = EgyptianPhone::normalize($search);
        $canonicalPhone = is_string($phone) && preg_match('/^01[0125][0-9]{8}$/', $phone) ? $phone : null;

        $query->where(function (Builder $nested) use ($operator, $pattern, $canonicalPhone): void {
            $nested->whereRaw("order_number {$operator} ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("customer_name {$operator} ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("customer_phone {$operator} ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("alternate_phone {$operator} ? ESCAPE '\\'", [$pattern]);

            if ($canonicalPhone !== null) {
                $nested->orWhere('customer_phone', $canonicalPhone)
                    ->orWhere('alternate_phone', $canonicalPhone);
            }
        });
    }

    /** @param array<string, list<string>> $errors @return array<string, list<string>> */
    private function mapReturnValidationErrors(array $errors): array
    {
        $segments = [
            'request_received_at' => 'return_requested_at',
            'received_at' => 'items_received_at',
            'restockable_quantity' => 'restock_quantity',
            'expected_revision' => 'expected_version',
            'explanation' => 'correction_reason',
        ];

        $mapped = [];
        foreach ($errors as $key => $messages) {
            $mappedKey = implode('.', array_map(
                static fn (string $segment): string => $segments[$segment] ?? $segment,
                explode('.', $key),
            ));
            $mapped[$mappedKey] = $messages;
        }

        return $mapped;
    }
}
