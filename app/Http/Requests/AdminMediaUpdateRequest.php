<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminMediaUpdateRequest extends FormRequest
{
    private const FIELDS = ['alt_ar', 'alt_en', 'caption_ar', 'caption_en', 'sort_order'];

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

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alt_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'caption_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = array_keys($this->all());
            foreach (array_diff($fields, self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            if (array_intersect($fields, self::FIELDS) === []) {
                $validator->errors()->add('media', 'At least one media metadata field is required.');
            }
        });
    }
}
