<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContactRequest extends FormRequest
{
    private const FIELDS = [
        'supportPhone', 'supportWhatsapp', 'supportEmail',
        'addressAr', 'addressEn', 'googleMapsUrl',
    ];

    private const INTERNAL_FIELDS = [
        'supportPhone' => 'support_phone',
        'supportWhatsapp' => 'support_whatsapp',
        'supportEmail' => 'support_email',
        'addressAr' => 'address_ar',
        'addressEn' => 'address_en',
        'googleMapsUrl' => 'google_maps_url',
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
                if (in_array($field, ['supportPhone', 'supportWhatsapp', 'supportEmail', 'googleMapsUrl'], true)
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
            'supportPhone' => ['required', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s().-]{6,29}$/'],
            'supportWhatsapp' => ['required', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s().-]{6,29}$/'],
            'supportEmail' => ['required', 'nullable', 'email', 'max:255'],
            'addressAr' => ['required', 'string', 'min:1', 'max:5000'],
            'addressEn' => ['required', 'string', 'min:1', 'max:5000'],
            'googleMapsUrl' => ['required', 'nullable', 'string', 'max:2048', 'url:https'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            $url = $this->input('googleMapsUrl');
            if (is_string($url) && $url !== '') {
                $parts = parse_url($url);
                $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
                $port = is_array($parts) ? ($parts['port'] ?? null) : null;
                $isAllowedHost = in_array($host, ['maps.google.com', 'maps.app.goo.gl'], true);
                $hasCredentials = is_array($parts) && (isset($parts['user']) || isset($parts['pass']));
                $hasNonStandardPort = $port !== null && (int) $port !== 443;

                if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                    || ! $isAllowedHost || $hasCredentials || $hasNonStandardPort) {
                    $validator->errors()->add('googleMapsUrl', 'The Google Maps URL must use HTTPS.');
                }
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
