<?php

namespace App\Http\Requests\Receiving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VerifyReceivingReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'corrected_data' => ['sometimes', 'array'],
            'corrected_data.*' => ['required', 'array'],
            'corrected_data.*.document_type' => ['required', 'string', 'max:100'],
            'corrected_data.*.fields' => ['present', 'array', 'max:100'],
            'corrected_data.*.fields.*' => ['required', 'array:label,value'],
            'corrected_data.*.fields.*.label' => ['required', 'string', 'max:100'],
            'corrected_data.*.fields.*.value' => ['nullable', 'string', 'max:10000'],
            'corrected_data.*.items' => ['present', 'array', 'max:200'],
            'corrected_data.*.items.*' => ['array', 'max:20'],
            'corrected_data.*.items.*.*' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $encoded = json_encode($this->input('corrected_data', []));
            if (! is_string($encoded) || strlen($encoded) > 500_000) {
                $validator->errors()->add('corrected_data', 'The complete corrected review may not exceed 500 KB.');
            }
        }];
    }
}
