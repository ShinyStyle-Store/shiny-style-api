<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminSellableItemRequest extends FormRequest
{
    private const FIELDS = [
        'sku', 'price', 'original_price', 'stock_quantity', 'status', 'is_default', 'sort_order', 'option_value_ids',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = $field === 'sku' ? trim($value) : trim($value);
            }
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $money = ['string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'];

        return [
            'sku' => [$presence, 'string', 'min:1', 'max:255'],
            'price' => array_merge([$presence], $money),
            'original_price' => ['sometimes', 'nullable', ...$money],
            'stock_quantity' => [$presence, 'integer', 'min:0', 'max:2147483647'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'option_value_ids' => [$this->isMethod('PATCH') ? 'sometimes' : 'present', 'array'],
            'option_value_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        unset($data['_method']);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }
            if ($this->isMethod('PATCH') && count(array_intersect(array_keys($this->all()), self::FIELDS)) === 0) {
                $validator->errors()->add('variant', 'At least one variant field is required.');
            }
        });
    }
}
