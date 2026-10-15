<?php

namespace App\Http\Requests;

use App\Services\SeoSitemapItemsService;
use App\Support\SeoSitemapCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SeoSitemapItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(SeoSitemapItemsService::TYPES)],
            'cursor' => ['sometimes', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.SeoSitemapItemsService::MAX_LIMIT],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), ['type', 'cursor', 'limit']) as $field) {
                $validator->errors()->add((string) $field, 'This query parameter is not allowed.');
            }

            $cursor = $this->query('cursor');
            $type = $this->query('type');
            if (is_string($cursor)
                && is_string($type)
                && in_array($type, SeoSitemapItemsService::TYPES, true)
                && SeoSitemapCursor::decode($cursor, $type) === null) {
                $validator->errors()->add('cursor', 'The cursor is invalid or belongs to another entity type.');
            }
        });
    }
}
