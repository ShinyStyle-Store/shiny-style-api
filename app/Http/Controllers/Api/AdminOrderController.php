<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminOrderIndexRequest;
use App\Http\Resources\AdminOrderDetailResource;
use App\Http\Resources\AdminOrderListResource;
use App\Models\Order;
use App\Support\EgyptianPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
