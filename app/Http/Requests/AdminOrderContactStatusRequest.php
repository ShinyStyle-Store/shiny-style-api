<?php

namespace App\Http\Requests;

use App\Enums\ContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminOrderContactStatusRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact_note'))) {
            $note = trim($this->input('contact_note'));
            $this->merge(['contact_note' => $note === '' ? null : $note]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_status' => ['required', 'string', Rule::enum(ContactStatus::class)],
            'contact_note' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->all()), ['contact_status', 'contact_note']) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }
        });
    }
}
