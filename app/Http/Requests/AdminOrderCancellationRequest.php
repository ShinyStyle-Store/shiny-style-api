<?php

namespace App\Http\Requests;

use App\Enums\CancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminOrderCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $note = trim($this->input('note'));
            $this->merge(['note' => $note === '' ? null : $note]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(CancellationReason::class)],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->all()), ['reason', 'note']) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }

            $data = $validator->getData();
            if (($data['reason'] ?? null) === CancellationReason::Other->value
                && (! is_string($data['note'] ?? null) || trim($data['note']) === '')) {
                $validator->errors()->add('note', 'A note is required for the other cancellation reason.');
            }
        });
    }
}
