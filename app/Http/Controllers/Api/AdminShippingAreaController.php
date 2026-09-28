<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminShippingAreaRequest;
use App\Http\Resources\AdminShippingAreaResource;
use App\Models\ShippingArea;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AdminShippingAreaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminShippingAreaResource::collection(
            ShippingArea::query()->governorates()->ordered()->get(),
        );
    }

    public function show(ShippingArea $shippingArea): AdminShippingAreaResource
    {
        $this->assertGovernorate($shippingArea);

        return new AdminShippingAreaResource($shippingArea);
    }

    public function store(AdminShippingAreaRequest $request): JsonResponse
    {
        $data = $request->validated();
        $shippingArea = ShippingArea::query()->create([
            ...$data,
            'parent_id' => null,
            'type' => ShippingArea::TYPE_GOVERNORATE,
            'is_selectable' => true,
        ]);

        return (new AdminShippingAreaResource($shippingArea))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        AdminShippingAreaRequest $request,
        ShippingArea $shippingArea,
    ): AdminShippingAreaResource|JsonResponse {
        $this->assertGovernorate($shippingArea);
        $validated = $request->validated();

        $result = DB::transaction(function () use ($shippingArea, $validated): ShippingArea|string {
            $locked = ShippingArea::query()
                ->whereKey($shippingArea->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertGovernorate($locked);

            if (array_key_exists('is_active', $validated)
                && $validated['is_active'] === false
                && $locked->children()->exists()) {
                return 'governorate_has_children';
            }

            $locked->fill([
                ...$validated,
                'parent_id' => null,
                'type' => ShippingArea::TYPE_GOVERNORATE,
                'is_selectable' => true,
            ]);
            $locked->save();

            return $locked;
        });

        if (is_string($result)) {
            return response()->json([
                'code' => $result,
                'message' => 'Move or remove child shipping areas before disabling this governorate.',
            ], 409);
        }

        return new AdminShippingAreaResource($result);
    }

    private function assertGovernorate(ShippingArea $shippingArea): void
    {
        if ($shippingArea->type !== ShippingArea::TYPE_GOVERNORATE || $shippingArea->parent_id !== null) {
            throw (new ModelNotFoundException)->setModel(ShippingArea::class, [$shippingArea->getKey()]);
        }
    }
}
