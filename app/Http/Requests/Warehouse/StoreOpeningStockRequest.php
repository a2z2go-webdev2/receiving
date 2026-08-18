<?php

namespace App\Http\Requests\Warehouse;

use App\Enums\Permission;
use App\Enums\WarehouseDateQuality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageWarehouseOperations->value) ?? false;
    }

    public function rules(): array
    {
        $unknown = $this->input('received_date_quality') === WarehouseDateQuality::Unknown->value;

        return [
            'warehouse_item_id' => ['nullable', 'integer', 'exists:warehouse_items,id'],
            'sku_number' => ['nullable', 'string', 'max:100'],
            'description' => ['required_without:warehouse_item_id', 'nullable', 'string', 'max:1000'],
            'unit' => ['nullable', 'string', 'max:50'],
            'quantity_received' => ['required', 'numeric', 'gt:0', 'max:99999999999.999'],
            'received_at' => [
                Rule::requiredIf(! $unknown),
                Rule::prohibitedIf($unknown),
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'received_date_quality' => ['required', Rule::enum(WarehouseDateQuality::class)],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
