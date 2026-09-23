<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminProductRequest extends FormRequest
{
    private const FIELDS = [
        'slug', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'features', 'specifications', 'badge', 'status', 'is_featured',
        'published_at', 'category_ids', 'primary_category_id',
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
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if (in_array($field, ['description_ar', 'description_en', 'badge'], true) && $value === '') {
                $value = null;
            }
            if ($field === 'slug') {
                $value = strtolower($value);
            }
            if ($field === 'published_at' && $value === '') {
                $value = null;
            }
            if ($field === 'is_featured' && in_array(strtolower($value), ['true', 'false'], true)) {
                $value = strtolower($value) === 'true';
            }

            $normalized[$field] = $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $product = $this->route('product');
        $product = $product instanceof Product ? $product : null;
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'slug' => [$updating ? 'sometimes' : 'nullable', 'nullable', 'string', 'min:1', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($product?->getKey())],
            'name_ar' => [$presence, 'string', 'min:1', 'max:255'],
            'name_en' => [$presence, 'string', 'min:1', 'max:255'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'features' => ['sometimes', 'nullable', 'array'],
            'specifications' => ['sometimes', 'nullable', 'array'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'inactive', 'active'])],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->whereNull('deleted_at')->where('status', 'active')],
            'primary_category_id' => ['sometimes', 'nullable', 'integer'],
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
                $validator->errors()->add('product', 'At least one product field is required.');
            }

            if ($this->exists('primary_category_id') && $this->input('primary_category_id') !== null) {
                $categoryIds = $this->input('category_ids');
                if (is_array($categoryIds) && ! in_array((int) $this->input('primary_category_id'), array_map('intval', $categoryIds), true)) {
                    $validator->errors()->add('primary_category_id', 'The primary category must be included in category_ids.');
                }
                if (! is_array($categoryIds)) {
                    $validator->errors()->add('category_ids', 'Category ids are required when setting a primary category.');
                }
            }
        });
    }
}
