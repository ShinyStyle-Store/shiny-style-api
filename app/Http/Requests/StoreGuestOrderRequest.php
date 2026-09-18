<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreGuestOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');
        $shipping = $this->input('shipping');

        if (is_array($customer)) {
            $customer['name'] = is_string($customer['name'] ?? null)
                ? trim($customer['name'])
                : ($customer['name'] ?? null);
            $customer['phone'] = $this->normalizePhone($customer['phone'] ?? null);
            $customer['alternate_phone'] = $this->normalizePhone($customer['alternate_phone'] ?? null);
            $this->merge(['customer' => $customer]);
        }

        if (is_array($shipping)) {
            foreach (['address', 'landmark'] as $field) {
                if (is_string($shipping[$field] ?? null)) {
                    $shipping[$field] = trim($shipping[$field]) ?: null;
                }
            }
            $this->merge(['shipping' => $shipping]);
        }

        if (is_string($this->input('order_note'))) {
            $this->merge(['order_note' => trim($this->input('order_note')) ?: null]);
        }

        if (is_string($this->input('payment_method'))) {
            $this->merge(['payment_method' => strtolower(trim($this->input('payment_method')))]);
        }
    }

    public function rules(): array
    {
        return [
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:150'],
            'customer.phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'customer.alternate_phone' => ['nullable', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'shipping' => ['required', 'array'],
            'shipping.shipping_area_id' => ['required', 'integer'],
            'shipping.address' => ['required', 'string', 'max:1000'],
            'shipping.landmark' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array'],
            'items.*.sellable_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'payment_method' => ['required', 'in:cash_on_delivery'],
            'order_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();
            $this->rejectUnexpectedKeys($validator, $data, ['customer', 'shipping', 'items', 'payment_method', 'order_note']);
            $this->rejectUnexpectedKeys($validator, $data['customer'] ?? null, ['name', 'phone', 'alternate_phone'], 'customer');
            $this->rejectUnexpectedKeys($validator, $data['shipping'] ?? null, ['shipping_area_id', 'address', 'landmark'], 'shipping');

            if (is_array($data['items'] ?? null)) {
                foreach ($data['items'] as $index => $item) {
                    $this->rejectUnexpectedKeys($validator, $item, ['sellable_item_id', 'quantity'], "items.{$index}");
                }
            }

            $phone = $data['customer']['phone'] ?? null;
            $alternatePhone = $data['customer']['alternate_phone'] ?? null;
            if ($alternatePhone !== null && $phone !== null && $alternatePhone === $phone) {
                $validator->errors()->add('customer.alternate_phone', 'The alternate phone must differ from the phone.');
            }

            $idempotencyKey = trim((string) $this->header('Idempotency-Key'));
            if (! Str::isUuid($idempotencyKey)) {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must be a valid UUID.');
            }
        });
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
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

    private function normalizePhone(mixed $phone): mixed
    {
        if (! is_string($phone)) {
            return $phone;
        }

        $phone = strtr($phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $phone = preg_replace('/[\s\-()]+/', '', trim($phone)) ?? $phone;

        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '+20')) {
            $phone = '0'.substr($phone, 3);
        } elseif (str_starts_with($phone, '0020')) {
            $phone = '0'.substr($phone, 4);
        }

        return $phone;
    }
}
