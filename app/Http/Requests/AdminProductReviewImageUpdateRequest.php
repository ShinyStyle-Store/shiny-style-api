<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminProductReviewImageUpdateRequest extends FormRequest
{
    private const FIELDS = ['image', 'status', 'alt_ar', 'alt_en', 'caption_ar', 'caption_en', '_method'];

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
            'image' => ['sometimes', 'file'],
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

            $fields = array_intersect(array_keys($this->all()), array_diff(self::FIELDS, ['image', '_method']));
            if (! $this->hasFile('image') && $fields === []) {
                $validator->errors()->add('reviewImage', 'At least one review image field is required.');
            }

            if ($this->hasFile('image') && array_key_exists('status', $this->all())) {
                $validator->errors()->add('status', 'Status cannot be changed while replacing the image.');
            }
            if ($this->getRealMethod() === 'POST' && ! $this->hasFile('image')) {
                $validator->errors()->add('image', 'The image field is required when replacing a review image.');
            }
            if ($this->getRealMethod() === 'PATCH' && $this->hasFile('image')) {
                $validator->errors()->add('image', 'Image replacement must use POST multipart form data.');
            }
            if ($this->has('_method') && (string) $this->input('_method') !== 'PATCH') {
                $validator->errors()->add('_method', 'Only PATCH method spoofing is supported.');
            }
        });
    }
}
