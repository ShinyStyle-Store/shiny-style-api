<?php

namespace App\Http\Requests;

use App\Enums\RefundTransferMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminRefundRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['amount', 'currency', 'transfer_method', 'transferred_at', 'transaction_reference', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3'],
            'transfer_method' => ['required', 'string', Rule::enum(RefundTransferMethod::class)],
            'transferred_at' => ['required', 'date'],
            'transaction_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'evidence' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [
                'amount', 'currency', 'transfer_method', 'transferred_at',
                'transaction_reference', 'note', 'evidence',
            ]) as $key) {
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
