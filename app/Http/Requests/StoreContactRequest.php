<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContactRequest extends FormRequest
{
    private const FIELDS = [
        'support_phone', 'support_whatsapp', 'support_email',
        'address_ar', 'address_en', 'google_maps_url',
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
                if (in_array($field, ['support_phone', 'support_whatsapp', 'support_email', 'google_maps_url'], true)
                    && $value === '') {
                    $value = null;
                }
            }
            $normalized[$field] = $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'support_phone' => ['required', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s().-]{6,29}$/'],
            'support_whatsapp' => ['required', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s().-]{6,29}$/'],
            'support_email' => ['required', 'nullable', 'email', 'max:255'],
            'address_ar' => ['required', 'string', 'min:1', 'max:5000'],
            'address_en' => ['required', 'string', 'min:1', 'max:5000'],
            'google_maps_url' => ['required', 'nullable', 'string', 'max:2048', 'url:https'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            $url = $this->input('google_maps_url');
            if (is_string($url) && $url !== '') {
                $parts = parse_url($url);
                $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
                $port = is_array($parts) ? ($parts['port'] ?? null) : null;
                $isAllowedHost = in_array($host, ['maps.google.com', 'maps.app.goo.gl'], true);
                $hasCredentials = is_array($parts) && (isset($parts['user']) || isset($parts['pass']));
                $hasNonStandardPort = $port !== null && (int) $port !== 443;

                if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                    || ! $isAllowedHost || $hasCredentials || $hasNonStandardPort) {
                    $validator->errors()->add('google_maps_url', 'The Google Maps URL must use HTTPS.');
                }
            }
        });
    }
}
