<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class AdminProductConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $options = $this->input('options');
        if (is_array($options)) {
            foreach ($options as &$option) {
                if (! is_array($option)) {
                    continue;
                }
                foreach (['ref', 'code', 'name_ar', 'name_en'] as $field) {
                    if (is_string($option[$field] ?? null)) {
                        $option[$field] = trim($option[$field]);
                    }
                }
                if (is_array($option['values'] ?? null)) {
                    foreach ($option['values'] as &$value) {
                        if (! is_array($value)) {
                            continue;
                        }
                        foreach (['ref', 'code', 'value_ar', 'value_en'] as $field) {
                            if (is_string($value[$field] ?? null)) {
                                $value[$field] = trim($value[$field]);
                            }
                        }
                        unset($value);
                    }
                }
            }
            unset($option);
            $this->merge(['options' => $options]);
        }

        $variants = $this->input('variants');
        if (is_array($variants)) {
            foreach ($variants as &$variant) {
                if (! is_array($variant)) {
                    continue;
                }
                foreach (['ref', 'sku'] as $field) {
                    if (is_string($variant[$field] ?? null)) {
                        $variant[$field] = trim($variant[$field]);
                    }
                }
                foreach (['price', 'original_price'] as $field) {
                    if (is_string($variant[$field] ?? null)) {
                        $variant[$field] = trim($variant[$field]);
                    }
                }
                if (is_array($variant['option_value_refs'] ?? null)) {
                    $variant['option_value_refs'] = array_map(
                        static fn (mixed $reference): mixed => is_string($reference) ? trim($reference) : $reference,
                        $variant['option_value_refs'],
                    );
                }
            }
            unset($variant);
            $this->merge(['variants' => $variants]);
        }

        if (is_string($this->input('default_variant_ref'))) {
            $this->merge(['default_variant_ref' => trim($this->input('default_variant_ref'))]);
        }
    }

    public function rules(): array
    {
        $money = ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'];

        return [
            'context_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'options' => ['required', 'array', 'min:1'],
            'options.*' => ['required', 'array'],
            'options.*.ref' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'options.*.code' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'options.*.name_ar' => ['required', 'string', 'min:1', 'max:255'],
            'options.*.name_en' => ['required', 'string', 'min:1', 'max:255'],
            'options.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'options.*.values' => ['required', 'array', 'min:1'],
            'options.*.values.*' => ['required', 'array'],
            'options.*.values.*.ref' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'options.*.values.*.code' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'options.*.values.*.value_ar' => ['required', 'string', 'min:1', 'max:255'],
            'options.*.values.*.value_en' => ['required', 'string', 'min:1', 'max:255'],
            'options.*.values.*.metadata' => ['sometimes', 'nullable', 'array'],
            'options.*.values.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*' => ['required', 'array'],
            'variants.*.ref' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'variants.*.sku' => ['required', 'string', 'min:1', 'max:255'],
            'variants.*.price' => $money,
            'variants.*.original_price' => ['sometimes', 'nullable', ...array_slice($money, 1)],
            'variants.*.stock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'variants.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'variants.*.option_value_refs' => ['required', 'array', 'min:1'],
            'variants.*.option_value_refs.*' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'default_variant_ref' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'stock_adjustment' => ['sometimes', 'nullable', 'array'],
            'stock_adjustment.confirmed_difference' => ['required_with:stock_adjustment', 'integer'],
            'stock_adjustment.reason' => ['required_with:stock_adjustment', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();
            $this->rejectUnexpectedKeys($validator, $data, [
                'context_token', 'options', 'variants', 'default_variant_ref', 'stock_adjustment',
            ]);

            foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $optionIndex => $option) {
                if (! is_array($option)) {
                    continue;
                }
                $this->rejectUnexpectedKeys($validator, $option, ['ref', 'code', 'name_ar', 'name_en', 'sort_order', 'values'], "options.{$optionIndex}");
                foreach ($option['values'] ?? [] as $valueIndex => $value) {
                    if (is_array($value)) {
                        $this->rejectUnexpectedKeys($validator, $value, ['ref', 'code', 'value_ar', 'value_en', 'metadata', 'sort_order'], "options.{$optionIndex}.values.{$valueIndex}");
                    }
                }
            }
            foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $variantIndex => $variant) {
                if (is_array($variant)) {
                    $this->rejectUnexpectedKeys($validator, $variant, ['ref', 'sku', 'price', 'original_price', 'stock_quantity', 'sort_order', 'option_value_refs'], "variants.{$variantIndex}");
                }
            }
            $this->rejectUnexpectedKeys($validator, $data['stock_adjustment'] ?? null, ['confirmed_difference', 'reason'], 'stock_adjustment');

            if (! Str::isUuid($this->idempotencyKey())) {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must be a valid UUID.');
            }

            $this->validateReferences($validator, $data);
        });
    }

    /** @param array<string, mixed> $data */
    private function validateReferences(Validator $validator, array $data): void
    {
        $allReferences = [];
        $valueOptions = [];
        $optionCodes = [];
        $optionNamesAr = [];
        $optionNamesEn = [];
        $valueCodesByOption = [];
        $valueNamesArByOption = [];
        $valueNamesEnByOption = [];

        foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $optionIndex => $option) {
            if (! is_array($option)) {
                continue;
            }
            $optionRef = $option['ref'] ?? null;
            if (is_string($optionRef)) {
                $allReferences[] = ['ref' => $optionRef, 'key' => "options.{$optionIndex}.ref"];
            }
            if (is_string($option['code'] ?? null)) {
                $code = $option['code'];
                if (isset($optionCodes[$code])) {
                    $validator->errors()->add("options.{$optionIndex}.code", 'Option codes must be distinct within this conversion.');
                }
                $optionCodes[$code] = true;
            }
            if (is_string($option['name_ar'] ?? null)) {
                $this->validateUniqueLabel($validator, $optionNamesAr, trim($option['name_ar']), "options.{$optionIndex}.name_ar", 'Option names must be distinct within this conversion.');
            }
            if (is_string($option['name_en'] ?? null)) {
                $this->validateUniqueLabel($validator, $optionNamesEn, mb_strtolower(trim($option['name_en'])), "options.{$optionIndex}.name_en", 'Option names must be distinct within this conversion.');
            }
            foreach (is_array($option['values'] ?? null) ? $option['values'] : [] as $valueIndex => $value) {
                if (! is_array($value)) {
                    continue;
                }
                $valueRef = $value['ref'] ?? null;
                if (is_string($valueRef)) {
                    $allReferences[] = ['ref' => $valueRef, 'key' => "options.{$optionIndex}.values.{$valueIndex}.ref"];
                    if (is_string($optionRef)) {
                        $valueOptions[$valueRef] = $optionRef;
                    }
                }
                if (is_string($value['code'] ?? null)) {
                    $code = $value['code'];
                    if (is_string($optionRef)) {
                        if (isset($valueCodesByOption[$optionRef][$code])) {
                            $validator->errors()->add("options.{$optionIndex}.values.{$valueIndex}.code", 'Value codes must be distinct within an option.');
                        }
                        $valueCodesByOption[$optionRef][$code] = true;
                    }
                }
                if (is_string($value['value_ar'] ?? null)) {
                    if (is_string($optionRef)) {
                        $valueNamesArByOption[$optionRef] ??= [];
                        $this->validateUniqueLabel($validator, $valueNamesArByOption[$optionRef], trim($value['value_ar']), "options.{$optionIndex}.values.{$valueIndex}.value_ar", 'Value labels must be distinct within an option.');
                    }
                }
                if (is_string($value['value_en'] ?? null)) {
                    if (is_string($optionRef)) {
                        $valueNamesEnByOption[$optionRef] ??= [];
                        $this->validateUniqueLabel($validator, $valueNamesEnByOption[$optionRef], mb_strtolower(trim($value['value_en'])), "options.{$optionIndex}.values.{$valueIndex}.value_en", 'Value labels must be distinct within an option.');
                    }
                }
            }
        }

        $variantReferences = [];
        $skus = [];
        $combinations = [];
        $optionCount = count(is_array($data['options'] ?? null) ? $data['options'] : []);
        foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $variantIndex => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $variantRef = $variant['ref'] ?? null;
            if (is_string($variantRef)) {
                $allReferences[] = ['ref' => $variantRef, 'key' => "variants.{$variantIndex}.ref"];
                $variantReferences[$variantRef] = true;
            }
            $sku = $variant['sku'] ?? null;
            if (is_string($sku)) {
                if (isset($skus[$sku])) {
                    $validator->errors()->add("variants.{$variantIndex}.sku", 'SKUs must be distinct within this conversion.');
                }
                $skus[$sku] = true;
            }
            $refs = $variant['option_value_refs'] ?? null;
            if (! is_array($refs)) {
                continue;
            }
            $stringRefs = array_filter($refs, 'is_string');
            if (count($refs) !== $optionCount || count(array_unique($stringRefs, SORT_STRING)) !== count($refs)) {
                $validator->errors()->add("variants.{$variantIndex}.option_value_refs", 'Each variant must select one distinct value for every new option.');
            }
            $selectedOptions = [];
            foreach ($refs as $refIndex => $ref) {
                if (! is_string($ref) || ! is_string($valueOptions[$ref] ?? null)) {
                    $validator->errors()->add("variants.{$variantIndex}.option_value_refs.{$refIndex}", 'The selected value reference is unknown.');

                    continue;
                }
                $optionRef = $valueOptions[$ref];
                if (isset($selectedOptions[$optionRef])) {
                    $validator->errors()->add("variants.{$variantIndex}.option_value_refs", 'A variant cannot select two values from the same option.');
                }
                $selectedOptions[$optionRef] = true;
            }
            if (count($selectedOptions) === $optionCount && count($refs) === $optionCount) {
                $combination = $refs;
                sort($combination, SORT_STRING);
                $key = implode(',', $combination);
                if (isset($combinations[$key])) {
                    $validator->errors()->add("variants.{$variantIndex}.option_value_refs", 'This option value combination already exists.');
                }
                $combinations[$key] = true;
            }
        }

        $seenReferences = [];
        foreach ($allReferences as $reference) {
            if (isset($seenReferences[$reference['ref']])) {
                $validator->errors()->add($reference['key'], 'Temporary references must be unique throughout the request.');
            }
            $seenReferences[$reference['ref']] = true;
        }

        $defaultRef = $data['default_variant_ref'] ?? null;
        if (is_string($defaultRef) && ! isset($variantReferences[$defaultRef])) {
            $validator->errors()->add('default_variant_ref', 'The default variant reference must identify a submitted variant.');
        }
    }

    /** @param array<string, mixed>|null $value @param list<string> $allowed */
    private function rejectUnexpectedKeys(Validator $validator, mixed $value, array $allowed, string $prefix = ''): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $validator->errors()->add($path, 'This field is not allowed.');
        }
    }

    /** @param array<string, true> $knownLabels */
    private function validateUniqueLabel(Validator $validator, array &$knownLabels, string $label, string $path, string $message): void
    {
        if (isset($knownLabels[$label])) {
            $validator->errors()->add($path, $message);
        }
        $knownLabels[$label] = true;
    }
}
