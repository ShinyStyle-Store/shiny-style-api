<?php

namespace App\Http\Requests;

use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminOrderIndexRequest extends FormRequest
{
    private const QUERY_KEYS = [
        'search', 'status', 'contact_status', 'payment_method', 'payment_status',
        'shipping_area_id', 'date_from', 'date_to', 'sort', 'per_page', 'page',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('search'))) {
            $search = trim($this->query('search'));
            $this->query->set('search', $search === '' ? null : preg_replace('/\s+/u', ' ', $search));
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::notIn(['']), Rule::enum(OrderStatus::class)],
            'contact_status' => ['sometimes', 'string', Rule::notIn(['']), Rule::enum(ContactStatus::class)],
            'payment_method' => ['sometimes', 'string', Rule::notIn(['']), Rule::enum(PaymentMethod::class)],
            'payment_status' => ['sometimes', 'string', Rule::notIn(['']), Rule::enum(PaymentStatus::class)],
            'shipping_area_id' => ['sometimes', 'required', 'integer', 'exists:shipping_areas,id'],
            'date_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'sort' => ['sometimes', 'string', Rule::notIn(['']), Rule::in(['newest', 'oldest'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->query->all()), self::QUERY_KEYS) as $key) {
                $validator->errors()->add((string) $key, 'This query parameter is not allowed.');
            }

            $dateFrom = $validator->getData()['date_from'] ?? null;
            $dateTo = $validator->getData()['date_to'] ?? null;
            if (is_string($dateFrom) && is_string($dateTo)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
                && $dateTo < $dateFrom) {
                $validator->errors()->add('date_to', 'The date to must be equal to or later than date from.');
            }
        });
    }
}
