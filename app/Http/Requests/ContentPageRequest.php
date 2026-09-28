<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ContentPageRequest extends FormRequest
{
    private const FIELDS = [
        'titleAr', 'titleEn', 'bodyAr', 'bodyEn',
    ];

    private const INTERNAL_FIELDS = [
        'titleAr' => 'title_ar',
        'titleEn' => 'title_en',
        'bodyAr' => 'body_ar',
        'bodyEn' => 'body_en',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titleAr' => ['required', 'string', 'max:255'],
            'titleEn' => ['required', 'string', 'max:255'],
            'bodyAr' => ['required', 'string', 'max:50000'],
            'bodyEn' => ['required', 'string', 'max:50000'],
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
