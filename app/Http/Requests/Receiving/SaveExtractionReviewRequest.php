<?php

namespace App\Http\Requests\Receiving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveExtractionReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'corrected_data' => ['required', 'array'],
            'corrected_data.document_type' => ['required', 'string', 'max:100'],
            'corrected_data.fields' => ['present', 'array', 'max:100'],
            'corrected_data.fields.*' => ['required', 'array:label,value'],
            'corrected_data.fields.*.label' => ['required', 'string', 'max:100'],
            'corrected_data.fields.*.value' => ['nullable', 'string', 'max:10000'],
            'corrected_data.items' => ['present', 'array', 'max:200'],
            'corrected_data.items.*' => ['array', 'max:20'],
            'corrected_data.items.*.*' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $encoded = json_encode($this->input('corrected_data'));
            if (! is_string($encoded) || strlen($encoded) > 100_000) {
                $validator->errors()->add('corrected_data', 'Corrected data may not exceed 100 KB.');
            }
        }];
    }
}
