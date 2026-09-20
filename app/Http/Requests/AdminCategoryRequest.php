<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminCategoryRequest extends FormRequest
{
    private const BYTES_PER_KILOBYTE = 1024;

    private const FIELDS = [
        'parent_id', 'slug', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'status', 'sort_order', 'cover_image', 'remove_cover_image',
    ];

    private bool $acceptedMultipartPatchSpoof = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->acceptedMultipartPatchSpoof = $this->isMethod('PATCH')
            && $this->getRealMethod() === 'POST'
            && str_contains(strtolower((string) $this->header('Content-Type')), 'multipart/form-data')
            && strtoupper((string) $this->request->get('_method')) === 'PATCH';

        $normalized = [];

        foreach (self::FIELDS as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($field === 'slug') {
                $value = strtolower($value);
            } elseif ($field === 'remove_cover_image' && in_array(strtolower($value), ['true', 'false'], true)) {
                $value = strtolower($value) === 'true';
            } elseif (in_array($field, ['description_ar', 'description_en'], true)
                && $value === '') {
                $value = null;
            }

            $normalized[$field] = $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $category = $this->route('category');
        $category = $category instanceof Category ? $category : null;
        $presence = $updating ? 'sometimes' : 'required';
        $maxImageSizeKilobytes = (int) ceil(
            (int) config('media.images.max_image_size_bytes') / self::BYTES_PER_KILOBYTE,
        );

        return [
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'slug' => [
                $presence, 'string', 'min:1', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($category?->getKey()),
            ],
            'name_ar' => [$presence, 'string', 'min:1', 'max:255'],
            'name_en' => [$presence, 'string', 'min:1', 'max:255'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'cover_image' => [
                'sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
                'max:'.$maxImageSizeKilobytes,
            ],
            'remove_cover_image' => ['sometimes', 'boolean'],
            'status' => [$presence, 'string', Rule::in(['active', 'inactive'])],
            'sort_order' => [$presence, 'integer', 'min:0'],
        ];
    }

    /** Remove accepted transport metadata from the data passed to validation and validated(). */
    public function validationData(): array
    {
        $data = parent::validationData();
        if ($this->acceptedMultipartPatchSpoof) {
            unset($data['_method']);
        }

        return $data;
    }

    /** Ensure transport metadata can never escape as mutation data. */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        unset($data['_method']);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $inputFields = array_keys($this->all());
            if ($this->acceptedMultipartPatchSpoof) {
                $inputFields = array_values(array_diff($inputFields, ['_method']));
            }

            foreach (array_diff($inputFields, self::FIELDS) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            if (! $this->isMethod('PATCH') && $this->exists('remove_cover_image')) {
                $validator->errors()->add('remove_cover_image', 'This field is only available when updating.');
            }

            $hasImage = $this->hasFile('cover_image');
            if ($hasImage && $this->exists('remove_cover_image')) {
                $validator->errors()->add('cover_image', 'Choose an image or remove the existing one.');
                $validator->errors()->add('remove_cover_image', 'Choose an image or remove the existing one.');
            }

            $businessFields = array_diff($inputFields, ['_method']);
            $hasCategoryChanges = count(array_intersect($businessFields, [
                'parent_id', 'slug', 'name_ar', 'name_en', 'description_ar', 'description_en', 'status', 'sort_order',
            ])) > 0;
            $hasMediaOperation = $hasImage || $this->exists('remove_cover_image');
            if ($this->isMethod('PATCH') && ! $hasCategoryChanges && ! $hasMediaOperation) {
                $validator->errors()->add('category', 'At least one category field is required.');
            }
        });
    }
}
