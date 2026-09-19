<?php

namespace App\Http\Requests;

use App\Support\AdminPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'max:1024', 'confirmed'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();

            foreach (array_diff(array_keys($data), ['current_password', 'password', 'password_confirmation']) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }

            foreach (AdminPasswordPolicy::violations($data['password'] ?? null) as $violation) {
                $validator->errors()->add('password', $violation);
            }
        });
    }
}
