<?php

namespace App\Http\Requests;

use App\Models\ShippingArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminShippingAreaRequest extends FormRequest
{
    private const FIELDS = [
        'code', 'nameAr', 'nameEn', 'shippingFee', 'isActive', 'sortOrder',
    ];

    private const INTERNAL_FIELDS = [
        'nameAr' => 'name_ar',
        'nameEn' => 'name_en',
        'shippingFee' => 'shipping_fee',
        'isActive' => 'is_active',
        'sortOrder' => 'sort_order',
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
                $value = trim($value);
                if ($field === 'code') {
                    $value = strtolower($value);
                } elseif ($field === 'isActive' && in_array(strtolower($value), ['true', 'false'], true)) {
                    $value = strtolower($value) === 'true';
                }
            }

            if ($this->exists($field)) {
                $normalized[$field] = $value;
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $presence = $updating ? 'sometimes' : 'required';
        $shippingArea = $this->route('shippingArea');
        $shippingArea = $shippingArea instanceof ShippingArea ? $shippingArea : null;

        return [
            'code' => [
                $presence, 'string', 'min:1', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('shipping_areas', 'code')->ignore($shippingArea?->getKey()),
            ],
            'nameAr' => [$presence, 'string', 'min:1', 'max:255'],
            'nameEn' => [$presence, 'string', 'min:1', 'max:255'],
            'shippingFee' => [
                $presence, 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2',
            ],
            'isActive' => [$presence, 'boolean'],
            'sortOrder' => [$presence, 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $inputFields = array_keys($this->all());

            foreach (array_diff($inputFields, self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            if ($this->isMethod('PATCH') && count(array_intersect($inputFields, self::FIELDS)) === 0) {
                $validator->errors()->add('shipping_area', 'At least one shipping area field is required.');
            }
        });
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        foreach (self::INTERNAL_FIELDS as $external => $internal) {
            if (array_key_exists($external, $data)) {
                $data[$internal] = $data[$external];
                unset($data[$external]);
            }
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }
}
