<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class InitiateCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $body = $this->isJson()
                ? $this->json()->all()
                : $this->request->all();

            if ($body !== []) {
                $validator->errors()->add('body', 'The payment initiation body must be empty.');
            }

            $key = trim((string) $this->header('Idempotency-Key'));
            if (! Str::isUuid($key)) {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must be a valid UUID.');
            }
        });
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }
}
