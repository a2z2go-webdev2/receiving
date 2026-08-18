<?php

namespace App\Http\Requests\Warehouse;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class StoreWarehouseDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageWarehouseOperations->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'sales_order' => ['required', 'string', 'max:100'],
            'po' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.warehouse_item_id' => ['required', 'integer', 'distinct', 'exists:warehouse_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,1', 'max:99999999999.9'],
        ];
    }
}
