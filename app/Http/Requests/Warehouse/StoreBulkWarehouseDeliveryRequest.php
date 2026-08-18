<?php

namespace App\Http\Requests\Warehouse;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class StoreBulkWarehouseDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageWarehouseOperations->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'dispatch_immediately' => ['nullable', 'boolean'],
            'deliveries' => ['required', 'array', 'min:1', 'max:20'],
            'deliveries.*.customer_name' => ['required', 'string', 'max:255'],
            'deliveries.*.sales_order' => ['required', 'string', 'max:100'],
            'deliveries.*.po' => ['required', 'string', 'max:100'],
            'deliveries.*.notes' => ['nullable', 'string', 'max:2000'],
            'deliveries.*.lines' => ['required', 'array', 'min:1', 'max:50'],
            'deliveries.*.lines.*.warehouse_item_id' => ['required', 'integer', 'exists:warehouse_items,id'],
            'deliveries.*.lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,1', 'max:99999999999.9'],
        ];
    }
}
