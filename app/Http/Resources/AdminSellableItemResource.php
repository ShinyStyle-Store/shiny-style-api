<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSellableItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'productId' => $this->product_id,
            'sku' => $this->sku,
            'price' => (string) $this->price,
            'originalPrice' => $this->original_price === null ? null : (string) $this->original_price,
            'stockQuantity' => (int) $this->stock_quantity,
            'reservedQuantity' => (int) $this->reserved_quantity,
            'availableQuantity' => $this->availableQuantity(),
            'status' => $this->status,
            'isDefault' => (bool) $this->is_default,
            'sortOrder' => (int) $this->sort_order,
            'optionValues' => $this->whenLoaded('optionValues', fn () => $this->optionValues->map(fn ($value): array => [
                'id' => $value->getKey(),
                'optionId' => $value->product_option_id,
                'optionCode' => $value->option->code,
                'optionNameAr' => $value->option->name_ar,
                'optionNameEn' => $value->option->name_en,
                'code' => $value->code,
                'valueAr' => $value->value_ar,
                'valueEn' => $value->value_en,
                'sortOrder' => (int) $value->sort_order,
            ])->values()),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'archivedAt' => $this->deleted_at?->toISOString(),
        ];
    }
}
