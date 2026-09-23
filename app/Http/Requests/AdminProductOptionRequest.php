<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminProductOptionRequest extends FormRequest
{
    private const FIELDS = ['code', 'name_ar', 'name_en', 'sort_order'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }
            $normalized[$field] = trim($this->input($field));
            if ($field === 'code') {
                $normalized[$field] = strtolower($normalized[$field]);
            }
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$presence, 'string', 'min:1', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'name_ar' => [$presence, 'string', 'min:1', 'max:255'],
            'name_en' => [$presence, 'string', 'min:1', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
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
                $validator->errors()->add('option', 'At least one option field is required.');
            }

            $product = $this->route('product');
            $productId = $product instanceof Product ? $product->getKey() : (int) $product;
            $currentOption = $this->route('option');
            $currentOptionId = $currentOption instanceof ProductOption ? $currentOption->getKey() : (int) $currentOption;

            $this->addDuplicateError($validator, 'code', strtolower(trim((string) $this->input('code'))), $productId, $currentOptionId);
            $this->addDuplicateError($validator, 'name_ar', trim((string) $this->input('name_ar')), $productId, $currentOptionId);
            $this->addDuplicateError($validator, 'name_en', mb_strtolower(trim((string) $this->input('name_en'))), $productId, $currentOptionId);
        });
    }

    private function addDuplicateError(Validator $validator, string $field, string $value, int $productId, int $currentOptionId): void
    {
        if (! $this->exists($field) || $value === '') {
            return;
        }

        $column = match ($field) {
            'code' => 'code',
            'name_ar' => 'name_ar_normalized',
            default => 'name_en_normalized',
        };

        $query = ProductOption::withTrashed()
            ->where('product_id', $productId)
            ->where($column, $value);

        if ($this->isMethod('PATCH')) {
            $query->whereKeyNot($currentOptionId);
        }

        if ($query->exists()) {
            $validator->errors()->add($field, 'The '.$field.' has already been taken within this parent.');
        }
    }
}
