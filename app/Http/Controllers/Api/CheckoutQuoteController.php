<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CheckoutQuoteResource;
use App\Services\CheckoutQuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CheckoutQuoteController extends Controller
{
    public function store(Request $request, CheckoutQuoteService $quoteService): CheckoutQuoteResource
    {
        $validator = Validator::make($request->all(), [
            'shipping_area_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array'],
            'items.*.sellable_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $validator->after(function ($validator): void {
            $data = $validator->getData();
            $allowedTopLevelKeys = ['shipping_area_id', 'items'];

            foreach (array_diff(array_keys($data), $allowedTopLevelKeys) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }

            if (! is_array($data['items'] ?? null)) {
                return;
            }

            foreach ($data['items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (array_diff(array_keys($item), ['sellable_item_id', 'quantity']) as $key) {
                    $validator->errors()->add(
                        "items.{$index}.{$key}",
                        'This field is not allowed.',
                    );
                }
            }
        });

        $validated = $validator->validate();

        return new CheckoutQuoteResource($quoteService->quote(
            (int) $validated['shipping_area_id'],
            $validated['items'],
        ));
    }
}
