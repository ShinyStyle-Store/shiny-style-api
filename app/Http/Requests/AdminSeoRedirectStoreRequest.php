<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSeoRedirectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sourcePath' => ['required', 'string', 'max:2048'],
            'destinationPath' => ['required', 'string', 'max:2048'],
            'statusCode' => ['required', 'integer', Rule::in([301, 308])],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $data = [
            'source_path' => $data['sourcePath'],
            'destination_path' => $data['destinationPath'],
            'status_code' => (int) $data['statusCode'],
        ];

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['sourcePath', 'destinationPath', 'statusCode']) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }
        });
    }
}
