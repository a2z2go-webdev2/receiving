<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class SyncGoogleSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageSettings->value) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'slug' => ['nullable', 'string'],
            'sheet_config_id' => ['nullable', 'integer', 'exists:google_sheet_configs,id'],
            'mode' => ['nullable', 'string', 'in:apply,preview'],
            'range' => ['nullable', 'string', 'max:100'],
        ];
    }
}
