<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ContentPageRequest extends FormRequest
{
    private const FIELDS = [
        'title_ar', 'title_en', 'body_ar', 'body_en',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['required', 'string', 'max:50000'],
            'body_en' => ['required', 'string', 'max:50000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            foreach (self::FIELDS as $field) {
                if (is_string($this->input($field)) && trim($this->input($field)) === '') {
                    $validator->errors()->add($field, 'This field cannot be blank.');
                }
            }
        });
    }
}
