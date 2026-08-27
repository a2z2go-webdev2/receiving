import { Head, router, useForm } from '@inertiajs/react';
import { Archive, ClipboardList, Pencil, Plus, Search, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import InputError from '@/components/input-error';
import { FilterDropdown } from '@/components/receiving/filter-dropdown';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type ScheduledItem = {
    id: number;
    serial_number: number | null;
    sku_number: string | null;
    ean_barcode: string | null;
    description: string;
    target_quantity: number;
    package_quantity: number | null;
    package_unit: string | null;
    sold_quantity: number | null;
    unit: string | null;
    expected_week: number | null;
    schedule_type: string;
    schedule_label: string;
    is_special_order: boolean;
    is_active: boolean;
    notes: string | null;
};

type Filters = {
    search: string;
    status: string;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const emptyForm = {
    sku_number: '',
    ean_barcode: '',
    description: '',
    target_quantity: '0',
    package_quantity: '',
    package_unit: '',
    sold_quantity: '',
    unit: '',
    is_active: true,
    notes: '',
};

export default function PurchaseOrderItemsIndex({
    items,
    filters,
}: {
    items: Paginator<ScheduledItem>;
    filters: Filters;
}) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<ScheduledItem | null>(null);
    const [deactivating, setDeactivating] = useState<ScheduledItem | null>(null);
    const [advancedFiltersOpen, setAdvancedFiltersOpen] = useState(false);
    const [filterValues, setFilterValues] = useState(filters);
    const form = useForm(emptyForm);

    function add() {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    }

    function edit(item: ScheduledItem) {
        setEditing(item);
        form.setData({
            sku_number: item.sku_number ?? '',
            ean_barcode: item.ean_barcode ?? '',
            description: item.description,
            target_quantity: String(item.target_quantity),
            package_quantity: item.package_quantity !== null ? String(item.package_quantity) : '',
            package_unit: item.package_unit ?? '',
            sold_quantity: item.sold_quantity !== null ? String(item.sold_quantity) : '',
            unit: item.unit ?? '',
            is_active: item.is_active,
            notes: item.notes ?? '',
        });
        form.clearErrors();
        setOpen(true);
    }

    function submit() {
        const options = {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        };

        editing
            ? form.put(`/admin/purchase-orders/items/${editing.id}`, options)
            : form.post('/admin/purchase-orders/items', options);
    }

    function applyFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        submitFilters();
    }

    function submitFilters() {
        router.get('/admin/purchase-orders/items', filterValues, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function clearFilters() {
        const next = { search: '', status: '' };
        setFilterValues(next);
        router.get('/admin/purchase-orders/items', next, {
            preserveScroll: true,
            replace: true,
        });
    }

    const hasActiveFilters = Boolean(filters.search) || Boolean(filters.status);
    const hasActiveFilterValues = Boolean(filterValues.search) || Boolean(filterValues.status);
    const advancedFilterCount = [filterValues.status].filter(Boolean).length;

    return (
        <>
            <Head title="PO Item Records" />
            <PageShell
                title="PO item records"
                description="Maintain the target purchase order item records and package units."
                actions={
                    <Button onClick={add} className="gap-1.5">
                        <Plus className="size-4" />
                        Add item
                    </Button>
                }
            >
                <FlashMessage />

                <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <form onSubmit={applyFilters} className="flex flex-1 items-center gap-1.5">
                        <div className="relative w-full max-w-sm">
                            <Search className="absolute top-1/2 left-2 size-3 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                id="item-search"
                                value={filterValues.search}
                                onChange={(event) =>
                                    setFilterValues((current) => ({
                                        ...current,
                                        search: event.target.value,
                                    }))
                                }
                                placeholder="Search SKU, EAN, description, or unit"
                                className="h-7 w-full pl-7 text-xs"
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            className="h-7 gap-1 whitespace-nowrap px-2.5 text-xs"
                        >
                            <Search className="size-3" /> Search
                        </Button>
                        <FilterDropdown
                            open={advancedFiltersOpen}
                            onOpenChange={setAdvancedFiltersOpen}
                            activeCount={advancedFilterCount}
                        >
                            <div className="space-y-4">
                                <div>
                                    <h2 className="font-semibold text-sm">More filters</h2>
                                    <p className="mt-1 text-muted-foreground text-xs">
                                        Narrow PO item records by active status.
                                    </p>
                                </div>
                                <div>
                                    <FilterSelect
                                        id="status-filter"
                                        label="Status"
                                        value={filterValues.status}
                                        onChange={(status) =>
                                            setFilterValues((current) => ({ ...current, status }))
                                        }
                                        options={[
                                            { value: 'active', label: 'Active' },
                                            { value: 'inactive', label: 'Inactive' },
                                        ]}
                                    />
                                </div>
                                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setFilterValues((current) => ({
                                                ...current,
                                                status: '',
                                            }))
                                        }
                                    >
                                        Clear advanced
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setAdvancedFiltersOpen(false);
                                            submitFilters();
                                        }}
                                    >
                                        Apply filters
                                    </Button>
                                </div>
                            </div>
                        </FilterDropdown>
                        {(hasActiveFilters || hasActiveFilterValues) && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                                className="h-7 gap-1 px-2 text-xs"
                            >
                                <X className="size-3" /> Clear
                            </Button>
                        )}
                    </form>

                    <div className="flex items-center gap-2">
                        <p className="text-muted-foreground text-xs">
                            {hasActiveFilters
                                ? 'Showing filtered results'
                                : 'Showing all PO item records'}
                        </p>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="w-12 px-3 py-2 text-center">#</th>
                                <th className="px-3 py-2">Item</th>
                                <th className="px-3 py-2">Schedule</th>
                                <th className="px-3 py-2 text-right">Target Qty</th>
                                <th className="px-3 py-2 text-right">Package</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {items.data.map((item) => (
                                <tr key={item.id}>
                                    <td className="px-3 py-2 text-center font-mono text-[11px] text-muted-foreground">
                                        {item.serial_number ?? '-'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex items-start gap-2">
                                            <ClipboardList className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                            <div>
                                                <p className="font-medium">{item.description}</p>
                                                <p className="text-muted-foreground">
                                                    <span className="font-mono">
                                                        SKU: {item.sku_number || 'N/A'}
                                                    </span>
                                                    {item.ean_barcode && (
                                                        <span className="ml-2 font-mono">
                                                            EAN: {item.ean_barcode}
                                                        </span>
                                                    )}
                                                    {item.notes ? ` • ${item.notes}` : ''}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">{item.schedule_label}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {formatQuantity(item.target_quantity)} {item.unit ?? ''}
                                    </td>
                                    <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                                        {item.package_quantity !== null && item.package_unit ? (
                                            <span>
                                                1 {item.unit ?? 'case'} ={' '}
                                                {formatQuantity(item.package_quantity)}{' '}
                                                {item.package_unit}
                                            </span>
                                        ) : (
                                            <span>-</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            value={item.is_active ? 'active' : 'inactive'}
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex justify-end gap-1.5">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => edit(item)}
                                            >
                                                <Pencil className="size-3.5" />
                                                Edit
                                            </Button>
                                            {item.is_active && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setDeactivating(item)}
                                                >
                                                    <Archive className="size-3.5" />
                                                    Deactivate
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {items.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-3 py-10 text-center text-muted-foreground"
                                    >
                                        No scheduled PO items match these filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationNav
                    currentPage={items.current_page}
                    lastPage={items.last_page}
                    previousUrl={items.prev_page_url}
                    nextUrl={items.next_page_url}
                    label="Scheduled PO item pages"
                />
            </PageShell>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="flex flex-col p-0 sm:max-w-lg">
                    <DialogHeader className="gap-0.5 border-b px-4 pt-3 pb-2">
                        <DialogTitle className="font-semibold text-sm">
                            {editing ? 'Edit PO item' : 'Add PO item'}
                        </DialogTitle>
                        <DialogDescription className="text-[10px] text-muted-foreground leading-tight">
                            Target quantities are compared with uploaded purchase-order line items.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        <div className="space-y-2.5 p-4">
                            {/* Row 1: SKU & EAN */}
                            <div className="grid gap-2.5 sm:grid-cols-2">
                                <div className="space-y-0.5">
                                    <Label htmlFor="sku-number" className="text-xs">
                                        SKU number
                                    </Label>
                                    <Input
                                        id="sku-number"
                                        className="h-7 text-xs"
                                        value={form.data.sku_number}
                                        onChange={(event) =>
                                            form.setData('sku_number', event.target.value)
                                        }
                                    />
                                    <InputError message={form.errors.sku_number} />
                                </div>
                                <div className="space-y-0.5">
                                    <Label htmlFor="ean-barcode" className="text-xs">
                                        EAN (Barcode)
                                    </Label>
                                    <Input
                                        id="ean-barcode"
                                        className="h-7 text-xs"
                                        value={form.data.ean_barcode}
                                        onChange={(event) =>
                                            form.setData('ean_barcode', event.target.value)
                                        }
                                    />
                                    <InputError message={form.errors.ean_barcode} />
                                </div>
                            </div>

                            {/* Row 2: Description */}
                            <div className="space-y-0.5">
                                <Label htmlFor="description" className="text-xs">
                                    Description
                                </Label>
                                <Input
                                    id="description"
                                    className="h-7 text-xs"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData('description', event.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.description} />
                            </div>

                            {/* Row 3: Target Quantity & Main Unit */}
                            <div className="grid gap-2.5 sm:grid-cols-3">
                                <div className="space-y-0.5 sm:col-span-2">
                                    <Label htmlFor="target-quantity" className="text-xs">
                                        Monthly target quantity
                                    </Label>
                                    <Input
                                        id="target-quantity"
                                        className="h-7 text-xs"
                                        type="number"
                                        min="0"
                                        step="0.001"
                                        value={form.data.target_quantity}
                                        onChange={(event) =>
                                            form.setData('target_quantity', event.target.value)
                                        }
                                        required
                                    />
                                    <InputError message={form.errors.target_quantity} />
                                </div>
                                <div className="space-y-0.5">
                                    <Label htmlFor="unit" className="text-xs">
                                        Main Unit
                                    </Label>
                                    <Input
                                        id="unit"
                                        className="h-7 text-xs"
                                        placeholder="e.g. case"
                                        value={form.data.unit}
                                        onChange={(event) =>
                                            form.setData('unit', event.target.value)
                                        }
                                    />
                                    <InputError message={form.errors.unit} />
                                </div>
                            </div>

                            {/* Row 4: Package Quantity & Package Unit */}
                            <div className="grid gap-2.5 sm:grid-cols-3">
                                <div className="space-y-0.5 sm:col-span-2">
                                    <Label htmlFor="package-quantity" className="text-xs">
                                        Package Sub-units count (1 Main Unit contains)
                                    </Label>
                                    <Input
                                        id="package-quantity"
                                        className="h-7 text-xs"
                                        type="number"
                                        min="0"
                                        step="0.001"
                                        placeholder="e.g. 12"
                                        value={form.data.package_quantity}
                                        onChange={(event) =>
                                            form.setData('package_quantity', event.target.value)
                                        }
                                    />
                                    <InputError message={form.errors.package_quantity} />
                                </div>
                                <div className="space-y-0.5">
                                    <Label htmlFor="package-unit" className="text-xs">
                                        Package Unit
                                    </Label>
                                    <Input
                                        id="package-unit"
                                        className="h-7 text-xs"
                                        placeholder="e.g. pcs"
                                        value={form.data.package_unit}
                                        onChange={(event) =>
                                            form.setData('package_unit', event.target.value)
                                        }
                                    />
                                    <InputError message={form.errors.package_unit} />
                                </div>
                            </div>

                            {/* Row 5: Active */}
                            <div className="flex h-7 items-center gap-2 self-end rounded-md border bg-muted/20 px-3">
                                <Checkbox
                                    id="item-active"
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_active', checked === true)
                                    }
                                />
                                <Label
                                    htmlFor="item-active"
                                    className="cursor-pointer font-normal text-xs"
                                >
                                    Active
                                </Label>
                            </div>

                            {/* Row 6: Notes */}
                            <div className="space-y-0.5">
                                <Label htmlFor="notes" className="text-xs">
                                    Notes
                                </Label>
                                <Input
                                    id="notes"
                                    value={form.data.notes}
                                    onChange={(event) => form.setData('notes', event.target.value)}
                                    className="h-7 text-xs"
                                    placeholder="Optional notes..."
                                />
                                <InputError message={form.errors.notes} />
                            </div>
                        </div>

                        <DialogFooter className="border-t bg-muted/30 px-4 py-2.5">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-7 px-3 text-xs"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                size="sm"
                                className="h-7 px-3 text-xs"
                                disabled={form.processing}
                            >
                                {form.processing ? 'Saving...' : 'Save item'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deactivating !== null}
                onOpenChange={(next) => !next && setDeactivating(null)}
            >
                <DialogContent hideCloseButton>
                    <DialogHeader>
                        <DialogTitle>Deactivate this item?</DialogTitle>
                        <DialogDescription>
                            {deactivating?.description} will stop appearing as a future required PO
                            item.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeactivating(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!deactivating) return;
                                router.delete(`/admin/purchase-orders/items/${deactivating.id}`, {
                                    onSuccess: () => setDeactivating(null),
                                });
                            }}
                        >
                            <Archive className="size-4" />
                            Deactivate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function FilterSelect({
    id,
    label,
    value,
    onChange,
    options,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <div className="min-w-36 space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value === '' ? 'all' : value}
                onValueChange={(next) => onChange(next === 'all' ? '' : next)}
            >
                <SelectTrigger id={id} className="h-8 w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

function formatQuantity(value: number) {
    return value.toLocaleString(undefined, {
        maximumFractionDigits: 3,
    });
}

PurchaseOrderItemsIndex.layout = {
    breadcrumbs: [
        { title: 'Purchase orders', href: '/admin/purchase-orders' },
        { title: 'PO item records', href: '/admin/purchase-orders/items' },
    ],
};
