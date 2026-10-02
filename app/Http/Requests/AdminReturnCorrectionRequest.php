<?php

namespace App\Http\Requests;

use App\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminReturnCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('correction_reason'))) {
            $this->merge(['correction_reason' => trim($this->input('correction_reason'))]);
        }

        $items = $this->input('items');
        if (is_array($items)) {
            $items = array_map(function (mixed $item): mixed {
                if (! is_array($item)) {
                    return $item;
                }
                foreach (['reason', 'note'] as $field) {
                    if (is_string($item[$field] ?? null)) {
                        $item[$field] = trim($item[$field]) ?: null;
                    }
                }
                return $item;
            }, $items);
            $this->merge(['items' => $items]);
        }
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'correction_reason' => ['required', 'string', 'min:1', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.return_receipt_item_id' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.received_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'items.*.restock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'items.*.reason' => ['sometimes', 'nullable', 'string', Rule::enum(ReturnReason::class)],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();
            $this->rejectUnexpectedKeys($validator, $data, ['expected_version', 'correction_reason', 'items']);

            if (is_array($data['items'] ?? null)) {
                foreach ($data['items'] as $index => $item) {
                    $this->rejectUnexpectedKeys($validator, $item, [
                        'return_receipt_item_id', 'received_quantity', 'restock_quantity', 'reason', 'note',
                    ], "items.{$index}");
                    if (is_array($item)
                        && isset($item['received_quantity'], $item['restock_quantity'])
                        && is_numeric($item['received_quantity'])
                        && is_numeric($item['restock_quantity'])
                        && (int) $item['restock_quantity'] > (int) $item['received_quantity']) {
                        $validator->errors()->add("items.{$index}.restock_quantity", 'The restock quantity cannot exceed the received quantity.');
                    }
                }
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

    /** @return array<string, mixed> */
    public function servicePayload(): array
    {
        $data = $this->validated();

        return [
            'expected_revision' => $data['expected_version'],
            'explanation' => $data['correction_reason'],
            'items' => array_map(static function (array $item): array {
                $mapped = [
                    'return_receipt_item_id' => $item['return_receipt_item_id'],
                    'received_quantity' => $item['received_quantity'],
                    'restockable_quantity' => $item['restock_quantity'],
                ];
                foreach (['reason', 'note'] as $field) {
                    if (array_key_exists($field, $item)) {
                        $mapped[$field] = $item[$field];
                    }
                }
                return $mapped;
            }, $data['items']),
        ];
    }

    private function rejectUnexpectedKeys($validator, mixed $value, array $allowed, string $prefix = ''): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $validator->errors()->add($path, 'This field is not allowed.');
        }
    }
}
