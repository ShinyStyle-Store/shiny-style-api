<?php

namespace App\Http\Requests;

use App\Models\SocialLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SocialLinkRequest extends FormRequest
{
    private const FIELDS = [
        'platformCode', 'url', 'isEnabled', 'sortOrder',
    ];

    private const INTERNAL_FIELDS = [
        'platformCode' => 'platform_code',
        'isEnabled' => 'is_enabled',
        'sortOrder' => 'sort_order',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
                if (in_array($field, ['platformCode', 'url'], true)) {
                    $value = $field === 'platformCode' ? strtolower($value) : $value;
                }
                if ($field === 'isEnabled' && in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
                    $value = in_array(strtolower($value), ['true', '1'], true);
                }
            }
            $normalized[$field] = $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $presence = $updating ? 'sometimes' : 'required';
        $link = $this->route('socialLink');
        $link = $link instanceof SocialLink ? $link : null;

        return [
            'platformCode' => [
                $presence,
                'string',
                'max:50',
                Rule::in(array_keys(config('store.social_platforms', []))),
                Rule::unique('social_links', 'platform_code')->ignore($link?->getKey()),
            ],
            'url' => [$presence, 'string', 'max:2048', 'url:https'],
            'isEnabled' => [$presence, 'boolean'],
            'sortOrder' => [$presence, 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $inputFields = array_keys($this->all());
            foreach (array_diff($inputFields, self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            if ($this->isMethod('PATCH') && count(array_intersect($inputFields, self::FIELDS)) === 0) {
                $validator->errors()->add('social_link', 'At least one social link field is required.');
            }

            $link = $this->route('socialLink');
            $link = $link instanceof SocialLink ? $link : null;
            $platform = (string) ($this->input('platformCode') ?? $link?->platform_code);
            $url = $this->input('url') ?? $link?->url;
            if (! is_string($url) || $url === '' || ! is_string($platform)) {
                return;
            }

            $parts = parse_url($url);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $allowedHosts = config("store.social_platforms.{$platform}.hosts", []);
            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
            $requiredPathPattern = config("store.social_platforms.{$platform}.required_path_pattern");
            $forbiddenPathPattern = config("store.social_platforms.{$platform}.forbidden_path_pattern");
            $hasCredentials = is_array($parts) && (isset($parts['user']) || isset($parts['pass']));
            $port = is_array($parts) ? ($parts['port'] ?? null) : null;
            $hasNonStandardPort = $port !== null && (int) $port !== 443;
            $hasRequiredPath = $requiredPathPattern === null
                || preg_match($requiredPathPattern, $path) === 1;
            $hasForbiddenPath = $forbiddenPathPattern !== null
                && preg_match($forbiddenPathPattern, $path) === 1;

            if (! is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || ! in_array($host, $allowedHosts, true)
                || $hasCredentials
                || $hasNonStandardPort
                || ! $hasRequiredPath
                || $hasForbiddenPath) {
                $validator->errors()->add('url', 'The URL is not valid for the selected social platform.');
            }
        });
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        foreach (self::INTERNAL_FIELDS as $external => $internal) {
            if (array_key_exists($external, $data)) {
                $data[$internal] = $data[$external];
                unset($data[$external]);
            }
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }
}
