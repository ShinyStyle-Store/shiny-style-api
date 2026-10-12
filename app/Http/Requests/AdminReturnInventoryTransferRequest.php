<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class AdminReturnInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'target_sellable_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($validator->getData()), ['target_sellable_item_id', 'quantity', 'reason']) as $key) {
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

    /** @return array{target_sellable_item_id: int, quantity: int, reason: string} */
    public function servicePayload(): array
    {
        $data = $this->validated();

        return [
            'target_sellable_item_id' => (int) $data['target_sellable_item_id'],
            'quantity' => (int) $data['quantity'],
            'reason' => $data['reason'],
        ];
    }
}
