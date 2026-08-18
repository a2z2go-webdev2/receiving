<?php

namespace App\Http\Requests\Warehouse;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmWarehouseArrivalsByPoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageWarehouseOperations->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'po_number' => ['required', 'string', 'max:255'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
