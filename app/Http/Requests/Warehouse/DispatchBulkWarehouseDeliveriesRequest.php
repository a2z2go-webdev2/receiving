<?php

namespace App\Http\Requests\Warehouse;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class DispatchBulkWarehouseDeliveriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageWarehouseOperations->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'delivery_ids' => ['required', 'array', 'min:1', 'max:50'],
            'delivery_ids.*' => ['required', 'integer', 'exists:warehouse_deliveries,id'],
        ];
    }
}
