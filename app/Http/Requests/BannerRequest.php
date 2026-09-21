<?php

namespace App\Http\Requests;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BannerRequest extends FormRequest
{
    private const FIELDS = [
        'title_ar', 'title_en', 'description_ar', 'description_en',
        'cta_text_ar', 'cta_text_en', 'cta_type', 'cta_target', 'is_active', 'sort_order',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
                if (in_array($field, [
                    'title_ar', 'title_en', 'description_ar', 'description_en',
                    'cta_text_ar', 'cta_text_en', 'cta_type', 'cta_target',
                ], true) && $value === '') {
                    $value = null;
                }
                $normalized[$field] = $value;
            }
        }

        foreach (['is_active'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
                $normalized[$field] = in_array(strtolower($value), ['true', '1'], true);
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $required = 'sometimes';

        return [
            'title_ar' => [$required, 'nullable', 'string', 'max:255'],
            'title_en' => [$required, 'nullable', 'string', 'max:255'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'cta_text_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cta_text_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cta_type' => ['sometimes', 'nullable', 'string', Rule::in(['product', 'category', 'url'])],
            'cta_target' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'image' => $this->isMethod('POST') ? ['required', 'file'] : ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = $this->isMethod('POST') ? [...self::FIELDS, 'image'] : self::FIELDS;
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            $current = $this->isMethod('PATCH') && $this->route('banner') instanceof Banner
                ? $this->route('banner')->only(self::FIELDS)
                : [];
            $data = array_replace($current, $this->all());
            $titleAr = trim((string) ($data['title_ar'] ?? ''));
            $titleEn = trim((string) ($data['title_en'] ?? ''));
            if ($titleAr === '' && $titleEn === '') {
                $validator->errors()->add('title_ar', 'At least one title is required.');
            }

            $text = trim((string) ($data['cta_text_ar'] ?? '')) !== '' || trim((string) ($data['cta_text_en'] ?? '')) !== '';
            $type = $data['cta_type'] ?? null;
            $target = trim((string) ($data['cta_target'] ?? ''));
            if (! $text && ($type !== null || $target !== '')) {
                $validator->errors()->add('cta_text_ar', 'CTA text is required when a CTA is configured.');
            }
            if ($text && ($type === null || $target === '')) {
                $validator->errors()->add('cta_type', 'CTA type and target are required when CTA text is configured.');
            }
            if ($type === 'product' && (! Product::query()->where('slug', $target)->whereNull('deleted_at')->exists())) {
                $validator->errors()->add('cta_target', 'The selected product is unavailable.');
            }
            if ($type === 'category' && (! Category::query()->where('slug', $target)->whereNull('deleted_at')->exists())) {
                $validator->errors()->add('cta_target', 'The selected category is unavailable.');
            }
            if ($type === 'url' && ! $this->safeUrl($target)) {
                $validator->errors()->add('cta_target', 'The CTA URL is not safe.');
            }
        });
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();

        return $key === null ? $data : data_get($data, $key, $default);
    }

    private function safeUrl(string $target): bool
    {
        if ($target === '' || preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $target) === 1) {
            return false;
        }
        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            return true;
        }
        $parts = parse_url($target);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'], $parts['pass'], $parts['fragment']);
    }
}
