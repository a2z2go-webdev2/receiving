import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardCheck,
    Layers,
    Package,
    Search,
} from 'lucide-react';
import { type FormEvent, Fragment, useEffect, useState } from 'react';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { WarehouseField } from './components/form-fields';
import { WarehouseProcessNav } from './components/process-nav';
import type { Paginator, PendingArrival, PendingPoGroup } from './types';
import { quantity } from './utils';

type ViewMode = 'by-po' | 'by-item';

export default function WarehouseArrivals({
    pendingArrivals,
    pendingCount,
    pendingPoGroups,
    filters,
}: {
    pendingArrivals: Paginator<PendingArrival>;
    pendingCount: number;
    pendingPoGroups: PendingPoGroup[];
    filters?: { search?: string };
}) {
    const [viewMode, setViewMode] = useState<ViewMode>('by-po');
    const [search, setSearch] = useState(filters?.search ?? '');

    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== (filters?.search ?? '')) {
                router.get(
                    '/warehouse/arrivals',
                    { search },
                    { preserveState: true, preserveScroll: true, replace: true },
                );
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [search, filters?.search]);

    // Single-item confirmation state
    const [arrival, setArrival] = useState<PendingArrival | null>(null);
    const itemForm = useForm({
        quantity_received: '',
        notes: '',
    });

    // PO confirmation state
    const [selectedPo, setSelectedPo] = useState<PendingPoGroup | null>(null);
    const [poNotes, setPoNotes] = useState('');
    const [poSubmitting, setPoSubmitting] = useState(false);
    const [poErrors, setPoErrors] = useState<Record<string, string>>({});

    function openArrival(next: PendingArrival) {
        setArrival(next);
        itemForm.setData({
            quantity_received: String(next.supplier_delivered_quantity),
            notes: '',
        });
        itemForm.clearErrors();
    }

    function submitItem(event: FormEvent) {
        event.preventDefault();
        if (!arrival) return;
        itemForm.post(`/warehouse/arrivals/${arrival.id}/confirm`, {
            preserveScroll: true,
            onSuccess: () => setArrival(null),
        });
    }

    function openPo(group: PendingPoGroup) {
        setSelectedPo(group);
        setPoNotes('');
        setPoErrors({});
    }

    function submitPo(event: FormEvent) {
        event.preventDefault();
        if (!selectedPo || poSubmitting) return;
        setPoSubmitting(true);
        setPoErrors({});
        router.post(
            '/warehouse/arrivals/confirm-by-po',
            {
                po_number: String(selectedPo.po_number),
                notes: poNotes,
            },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedPo(null),
                onError: (errors) => setPoErrors(errors),
                onFinish: () => setPoSubmitting(false),
            },
        );
    }

    return (
        <>
            <Head title="Confirm Warehouse Arrivals" />
            <PageShell
                title="Confirm warehouse arrivals"
                description="Step 1: confirm when PO/invoice items are physically received in the warehouse."
            >
                <FlashMessage />
                <WarehouseProcessNav current="arrivals" />

                {/* View mode tabs */}
                <div className="flex w-fit items-center gap-1 rounded-lg bg-muted p-1">
                    <button
                        type="button"
                        onClick={() => setViewMode('by-po')}
                        className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium text-sm transition-colors ${
                            viewMode === 'by-po'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Layers className="size-3.5" />
                        By PO
                    </button>
                    <button
                        type="button"
                        onClick={() => setViewMode('by-item')}
                        className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium text-sm transition-colors ${
                            viewMode === 'by-item'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Package className="size-3.5" />
                        By Item
                    </button>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle className="flex items-center gap-2">
                                <ClipboardCheck className="size-5 text-primary" />
                                {viewMode === 'by-po'
                                    ? 'Purchase orders waiting to be received'
                                    : 'Items waiting to be received'}
                            </CardTitle>
                            {viewMode === 'by-item' && (
                                <CardDescription>
                                    Confirm individual items one at a time. You can adjust the
                                    received quantity for each item.
                                </CardDescription>
                            )}
                        </div>
                        <div className="mt-4 flex flex-col items-start gap-4 sm:mt-0 sm:flex-row sm:items-center">
                            <div className="relative w-full sm:w-64">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    type="search"
                                    placeholder="Search PO, items..."
                                    className="h-9 w-full bg-background pl-8"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                            <span className="shrink-0 rounded-full bg-amber-100 px-3 py-1 font-semibold text-amber-800 text-sm">
                                {viewMode === 'by-po'
                                    ? `${pendingPoGroups.length} PO${pendingPoGroups.length !== 1 ? 's' : ''}`
                                    : `${pendingCount.toLocaleString()} items`}{' '}
                                waiting
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {viewMode === 'by-po' ? (
                            <ByPoView groups={pendingPoGroups} onReceivePo={openPo} />
                        ) : (
                            <ByItemView arrivals={pendingArrivals} onReceiveItem={openArrival} />
                        )}
                    </CardContent>
                </Card>
            </PageShell>

            {/* Single-item confirmation dialog */}
            <Dialog open={arrival !== null} onOpenChange={(open) => !open && setArrival(null)}>
                <DialogContent className="flex max-h-[85vh] flex-col gap-0 p-0 sm:max-w-md">
                    <DialogHeader className="gap-1 px-4 pt-4 pb-2">
                        <DialogTitle className="text-base">Receive item</DialogTitle>
                        <DialogDescription className="text-[11px] leading-tight">
                            Confirm the quantity received. Dwell tracking starts automatically at
                            current system time.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={submitItem}
                        noValidate
                        className="flex flex-col overflow-hidden"
                    >
                        <div className="space-y-4 overflow-y-auto px-4 py-2">
                            <FormErrorBanner errors={itemForm.errors} />

                            {/* Item Information Section */}
                            <div className="border-primary/40 border-l-2 pl-3">
                                <h3 className="mb-1 font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                    Item Information
                                </h3>
                                <p className="text-[11px] leading-snug">
                                    <span className="font-medium text-foreground">
                                        {arrival?.description}
                                    </span>
                                    <br />
                                    <span className="text-muted-foreground">
                                        PO {arrival?.po_number ?? 'N/A'} &bull; Delivery{' '}
                                        {arrival?.supplier_delivery_date ?? 'N/A'} &bull;{' '}
                                        {waitingTime(arrival?.po_waiting_days ?? null)} waiting
                                    </span>
                                </p>
                            </div>

                            {/* Receipt Details Section */}
                            <div className="border-primary/40 border-l-2 pl-3">
                                <h3 className="mb-1.5 font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                    Receipt Details
                                </h3>
                                <div>
                                    <WarehouseField
                                        label="Quantity received"
                                        error={itemForm.errors.quantity_received}
                                    >
                                        <Input
                                            type="number"
                                            min="0.001"
                                            step="0.001"
                                            value={itemForm.data.quantity_received}
                                            onChange={(event) =>
                                                itemForm.setData(
                                                    'quantity_received',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            className="h-7 text-xs"
                                        />
                                    </WarehouseField>
                                </div>
                            </div>

                            {/* Additional Notes Section */}
                            <div className="border-primary/40 border-l-2 pl-3">
                                <h3 className="mb-1.5 font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                    Additional Notes
                                </h3>
                                <WarehouseField label="Notes" error={itemForm.errors.notes}>
                                    <textarea
                                        className="h-10 w-full resize-none rounded-md border border-input bg-transparent px-2 py-1 text-[11px] shadow-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        value={itemForm.data.notes}
                                        onChange={(event) =>
                                            itemForm.setData('notes', event.target.value)
                                        }
                                        placeholder="Enter any additional notes..."
                                    />
                                </WarehouseField>
                            </div>
                        </div>

                        <DialogFooter className="border-t bg-muted/30 px-4 py-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setArrival(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={itemForm.processing}>
                                {itemForm.processing ? 'Receiving…' : 'Receive item'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* PO bulk confirmation dialog */}
            <Dialog
                open={selectedPo !== null}
                onOpenChange={(open) => !open && setSelectedPo(null)}
            >
                <DialogContent className="flex max-h-[85vh] flex-col gap-0 p-0 sm:max-w-lg">
                    <DialogHeader className="gap-1 px-4 pt-4 pb-2">
                        <DialogTitle className="text-base">
                            Receive PO {selectedPo?.po_number}
                        </DialogTitle>
                        <DialogDescription className="text-[11px] leading-tight">
                            Receiving {selectedPo?.pending_item_count ?? selectedPo?.item_count}{' '}
                            pending item
                            {(selectedPo?.pending_item_count ?? selectedPo?.item_count ?? 0) !== 1
                                ? 's'
                                : ''}{' '}
                            in this PO. Dwell tracking starts automatically using supplier-delivered
                            quantities.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitPo} noValidate className="flex flex-col overflow-hidden">
                        <div className="space-y-4 overflow-y-auto px-4 py-2">
                            <FormErrorBanner errors={poErrors} />

                            {/* Items Summary Section */}
                            <div className="border-primary/40 border-l-2 pl-3">
                                <h3 className="mb-1.5 font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                    Items to receive (
                                    {selectedPo?.pending_item_count ?? selectedPo?.item_count})
                                </h3>
                                <div className="max-h-48 divide-y overflow-y-auto rounded-md border">
                                    {selectedPo?.items.map((item) => (
                                        <div
                                            key={item.id}
                                            className={`flex items-center justify-between px-3 py-2 text-[11px] ${
                                                item.is_received ? 'bg-muted/30 opacity-70' : ''
                                            }`}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <p className="truncate font-medium text-foreground">
                                                        {item.description}
                                                    </p>
                                                    {item.is_received ? (
                                                        <span className="inline-flex items-center gap-0.5 rounded-full bg-emerald-100 px-1.5 py-0.5 font-semibold text-[9px] text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                            <CheckCircle2 className="size-2.5" />
                                                            Already received
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 font-semibold text-[9px] text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                                            To receive
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-muted-foreground">
                                                    {item.sku_number ?? 'No SKU'}
                                                </p>
                                            </div>
                                            <span className="ml-3 shrink-0 font-medium tabular-nums">
                                                {quantity(item.supplier_delivered_quantity)}{' '}
                                                {item.unit ?? ''}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Additional Notes Section */}
                            <div className="border-primary/40 border-l-2 pl-3">
                                <h3 className="mb-1.5 font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                    Additional Notes
                                </h3>
                                <WarehouseField label="Notes" error={poErrors.notes}>
                                    <textarea
                                        className="h-10 w-full resize-none rounded-md border border-input bg-transparent px-2 py-1 text-[11px] shadow-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        value={poNotes}
                                        onChange={(event) => setPoNotes(event.target.value)}
                                        placeholder="Enter any additional notes..."
                                    />
                                </WarehouseField>
                            </div>
                        </div>

                        <DialogFooter className="border-t bg-muted/30 px-4 py-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSelectedPo(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={poSubmitting}>
                                {poSubmitting
                                    ? 'Receiving…'
                                    : `Receive ${selectedPo?.pending_item_count ?? selectedPo?.item_count} item${
                                          (
                                              selectedPo?.pending_item_count ??
                                                  selectedPo?.item_count ??
                                                  0
                                          ) !== 1
                                              ? 's'
                                              : ''
                                      }`}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function FormErrorBanner({ errors }: { errors: Record<string, string> }) {
    if (Object.keys(errors).length === 0) return null;
    return (
        <div className="flex items-start gap-2 rounded-md border border-destructive/20 bg-destructive/10 p-3 text-destructive">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <div className="font-medium text-[11px] leading-snug">
                {Object.values(errors).map((error) => (
                    <p key={error as string}>{error as string}</p>
                ))}
            </div>
        </div>
    );
}

/* ─── By PO View ─── */

function ByPoView({
    groups,
    onReceivePo,
}: {
    groups: PendingPoGroup[];
    onReceivePo: (group: PendingPoGroup) => void;
}) {
    const [expandedPo, setExpandedPo] = useState<string | null>(null);

    const togglePo = (poNumber: string) => {
        setExpandedPo((prev) => (prev === poNumber ? null : poNumber));
    };

    if (groups.length === 0) {
        return <EmptyState />;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full whitespace-nowrap text-left text-xs">
                <thead className="border-b bg-muted/50">
                    <tr>
                        <th className="w-8 p-3"></th>
                        <th className="p-3 font-medium">PO Number</th>
                        <th className="p-3 font-medium">PO Date</th>
                        <th className="p-3 text-right font-medium">Items</th>
                        <th className="p-3 text-right font-medium">Total Qty</th>
                        <th className="p-3 font-medium">Supplier Delivery Date</th>
                        <th className="p-3">
                            <span className="sr-only">Action</span>
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {groups.map((group) => {
                        const isExpanded = expandedPo === group.po_number;
                        return (
                            <Fragment key={group.po_number}>
                                <tr
                                    onClick={() => togglePo(group.po_number)}
                                    className="cursor-pointer hover:bg-muted/30"
                                >
                                    <td className="p-3">
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                togglePo(group.po_number);
                                            }}
                                            className="flex size-6 items-center justify-center rounded-md hover:bg-muted"
                                        >
                                            {isExpanded ? (
                                                <ChevronDown className="size-4" />
                                            ) : (
                                                <ChevronRight className="size-4" />
                                            )}
                                        </button>
                                    </td>
                                    <td className="p-3">
                                        <p className="font-medium">{group.po_number}</p>
                                    </td>
                                    <td className="p-3">{group.po_date ?? 'Not available'}</td>
                                    <td className="p-3 text-right tabular-nums">
                                        {group.pending_item_count === group.item_count
                                            ? `${group.item_count} item${group.item_count !== 1 ? 's' : ''}`
                                            : `${group.pending_item_count} of ${group.item_count} items waiting`}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {quantity(group.total_supplier_delivered_quantity)}
                                    </td>
                                    <td className="p-3">
                                        {group.supplier_delivery_date ?? 'Not available'}
                                    </td>
                                    <td className="p-3 text-right">
                                        <Button
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onReceivePo(group);
                                            }}
                                        >
                                            Receive PO
                                        </Button>
                                    </td>
                                </tr>
                                {isExpanded && (
                                    <tr>
                                        <td colSpan={7} className="border-b bg-muted/10 p-0">
                                            <div className="my-2 ml-6 border-primary/20 border-l-2 px-12 py-3">
                                                <table className="w-full whitespace-nowrap text-left text-xs">
                                                    <thead className="border-muted/50 border-b text-muted-foreground">
                                                        <tr>
                                                            <th className="pb-2 font-medium">
                                                                Item Description
                                                            </th>
                                                            <th className="pb-2 font-medium">
                                                                SKU
                                                            </th>
                                                            <th className="pb-2 text-right font-medium">
                                                                Ordered
                                                            </th>
                                                            <th className="pb-2 text-right font-medium">
                                                                Delivered
                                                            </th>
                                                            <th className="pb-2 text-right font-medium">
                                                                Status
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-muted/20">
                                                        {group.items.map((item) => (
                                                            <tr key={item.id}>
                                                                <td className="py-2">
                                                                    {item.description}
                                                                </td>
                                                                <td className="py-2 text-muted-foreground">
                                                                    {item.sku_number ?? '—'}
                                                                </td>
                                                                <td className="py-2 text-right text-muted-foreground tabular-nums">
                                                                    {item.ordered_quantity
                                                                        ? quantity(
                                                                              item.ordered_quantity,
                                                                          )
                                                                        : '—'}
                                                                </td>
                                                                <td className="py-2 text-right font-medium tabular-nums">
                                                                    {quantity(
                                                                        item.supplier_delivered_quantity,
                                                                    )}{' '}
                                                                    {item.unit ?? ''}
                                                                </td>
                                                                <td className="py-2 text-right">
                                                                    {item.is_received ? (
                                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-[10px] text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                                            <CheckCircle2 className="size-3" />
                                                                            Received
                                                                            {item.lot_number
                                                                                ? ` (Batch: ${item.lot_number})`
                                                                                : ''}
                                                                        </span>
                                                                    ) : (
                                                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-[10px] text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                                                            Pending
                                                                        </span>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/* ─── By Item View ─── */

function ByItemView({
    arrivals,
    onReceiveItem,
}: {
    arrivals: Paginator<PendingArrival>;
    onReceiveItem: (arrival: PendingArrival) => void;
}) {
    return (
        <>
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full whitespace-nowrap text-left text-xs">
                    <thead className="border-b bg-muted/50">
                        <tr>
                            <th className="p-3 font-medium">Item</th>
                            <th className="p-3 font-medium">PO</th>
                            <th className="p-3 text-right font-medium">Ordered</th>
                            <th className="p-3 text-right font-medium">Supplier delivered</th>
                            <th className="p-3 font-medium">Supplier delivery date</th>
                            <th className="p-3 text-right font-medium">PO waiting time</th>
                            <th className="p-3">
                                <span className="sr-only">Action</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {arrivals.data.map((row) => (
                            <tr key={row.id}>
                                <td className="p-3">
                                    <p className="font-medium">{row.description}</p>
                                    <p className="text-muted-foreground">
                                        {row.sku_number ?? 'No SKU'}
                                    </p>
                                </td>
                                <td className="p-3">
                                    <p>{row.po_number ?? 'No PO number'}</p>
                                    <p className="text-muted-foreground">
                                        {row.po_date ?? 'PO date unavailable'}
                                    </p>
                                </td>
                                <td className="p-3 text-right tabular-nums">
                                    {row.ordered_quantity === null
                                        ? 'Not available'
                                        : `${quantity(row.ordered_quantity)} ${row.unit ?? ''}`}
                                </td>
                                <td className="p-3 text-right tabular-nums">
                                    {quantity(row.supplier_delivered_quantity)} {row.unit ?? ''}
                                </td>
                                <td className="p-3">
                                    {row.supplier_delivery_date ?? 'Not available'}
                                </td>
                                <td className="p-3 text-right font-medium tabular-nums">
                                    {waitingTime(row.po_waiting_days)}
                                </td>
                                <td className="p-3 text-right">
                                    <Button
                                        size="sm"
                                        className="h-7 text-xs"
                                        onClick={() => onReceiveItem(row)}
                                    >
                                        Receive item
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {arrivals.data.length === 0 && <EmptyRow colSpan={7} />}
                    </tbody>
                </table>
            </div>
            <div className="mt-4">
                <PaginationNav
                    currentPage={arrivals.current_page}
                    lastPage={arrivals.last_page}
                    previousUrl={arrivals.prev_page_url}
                    nextUrl={arrivals.next_page_url}
                    label="Pending receipt pages"
                />
            </div>
        </>
    );
}

/* ─── Shared ─── */

function EmptyState() {
    return (
        <div className="rounded-lg border p-12 text-center">
            <CheckCircle2 className="mx-auto mb-3 size-8 text-emerald-600" />
            <p className="font-medium">All delivered items have been received</p>
            <p className="mt-1 text-muted-foreground text-sm">
                New linked PO/invoice items will appear here automatically.
            </p>
        </div>
    );
}

function EmptyRow({ colSpan }: { colSpan: number }) {
    return (
        <tr>
            <td colSpan={colSpan} className="p-12 text-center">
                <CheckCircle2 className="mx-auto mb-3 size-8 text-emerald-600" />
                <p className="font-medium">All delivered items have been received</p>
                <p className="mt-1 text-muted-foreground">
                    New linked PO/invoice items will appear here automatically.
                </p>
            </td>
        </tr>
    );
}

function waitingTime(value: number | null) {
    if (value === null) return 'Not available';
    if (value === 0) return 'Same day';
    return `${value.toLocaleString()} days`;
}
