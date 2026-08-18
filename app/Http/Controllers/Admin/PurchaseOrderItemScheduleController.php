<?php

namespace App\Http\Controllers\Admin;

use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderItemScheduleController extends Controller
{
    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = (string) ($validated['status'] ?? '');

        $items = PurchaseOrderItemSchedule::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
                $pattern = "%{$escaped}%";
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("LOWER(sku_number) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(ean_barcode) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(unit) LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($status !== '', fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->orderBy('is_active', 'desc')
            ->orderByRaw('serial_number ASC NULLS LAST')
            ->orderBy('description')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (PurchaseOrderItemSchedule $item): array => $this->serialize($item));

        return Inertia::render('admin/purchase-orders/items/index', [
            'items' => $items,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        /** @var User|null $user */
        $user = $request->user();

        PurchaseOrderItemSchedule::query()->create([
            ...$this->attributes($data),
            'created_by' => $user?->getKey(),
        ]);

        return back()->with('status', 'Scheduled PO item was added.');
    }

    public function update(Request $request, PurchaseOrderItemSchedule $item): RedirectResponse
    {
        $item->forceFill($this->attributes($this->validated($request, $item)))->save();

        return back()->with('status', 'Scheduled PO item was updated.');
    }

    public function destroy(PurchaseOrderItemSchedule $item): RedirectResponse
    {
        $item->forceFill(['is_active' => false])->save();

        return back()->with('status', 'Scheduled PO item was deactivated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PurchaseOrderItemSchedule $item = null): array
    {
        return $request->validate([
            'sku_number' => ['nullable', 'string', 'max:100'],
            'ean_barcode' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:1000'],
            'target_quantity' => ['required', 'numeric', 'min:0', 'max:999999999.999'],
            'package_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'package_unit' => ['nullable', 'string', 'max:50'],
            'sold_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $skuNumber = trim((string) ($data['sku_number'] ?? ''));
        $eanBarcode = trim((string) ($data['ean_barcode'] ?? ''));
        $description = trim((string) $data['description']);

        return [
            'sku_number' => $skuNumber === '' ? null : $skuNumber,
            'sku_number_normalized' => $this->normalizer->normalizeIdentifier($skuNumber),
            'ean_barcode' => $eanBarcode === '' || $eanBarcode === '###' ? null : $eanBarcode,
            'ean_barcode_normalized' => $this->normalizer->normalizeIdentifier($eanBarcode),
            'description' => $description,
            'description_normalized' => $this->normalizer->normalizeDescription($description) ?? '',
            'target_quantity' => $this->normalizer->decimalString((float) $data['target_quantity']),
            'package_quantity' => isset($data['package_quantity']) && is_numeric($data['package_quantity'])
                ? $this->normalizer->decimalString((float) $data['package_quantity'])
                : null,
            'package_unit' => trim((string) ($data['package_unit'] ?? '')) ?: null,
            'sold_quantity' => isset($data['sold_quantity']) && is_numeric($data['sold_quantity'])
                ? $this->normalizer->decimalString((float) $data['sold_quantity'])
                : null,
            'unit' => trim((string) ($data['unit'] ?? '')) ?: null,
            'expected_week' => null,
            'is_special_order' => false,
            'is_active' => (bool) $data['is_active'],
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'source' => 'manual',
            'source_key' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(PurchaseOrderItemSchedule $item): array
    {
        return [
            'id' => $item->getKey(),
            'serial_number' => $item->serial_number,
            'sku_number' => $item->sku_number,
            'ean_barcode' => $item->ean_barcode,
            'description' => $item->description,
            'target_quantity' => (float) $item->target_quantity,
            'package_quantity' => $item->package_quantity !== null ? (float) $item->package_quantity : null,
            'package_unit' => $item->package_unit,
            'sold_quantity' => $item->sold_quantity !== null ? (float) $item->sold_quantity : null,
            'unit' => $item->unit,
            'expected_week' => null,
            'schedule_type' => 'monthly',
            'schedule_label' => 'Monthly target',
            'is_special_order' => false,
            'is_active' => $item->is_active,
            'notes' => $item->notes,
        ];
    }
}
