<?php

namespace App\Http\Requests;

use App\Models\Offer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminOfferRequest extends FormRequest
{
    private const FIELDS = [
        'name', 'discountPercentage', 'startsAt', 'endsAt', 'isEnabled', 'productIds',
    ];

    private const INTERNAL_FIELDS = [
        'discountPercentage' => 'discount_percentage',
        'startsAt' => 'starts_at',
        'endsAt' => 'ends_at',
        'isEnabled' => 'is_enabled',
        'productIds' => 'product_ids',
    ];

    /** @var array<string, bool> */
    private array $timezoneAware = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'discountPercentage'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        foreach (['startsAt', 'endsAt'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);
            $this->timezoneAware[$field] = is_string($value)
                && preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', trim($value)) === 1;

            if (is_string($value) && trim($value) !== '') {
                try {
                    $normalized[$field] = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.u');
                } catch (\Throwable) {
                    // Keep the original value so Laravel's date validation can report it.
                }
            }
        }

        foreach (['isEnabled'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
                $normalized[$field] = in_array(strtolower($value), ['true', '1'], true);
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'min:1', 'max:255'],
            'discountPercentage' => [$presence, 'numeric', 'decimal:0,2', 'gt:0', 'lt:100'],
            'startsAt' => [$presence, 'date'],
            'endsAt' => [$presence, 'date'],
            'isEnabled' => [$presence, 'boolean'],
            'productIds' => [$presence, 'array'],
            'productIds.*' => [
                'integer',
                'distinct',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            foreach (['startsAt', 'endsAt'] as $field) {
                if ($this->exists($field) && ($this->timezoneAware[$field] ?? false) !== true) {
                    $validator->errors()->add($field, 'The timestamp must include a timezone offset or Z.');
                }
            }

            $offer = $this->route('offer');
            $offer = $offer instanceof Offer ? $offer : null;
            $start = $this->input('startsAt', $offer?->starts_at);
            $end = $this->input('endsAt', $offer?->ends_at);

            try {
                $start = $start instanceof \DateTimeInterface ? CarbonImmutable::instance($start) : CarbonImmutable::parse((string) $start);
                $end = $end instanceof \DateTimeInterface ? CarbonImmutable::instance($end) : CarbonImmutable::parse((string) $end);
                if ($start->greaterThanOrEqualTo($end)) {
                    $validator->errors()->add('endsAt', 'The end time must be after the start time.');
                }
            } catch (\Throwable) {
                // Field-level date validation reports malformed values.
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
