<?php

namespace App\Http\Requests;

use App\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminOrderReturnDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(ReturnReason::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();
            foreach (array_diff(array_keys($data), ['reason', 'note']) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }

            if (($data['reason'] ?? null) === ReturnReason::Other->value
                && (! is_string($data['note'] ?? null) || trim($data['note']) === '')) {
                $validator->errors()->add('note', 'A meaningful note is required for the other reason.');
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
