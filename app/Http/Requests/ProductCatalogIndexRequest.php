<?php

namespace App\Http\Requests;

use App\Support\ExactMoney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductCatalogIndexRequest extends FormRequest
{
    private const QUERY_PARAMETERS = [
        'q',
        'category',
        'page',
        'per_page',
        'sort',
        'min_price',
        'max_price',
        'offer',
        'colors',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'min_price' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'max_price' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'offer' => ['sometimes', 'string', Rule::in(['active'])],
            'colors' => ['sometimes', 'array', 'max:20'],
            'colors.*' => ['required', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct:strict'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), self::QUERY_PARAMETERS) as $parameter) {
                $validator->errors()->add((string) $parameter, 'This query parameter is not allowed.');
            }

            $minimum = $this->query('min_price');
            $maximum = $this->query('max_price');

            if ($validator->errors()->hasAny(['min_price', 'max_price'])
                || ! is_string($minimum)
                || ! is_string($maximum)) {
                return;
            }

            if (ExactMoney::toMinorUnitInteger($minimum) > ExactMoney::toMinorUnitInteger($maximum)) {
                $validator->errors()->add('min_price', 'The minimum price must not exceed the maximum price.');
            }
        });
    }
}
