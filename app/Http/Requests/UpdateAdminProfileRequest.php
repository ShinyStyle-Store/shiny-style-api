<?php

namespace App\Http\Requests;

use App\Support\EgyptianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if (is_string($this->input('name'))) {
            $data['name'] = trim($this->input('name'));
        }
        if ($this->exists('phone')) {
            $data['phone'] = EgyptianPhone::normalize($this->input('phone'));
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'phone' => [
                'sometimes', 'nullable', 'string', 'regex:/^01[0125][0-9]{8}$/',
                Rule::unique('users', 'phone')->ignore($this->user()?->getKey()),
            ],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();
            $allowed = ['name', 'phone'];

            foreach (array_diff(array_keys($data), $allowed) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }

            if (count(array_intersect(array_keys($data), $allowed)) === 0) {
                $validator->errors()->add('profile', 'At least one profile field is required.');
            }
        });
    }
}
