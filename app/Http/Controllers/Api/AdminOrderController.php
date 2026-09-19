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
use App\Http\Resources\AdminOrderDetailResource;
use App\Http\Resources\AdminOrderListResource;
use App\Models\Order;
use App\Services\OrderLifecycleService;
use App\Support\EgyptianPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        return new AdminOrderDetailResource($order);
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
}
