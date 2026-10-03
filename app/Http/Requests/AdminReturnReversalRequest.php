<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class AdminReturnReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reversal_reason'))) {
            $this->merge(['reversal_reason' => trim($this->input('reversal_reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'reversal_reason' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($validator->getData()), ['expected_version', 'reversal_reason']) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }

            if (! Str::isUuid(trim((string) $this->header('Idempotency-Key')))) {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must be a valid UUID.');
            }
        });
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }
}
