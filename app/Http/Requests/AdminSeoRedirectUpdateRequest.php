<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminSeoRedirectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destinationPath' => ['sometimes', 'string', 'max:2048'],
            'statusCode' => ['sometimes', 'integer', Rule::in([301, 308])],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $mapped = [];
        foreach ([
            'destinationPath' => 'destination_path',
            'statusCode' => 'status_code',
            'isActive' => 'is_active',
        ] as $external => $internal) {
            if (array_key_exists($external, $data)) {
                $mapped[$internal] = $data[$external];
            }
        }

        return $key === null ? $mapped : data_get($mapped, $key, $default);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['destinationPath', 'statusCode', 'isActive']) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }
            if (array_intersect(array_keys($this->all()), ['destinationPath', 'statusCode', 'isActive']) === []) {
                $validator->errors()->add('redirect', 'At least one mutable redirect field is required.');
            }
        });
    }
}
