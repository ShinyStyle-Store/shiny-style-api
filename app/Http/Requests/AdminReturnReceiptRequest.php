<?php

namespace App\Http\Requests;

use App\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminReturnReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['return_requested_at', 'items_received_at', 'note', 'default_reason'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
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
            'return_requested_at' => ['sometimes', 'nullable', 'date'],
            'items_received_at' => ['sometimes', 'nullable', 'date'],
            'default_reason' => ['sometimes', 'nullable', 'string', Rule::enum(ReturnReason::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.received_quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.restock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'items.*.reason' => ['sometimes', 'nullable', 'string', Rule::enum(ReturnReason::class)],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();
            $this->rejectUnexpectedKeys($validator, $data, [
                'return_requested_at', 'items_received_at', 'default_reason', 'note', 'items',
            ]);

            if (is_array($data['items'] ?? null)) {
                foreach ($data['items'] as $index => $item) {
                    $this->rejectUnexpectedKeys(
                        $validator,
                        $item,
                        ['order_item_id', 'received_quantity', 'restock_quantity', 'reason', 'note'],
                        "items.{$index}",
                    );
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
        $payload = [];

        foreach (['default_reason', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (array_key_exists('return_requested_at', $data)) {
            $payload['request_received_at'] = $data['return_requested_at'];
        }
        if (array_key_exists('items_received_at', $data)) {
            $payload['received_at'] = $data['items_received_at'];
        }
        $payload['items'] = array_map(static function (array $item): array {
            $mapped = [
                'order_item_id' => $item['order_item_id'],
                'received_quantity' => $item['received_quantity'],
                'restockable_quantity' => $item['restock_quantity'],
            ];
            foreach (['reason', 'note'] as $field) {
                if (array_key_exists($field, $item)) {
                    $mapped[$field] = $item[$field];
                }
            }
            return $mapped;
        }, $data['items']);

        return $payload;
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
