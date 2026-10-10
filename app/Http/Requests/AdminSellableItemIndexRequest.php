<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSellableItemIndexRequest extends FormRequest
{
    private const QUERY_KEYS = ['archive_state'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archive_state' => ['sometimes', 'string', Rule::in(['unarchived', 'archived', 'all'])],
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
