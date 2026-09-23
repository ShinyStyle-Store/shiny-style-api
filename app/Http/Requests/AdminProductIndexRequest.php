<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminProductIndexRequest extends FormRequest
{
    private const QUERY_KEYS = [
        'search', 'status', 'category_id', 'featured', 'publication', 'include_archived',
        'sort', 'per_page', 'page',
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
            'status' => ['sometimes', 'string', Rule::in(['draft', 'inactive', 'active'])],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'featured' => ['sometimes', 'boolean'],
            'publication' => ['sometimes', 'string', Rule::in(['published', 'unpublished', 'scheduled'])],
            'include_archived' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest', 'name'])],
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
        });
    }
}
