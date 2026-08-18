import { Head, Link, router, useForm } from '@inertiajs/react';
import { PackageOpen, Plus, Search } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { WarehouseField } from './components/form-fields';
import { WarehouseProcessNav } from './components/process-nav';
import type { InventoryItem, Paginator, WarehouseItemOption } from './types';
import { localDate, quantity } from './utils';

export default function WarehouseInventory({
    inventory,
    warehouseItems,
    filters,
}: {
    inventory: Paginator<InventoryItem>;
    warehouseItems: WarehouseItemOption[];
    filters?: { search?: string };
}) {
    const [openingOpen, setOpeningOpen] = useState(false);
    const [search, setSearch] = useState(filters?.search ?? '');

    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== (filters?.search ?? '')) {
                router.get(
                    '/warehouse/inventory',
                    { search },
                    { preserveState: true, preserveScroll: true, replace: true },
                );
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [search, filters?.search]);

    const today = localDate();
    const form = useForm({
        warehouse_item_id: '',
        sku_number: '',
        description: '',
        unit: '',
        quantity_received: '',
        received_at: '',
        received_date_quality: 'unknown',
        lot_number: '',
        notes: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/warehouse/opening-stock', {
            preserveScroll: true,
            onSuccess: () => {
                setOpeningOpen(false);
                form.reset();
            },
        });
    }

    return (
        <>
            <Head title="Warehouse Inventory" />
            <PageShell
                title="Current warehouse inventory"
                description="Step 2: review stock created by confirmed arrivals and opening balances."
                actions={
                    <Button size="sm" className="h-8 text-xs" onClick={() => setOpeningOpen(true)}>
                        <Plus className="mr-1 size-3.5" /> Opening stock
                    </Button>
                }
            >
                <FlashMessage />
                <WarehouseProcessNav current="inventory" />

                <Card>
                    <CardHeader className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                        <div>
                            <CardTitle>Inventory balance</CardTitle>
                        </div>
                        <div className="relative w-full shrink-0 sm:w-64">
                            <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                type="search"
                                placeholder="Search items..."
                                className="h-9 w-full bg-background pl-8"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full whitespace-nowrap text-left text-xs">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="p-3 font-medium">Item</th>
                                        <th className="p-3 text-right font-medium">
                                            Total Received
                                        </th>
                                        <th className="p-3 text-right font-medium">Allocated</th>
                                        <th className="p-3 text-right font-medium">Available</th>
                                        <th className="p-3 font-medium">
                                            Oldest warehouse stock date
                                        </th>
                                        <th className="p-3 font-medium">Batches</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {inventory.data.map((item) => (
                                        <tr key={item.id}>
                                            <td className="p-3">
                                                <p className="font-medium">{item.description}</p>
                                                <p className="text-muted-foreground">
                                                    {item.sku_number ?? 'No SKU'} /{' '}
                                                    {item.unit ?? 'No unit'}
                                                </p>
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {quantity(item.received_quantity)}
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {quantity(item.allocated_quantity)}
                                            </td>
                                            <td className="p-3 text-right font-semibold tabular-nums">
                                                {quantity(item.available_quantity)}
                                            </td>
                                            <td className="p-3">
                                                {item.oldest_received_at ?? 'Unknown'}
                                            </td>
                                            <td className="p-3">
                                                {item.lot_count}
                                                {item.unknown_date_lots > 0
                                                    ? ` / ${item.unknown_date_lots} unknown-date`
                                                    : ''}
                                            </td>
                                        </tr>
                                    ))}
                                    {inventory.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="p-12 text-center">
                                                <PackageOpen className="mx-auto mb-3 size-8 text-muted-foreground" />
                                                <p className="font-medium">
                                                    No stock is available yet
                                                </p>
                                                <p className="mt-1 text-muted-foreground">
                                                    Confirm an arrival or add opening stock to begin
                                                    tracking inventory.
                                                </p>
                                                <div className="mt-4 flex justify-center gap-2">
                                                    <Button asChild variant="outline">
                                                        <Link href="/warehouse/arrivals">
                                                            Open arrivals
                                                        </Link>
                                                    </Button>
                                                    <Button onClick={() => setOpeningOpen(true)}>
                                                        Add opening stock
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-4">
                            <PaginationNav
                                currentPage={inventory.current_page}
                                lastPage={inventory.last_page}
                                previousUrl={inventory.prev_page_url}
                                nextUrl={inventory.next_page_url}
                                label="Warehouse inventory pages"
                            />
                        </div>
                    </CardContent>
                </Card>
            </PageShell>

            <Dialog open={openingOpen} onOpenChange={setOpeningOpen}>
                <DialogContent className="flex flex-col p-0 sm:max-w-lg">
                    <DialogHeader className="gap-0.5 border-b px-4 pt-3 pb-2">
                        <DialogTitle className="font-semibold text-sm">
                            Add opening stock
                        </DialogTitle>
                        <DialogDescription className="text-[10px] text-muted-foreground leading-tight">
                            Use this only for stock already held before this workflow began. Unknown
                            dates remain unknown in reporting.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit}>
                        <div className="space-y-2.5 p-4">
                            <WarehouseField label="Item" error={form.errors.warehouse_item_id}>
                                <Select
                                    value={form.data.warehouse_item_id}
                                    onValueChange={(value) =>
                                        form.setData('warehouse_item_id', value)
                                    }
                                >
                                    <SelectTrigger className="h-7 w-full text-xs">
                                        <SelectValue placeholder="Select an item" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {warehouseItems.map((item) => (
                                            <SelectItem key={item.id} value={String(item.id)}>
                                                {item.description}
                                                {item.sku_number ? ` (${item.sku_number})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </WarehouseField>

                            <div className="grid gap-2.5 sm:grid-cols-2">
                                <WarehouseField
                                    label="Quantity"
                                    error={form.errors.quantity_received}
                                >
                                    <Input
                                        type="number"
                                        min="0.001"
                                        step="0.001"
                                        value={form.data.quantity_received}
                                        onChange={(event) =>
                                            form.setData('quantity_received', event.target.value)
                                        }
                                        required
                                        className="h-7 text-xs"
                                    />
                                </WarehouseField>
                                <WarehouseField
                                    label="Arrival-date quality"
                                    error={form.errors.received_date_quality}
                                >
                                    <Select
                                        value={form.data.received_date_quality}
                                        onValueChange={(value) => {
                                            form.setData('received_date_quality', value);
                                            if (value === 'unknown')
                                                form.setData('received_at', '');
                                        }}
                                    >
                                        <SelectTrigger className="h-7 w-full text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="unknown">Unknown</SelectItem>
                                            <SelectItem value="estimated">Estimated</SelectItem>
                                            <SelectItem value="confirmed">Confirmed</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </WarehouseField>
                            </div>

                            <div className="grid gap-2.5 sm:grid-cols-2">
                                {form.data.received_date_quality !== 'unknown' ? (
                                    <WarehouseField
                                        label="Historical warehouse arrival date"
                                        error={form.errors.received_at}
                                    >
                                        <Input
                                            type="date"
                                            max={today}
                                            value={form.data.received_at}
                                            onChange={(event) =>
                                                form.setData('received_at', event.target.value)
                                            }
                                            required
                                            className="h-7 text-xs"
                                        />
                                    </WarehouseField>
                                ) : null}
                                <WarehouseField label="Batch number" error={form.errors.lot_number}>
                                    <Input
                                        value={form.data.lot_number}
                                        onChange={(event) =>
                                            form.setData('lot_number', event.target.value)
                                        }
                                        className="h-7 text-xs"
                                        placeholder="Optional batch #"
                                    />
                                </WarehouseField>
                            </div>

                            <WarehouseField label="Notes" error={form.errors.notes}>
                                <Input
                                    value={form.data.notes}
                                    onChange={(event) => form.setData('notes', event.target.value)}
                                    className="h-7 text-xs"
                                    placeholder="Enter any additional notes..."
                                />
                            </WarehouseField>
                        </div>

                        <DialogFooter className="border-t bg-muted/30 px-4 py-2.5">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-7 px-3 text-xs"
                                onClick={() => setOpeningOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                size="sm"
                                className="h-7 px-3 text-xs"
                                disabled={form.processing}
                            >
                                {form.processing ? 'Saving...' : 'Save opening stock'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
