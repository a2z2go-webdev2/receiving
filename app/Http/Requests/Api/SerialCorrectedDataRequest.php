<?php

namespace App\Http\Requests\Api;

class SerialCorrectedDataRequest extends PaginatedCorrectedDataRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'serial_number' => ['required', 'string', 'regex:/^(?:SN-)?[1-9][0-9]*$/i'],
        ];
    }

    public function serialNumber(): int
    {
        return (int) preg_replace('/^SN-/i', '', $this->string('serial_number')->toString());
    }
}
