<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminProductIndexRequest extends FormRequest
{
    private const QUERY_KEYS = [
        'search', 'status', 'category_id', 'featured', 'publication', 'include_archived', 'archive_state',
        'sort', 'per_page', 'page',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['featured', 'include_archived'] as $key) {
            $value = $this->query($key);
            if (! is_string($value)) {
                continue;
            }

            $normalized = match (strtolower($value)) {
                'true', '1' => true,
                'false', '0' => false,
                default => $value,
            };
            $this->query->set($key, $normalized);
        }

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
            'archive_state' => ['sometimes', 'string', Rule::in(['unarchived', 'archived', 'all'])],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest', 'name'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->query->has('archive_state') && $this->query->has('include_archived')) {
                $validator->errors()->add('archive_state', 'archive_state cannot be combined with include_archived.');
            }

            foreach (array_diff(array_keys($this->query->all()), self::QUERY_KEYS) as $key) {
                $validator->errors()->add((string) $key, 'This query parameter is not allowed.');
            }
        });
    }
}
