<?php

namespace App\Http\Requests;

use App\Models\ProductOptionValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminProductOptionValueRequest extends FormRequest
{
    private const FIELDS = ['code', 'value_ar', 'value_en', 'metadata', 'sort_order'];

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
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'code' => [$presence, 'string', 'min:1', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'value_ar' => [$presence, 'string', 'min:1', 'max:255'],
            'value_en' => [$presence, 'string', 'min:1', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
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
                $validator->errors()->add('value', 'At least one value field is required.');
            }

            $optionId = (int) $this->route('option');
            $currentValue = $this->route('value');
            $currentValueId = $currentValue instanceof ProductOptionValue ? $currentValue->getKey() : (int) $currentValue;

            $this->addDuplicateError($validator, 'code', strtolower(trim((string) $this->input('code'))), $optionId, $currentValueId);
            $this->addDuplicateError($validator, 'value_ar', trim((string) $this->input('value_ar')), $optionId, $currentValueId);
            $this->addDuplicateError($validator, 'value_en', mb_strtolower(trim((string) $this->input('value_en'))), $optionId, $currentValueId);
        });
    }

    private function addDuplicateError(Validator $validator, string $field, string $value, int $optionId, int $currentValueId): void
    {
        if (! $this->exists($field) || $value === '') {
            return;
        }

        $column = match ($field) {
            'code' => 'code',
            'value_ar' => 'value_ar_normalized',
            default => 'value_en_normalized',
        };

        $query = ProductOptionValue::withTrashed()
            ->where('product_option_id', $optionId)
            ->where($column, $value);

        if ($this->isMethod('PATCH')) {
            $query->whereKeyNot($currentValueId);
        }

        if ($query->exists()) {
            $validator->errors()->add($field, 'The '.$field.' has already been taken within this parent.');
        }
    }
}
