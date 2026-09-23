<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminProductReviewImageBulkRequest extends FormRequest
{
    private const FIELDS = ['images', 'status', 'alt_ar', 'alt_en'];

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['alt_ar', 'alt_en'] as $field) {
            if (is_string($value = $this->input($field))) {
                $normalized[$field] = trim($value) === '' ? null : trim($value);
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
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['required', 'file'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
            'alt_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_en' => ['sometimes', 'nullable', 'string', 'max:255'],
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
