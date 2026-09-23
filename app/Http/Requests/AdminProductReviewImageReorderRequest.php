<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminProductReviewImageReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array', 'size:2'],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['items']) !== []) {
                $validator->errors()->add('items', 'Only items are allowed.');
            }

            $items = $this->input('items');
            if (! is_array($items)) {
                return;
            }
            $ids = array_map(static fn ($item) => is_array($item) ? (int) ($item['id'] ?? 0) : 0, $items);
            $orders = array_map(static fn ($item) => is_array($item) ? (int) ($item['sort_order'] ?? -1) : -1, $items);
            foreach ($items as $index => $item) {
                if (is_array($item) && array_diff(array_keys($item), ['id', 'sort_order']) !== []) {
                    $validator->errors()->add("items.{$index}", 'Only id and sort_order are allowed.');
                }
            }
            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('items', 'Review image IDs must be unique.');
            }
            if (count($orders) !== count(array_unique($orders))) {
                $validator->errors()->add('items', 'Review image sort orders must be unique.');
            }
        });
    }
}
