<?php

namespace App\Http\Requests\Api;

class PoNumberCorrectedDataRequest extends PaginatedCorrectedDataRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'po_number' => ['required', 'string', 'max:255'],
        ];
    }
}
