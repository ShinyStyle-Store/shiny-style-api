<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminMediaUploadRequest extends FormRequest
{
    private const FIELDS = [
        'file', 'kind', 'alt_ar', 'alt_en', 'caption_ar', 'caption_en', 'sort_order', 'is_primary',
    ];

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['alt_ar', 'alt_en', 'caption_ar', 'caption_en'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
                $normalized[$field] = $value === '' ? null : $value;
            }
        }

        $primary = $this->input('is_primary');
        if (is_string($primary) && in_array(strtolower($primary), ['true', 'false'], true)) {
            $normalized['is_primary'] = strtolower($primary) === 'true';
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'kind' => ['required', 'string', Rule::in(['image', 'video'])],
            'alt_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'caption_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }
        });
    }
}
