<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminProductReviewImageUploadRequest extends FormRequest
{
    private const FIELDS = ['image', 'status', 'alt_ar', 'alt_en', 'caption_ar', 'caption_en'];

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['alt_ar', 'alt_en', 'caption_ar', 'caption_en'] as $field) {
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
            'image' => ['required', 'file'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
            'alt_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'caption_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
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
