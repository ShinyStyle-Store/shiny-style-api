<?php

namespace App\Http\Requests;

use App\Models\MediaAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminSeoRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(['ar', 'en'])],
            'metaTitle' => ['sometimes', 'nullable', 'string', 'max:180'],
            'metaDescription' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonicalPath' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'robots' => ['sometimes', 'array:index,follow', 'min:1'],
            'robots.index' => ['sometimes', 'nullable', 'boolean'],
            'robots.follow' => ['sometimes', 'nullable', 'boolean'],
            'openGraph' => ['sometimes', 'array:title,description,imageId', 'min:1'],
            'openGraph.title' => ['sometimes', 'nullable', 'string', 'max:180'],
            'openGraph.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'openGraph.imageId' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('media_assets', 'id')->where('media_type', 'image')->whereNull('deleted_at'),
            ],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $seo = ['locale' => $data['locale']];
        $fields = [
            'metaTitle' => 'meta_title',
            'metaDescription' => 'meta_description',
            'canonicalPath' => 'canonical_path',
        ];

        foreach ($fields as $external => $internal) {
            if (array_key_exists($external, $data)) {
                $seo[$internal] = $data[$external];
            }
        }

        if (array_key_exists('robots', $data)) {
            foreach (['index' => 'robots_index', 'follow' => 'robots_follow'] as $external => $internal) {
                if (array_key_exists($external, $data['robots'])) {
                    $seo[$internal] = $data['robots'][$external];
                }
            }
        }

        if (array_key_exists('openGraph', $data)) {
            foreach (['title' => 'og_title', 'description' => 'og_description', 'imageId' => 'og_image_id'] as $external => $internal) {
                if (array_key_exists($external, $data['openGraph'])) {
                    $seo[$internal] = $data['openGraph'][$external];
                }
            }
        }

        return $key === null ? $seo : data_get($seo, $key, $default);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['locale', 'metaTitle', 'metaDescription', 'canonicalPath', 'robots', 'openGraph'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            foreach (['metaTitle', 'metaDescription', 'openGraph.title', 'openGraph.description'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && trim($value) === '') {
                    $validator->errors()->add($field, 'This field cannot be blank; use null to clear it.');
                }
            }

            foreach (['robots' => ['index', 'follow'], 'openGraph' => ['title', 'description', 'imageId']] as $parent => $keys) {
                $value = $this->input($parent);
                if (is_array($value)) {
                    foreach (array_diff(array_keys($value), $keys) as $key) {
                        $validator->errors()->add("{$parent}.{$key}", 'This field is not allowed.');
                    }
                }
            }

            if (array_intersect(array_keys($this->all()), array_diff($allowed, ['locale'])) === []) {
                $validator->errors()->add('seo', 'At least one SEO field is required.');
            }

            foreach (['openGraph.imageId' => $this->input('openGraph.imageId')] as $field => $value) {
                if ($value !== null && $value !== '' && is_numeric($value)
                    && ! MediaAsset::query()->whereKey((int) $value)->where('media_type', 'image')->whereNull('deleted_at')->exists()) {
                    $validator->errors()->add($field, 'The selected media asset must be an available image.');
                }
            }
        });
    }
}
