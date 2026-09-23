<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Services\ExternalVideoUrlNormalizer;
use InvalidArgumentException;
use JsonException;

class AdminProductRequest extends FormRequest
{
    private const FIELDS = [
        'slug', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'features', 'specifications', 'badge', 'status', 'is_featured',
        'published_at', 'category_ids', 'primary_category_id',
        'primary_image', 'gallery_images', 'primary_attachment_id', 'remove_attachment_ids',
        'video_url',
    ];

    /** @var array<string, string> */
    private array $multipartJsonErrors = [];

    private ?string $videoUrlError = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::FIELDS as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if (in_array($field, ['description_ar', 'description_en', 'badge'], true) && $value === '') {
                $value = null;
            }
            if ($field === 'slug') {
                $value = strtolower($value);
            }
            if ($field === 'published_at' && $value === '') {
                $value = null;
            }
            if ($field === 'video_url' && $value !== '') {
                try {
                    $value = app(ExternalVideoUrlNormalizer::class)->normalize($value);
                } catch (InvalidArgumentException) {
                    $this->videoUrlError = 'The video_url must be a valid HTTPS YouTube or Vimeo video URL.';
                }
            }
            if ($field === 'video_url' && $value === '') {
                $value = null;
            }
            if ($field === 'is_featured' && in_array(strtolower($value), ['true', 'false'], true)) {
                $value = strtolower($value) === 'true';
            }

            $normalized[$field] = $value;
        }

        $this->merge($normalized);

        if (! $this->isJson()) {
            $this->normalizeMultipartValues();
        }
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $product = $this->route('product');
        $product = $product instanceof Product ? $product : null;
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'slug' => [$updating ? 'sometimes' : 'nullable', 'nullable', 'string', 'min:1', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($product?->getKey())],
            'name_ar' => [$presence, 'string', 'min:1', 'max:255'],
            'name_en' => [$presence, 'string', 'min:1', 'max:255'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'features' => ['sometimes', 'nullable', 'array'],
            'specifications' => ['sometimes', 'nullable', 'array'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'inactive', 'active'])],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'video_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->whereNull('deleted_at')->where('status', 'active')],
            'primary_category_id' => ['sometimes', 'nullable', 'integer'],
            'primary_image' => ['sometimes', 'file'],
            'gallery_images' => ['sometimes', 'array'],
            'gallery_images.*' => ['file'],
            'primary_attachment_id' => $updating ? ['sometimes', 'nullable', 'integer', 'min:1'] : ['prohibited'],
            'remove_attachment_ids' => $updating ? ['sometimes', 'array'] : ['prohibited'],
            'remove_attachment_ids.*' => $updating ? ['integer', 'distinct', 'min:1'] : ['prohibited'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        unset($data['_method']);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedTransportFields = ['_method'];
            foreach (array_diff(array_keys($this->all()), [...self::FIELDS, ...$allowedTransportFields]) as $field) {
                $validator->errors()->add((string) $field, 'This field is not allowed.');
            }

            if ($this->has('_method') && (string) $this->input('_method') !== 'PATCH') {
                $validator->errors()->add('_method', 'Only PATCH method spoofing is supported.');
            }

            if ($this->isMethod('PATCH') && count(array_intersect(array_keys($this->all()), self::FIELDS)) === 0) {
                $validator->errors()->add('product', 'At least one product field is required.');
            }

            foreach ($this->multipartJsonErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            if ($this->videoUrlError !== null) {
                $validator->errors()->add('video_url', $this->videoUrlError);
            }

            if (! $this->isJson()) {
                $gallery = $this->input('gallery_images');
                if (is_array($gallery)) {
                    foreach ($gallery as $index => $image) {
                        if (is_array($image)) {
                            $validator->errors()->add("gallery_images.{$index}", 'Each gallery image must be a file.');
                        }
                    }
                }

            }

            if ($this->isMethod('PATCH')
                && $this->hasFile('primary_image')
                && $this->filled('primary_attachment_id')) {
                $validator->errors()->add('primary_image', 'A new primary image cannot be combined with primary_attachment_id.');
                $validator->errors()->add('primary_attachment_id', 'A new primary image cannot be combined with primary_attachment_id.');
            }

            if ($this->isMethod('PATCH')
                && $this->filled('primary_attachment_id')
                && is_array($this->input('remove_attachment_ids'))
                && in_array((int) $this->input('primary_attachment_id'), array_map('intval', $this->input('remove_attachment_ids')), true)) {
                $validator->errors()->add('primary_attachment_id', 'The primary attachment cannot also be removed.');
                $validator->errors()->add('remove_attachment_ids', 'The primary attachment cannot also be removed.');
            }

            if ($this->exists('primary_category_id') && $this->input('primary_category_id') !== null) {
                $categoryIds = $this->input('category_ids');
                if (is_array($categoryIds) && ! in_array((int) $this->input('primary_category_id'), array_map('intval', $categoryIds), true)) {
                    $validator->errors()->add('primary_category_id', 'The primary category must be included in category_ids.');
                }
                if (! is_array($categoryIds)) {
                    $validator->errors()->add('category_ids', 'Category ids are required when setting a primary category.');
                }
            }
        });
    }

    private function normalizeMultipartValues(): void
    {
        foreach (['features', 'specifications'] as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }

            try {
                $this->merge([$field => json_decode($value, true, 512, JSON_THROW_ON_ERROR)]);
            } catch (JsonException) {
                $this->multipartJsonErrors[$field] = 'The '.$field.' field must contain valid JSON.';
            }
        }

        $value = $this->input('is_featured');
        if (is_string($value) && in_array(strtolower($value), ['0', '1', 'true', 'false'], true)) {
            $this->merge(['is_featured' => in_array(strtolower($value), ['1', 'true'], true)]);
        }

        $categoryIds = $this->input('category_ids');
        if (is_array($categoryIds)) {
            $this->merge(['category_ids' => array_map(
                static fn (mixed $id): mixed => is_numeric($id) ? (int) $id : $id,
                $categoryIds,
            )]);
        }

        $primaryCategoryId = $this->input('primary_category_id');
        if (is_numeric($primaryCategoryId)) {
            $this->merge(['primary_category_id' => (int) $primaryCategoryId]);
        }

        $primaryAttachmentId = $this->input('primary_attachment_id');
        if (is_numeric($primaryAttachmentId)) {
            $this->merge(['primary_attachment_id' => (int) $primaryAttachmentId]);
        }

        $removeAttachmentIds = $this->input('remove_attachment_ids');
        if (is_array($removeAttachmentIds)) {
            $this->merge(['remove_attachment_ids' => array_map(
                static fn (mixed $id): mixed => is_numeric($id) ? (int) $id : $id,
                $removeAttachmentIds,
            )]);
        }
    }
}
