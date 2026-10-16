<?php

namespace App\Http\Requests;

use App\Models\MediaAsset;
use App\Services\SeoPublicImageService;
use App\Support\SeoSettingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminSeoSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1', 'max:50'],
            'settings.*' => ['required', 'array:key,locale,value'],
            'settings.*.key' => ['required', 'string', Rule::in(array_keys(SeoSettingCatalog::DEFINITIONS))],
            'settings.*.locale' => ['sometimes', 'nullable', 'string', 'size:2'],
            'settings.*.value' => ['present'],
        ];
    }

    /** @return array<int, array{key: string, locale: string, value: mixed}> */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $settings = [];

        foreach ($data['settings'] as $setting) {
            $definition = SeoSettingCatalog::definition($setting['key']);
            if ($definition === null) {
                continue;
            }

            $settings[] = [
                'key' => $definition['key'],
                'locale' => $definition['localized'] ? (string) ($setting['locale'] ?? '') : '',
                'value' => $setting['value'],
            ];
        }

        return $key === null ? $settings : data_get($settings, $key, $default);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['settings']) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            $seen = [];
            $settings = $this->input('settings', []);
            foreach (is_array($settings) ? $settings : [] as $index => $setting) {
                if (! is_array($setting)) {
                    continue;
                }

                foreach (array_diff(array_keys($setting), ['key', 'locale', 'value']) as $field) {
                    $validator->errors()->add("settings.{$index}.{$field}", 'This field is not allowed.');
                }

                $apiKey = $setting['key'] ?? null;
                if (! is_string($apiKey)) {
                    continue;
                }

                $definition = SeoSettingCatalog::definition($apiKey);
                if ($definition === null) {
                    continue;
                }

                $locale = $setting['locale'] ?? '';
                if ($definition['localized']) {
                    if (! in_array($locale, ['ar', 'en'], true)) {
                        $validator->errors()->add("settings.{$index}.locale", 'This setting requires an explicit ar or en locale.');
                    }
                } elseif ($locale !== null && $locale !== '') {
                    $validator->errors()->add("settings.{$index}.locale", 'This setting is global and does not accept a locale.');
                }

                $scope = $definition['key'].'|'.($definition['localized'] ? (string) $locale : '');
                if (isset($seen[$scope])) {
                    $validator->errors()->add("settings.{$index}.key", 'A setting key and locale may only appear once per request.');
                }
                $seen[$scope] = true;

                if (! array_key_exists('value', $setting) || $setting['value'] === null) {
                    continue;
                }

                $error = $this->valueError($definition['type'], $setting['value']);
                if ($error !== null) {
                    $validator->errors()->add("settings.{$index}.value", $error);
                }
            }
        });
    }

    private function valueError(string $type, mixed $value): ?string
    {
        return match ($type) {
            'string' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255
                ? null
                : 'This setting must be a non-blank string of at most 255 characters.',
            'title' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 180
                ? null
                : 'This setting must be a non-blank title of at most 180 characters.',
            'description' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 5000
                ? null
                : 'This setting must be a non-blank description of at most 5000 characters.',
            'image' => $this->isAvailableImage($value)
                ? null
                : 'This setting must reference an available image asset.',
            'https_origin' => $this->isHttpsOrigin($value)
                ? null
                : 'This setting must be an HTTPS origin without a path, credentials, query, or fragment.',
            'locales' => $this->isSupportedLocaleList($value)
                ? null
                : 'This setting must be a non-empty unique list containing only ar and en.',
            'robots_policy' => $this->isRobotsPolicy($value)
                ? null
                : 'This setting must contain index and follow boolean or null values only.',
            'currency' => is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1
                ? null
                : 'This setting must be a three-letter uppercase ISO currency code.',
            default => 'This setting type is not supported.',
        };
    }

    private function isAvailableImage(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false
            && (int) $value > 0
            && app(SeoPublicImageService::class)
                ->url(MediaAsset::query()->find((int) $value)) !== null;
    }

    private function isHttpsOrigin(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private function isSupportedLocaleList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        foreach ($value as $locale) {
            if (! is_string($locale)) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value)
            && array_diff($value, ['ar', 'en']) === [];
    }

    private function isRobotsPolicy(mixed $value): bool
    {
        if (! is_array($value) || array_diff(array_keys($value), ['index', 'follow']) !== []) {
            return false;
        }

        if (! array_key_exists('index', $value) || ! array_key_exists('follow', $value)) {
            return false;
        }

        foreach (['index', 'follow'] as $field) {
            if ($value[$field] !== null && ! is_bool($value[$field])) {
                return false;
            }
        }

        return true;
    }
}
