<?php

namespace App\Http\Requests;

use App\Support\SeoRedirectPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SeoRedirectResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['path']) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            $path = $this->input('path');
            if (is_string($path) && SeoRedirectPath::normalize($path) === null) {
                $validator->errors()->add('path', 'The path is not a supported normalized internal route.');
            }
        });
    }
}
