<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminCategoryRequest extends FormRequest
{
    private const FIELDS = [
        'parent_id', 'slug', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'cover_image_url', 'status', 'sort_order',
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

            if ($field === 'slug') {
                $value = strtolower($value);
            } elseif (in_array($field, ['description_ar', 'description_en', 'cover_image_url'], true)
                && $value === '') {
                $value = null;
            }

            $normalized[$field] = $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $category = $this->route('category');
        $category = $category instanceof Category ? $category : null;
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'slug' => [
                $presence, 'string', 'min:1', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($category?->getKey()),
            ],
            'name_ar' => [$presence, 'string', 'min:1', 'max:255'],
            'name_en' => [$presence, 'string', 'min:1', 'max:255'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'cover_image_url' => [
                'sometimes', 'nullable', 'string', 'max:2048', 'url', 'regex:/^https?:\/\//i',
            ],
            'status' => [$presence, 'string', Rule::in(['active', 'inactive'])],
            'sort_order' => [$presence, 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            if ($this->isMethod('PATCH') && $this->all() === []) {
                $validator->errors()->add('category', 'At least one category field is required.');
            }
        });
    }
}
