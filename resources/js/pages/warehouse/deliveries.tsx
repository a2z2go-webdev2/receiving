import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    ChevronDown,
    ChevronRight,
    ChevronsUpDown,
    FileText,
    Package,
    Pencil,
    Plus,
    Search,
    Send,
    Trash2,
    Truck,
} from 'lucide-react';
import React, { type FormEvent, useEffect, useState } from 'react';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { WarehouseField } from './components/form-fields';
import { WarehouseProcessNav } from './components/process-nav';
import type { DeliveryItemOption, Paginator, TruckShipment } from './types';
import { quantity } from './utils';

type DeliveryLineInput = {
    client_id: string;
    warehouse_item_id: string;
    quantity: string;
};

type DeliveryEntryInput = {
    client_id: string;
    id?: number;
    customer_name: string;
    sales_order: string;
    po: string;
    notes: string;
    lines: DeliveryLineInput[];
};

export default function WarehouseDeliveries({
    deliveries,
    deliveryItems,
    activeTab,
    counts,
    filters,
}: {
    deliveries: Paginator<TruckShipment>;
    deliveryItems: DeliveryItemOption[];
    activeTab: 'draft' | 'dispatched' | 'delivered';
    counts: { draft: number; dispatched: number };
    filters?: { search?: string };
}) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editingShipment, setEditingShipment] = useState<TruckShipment | null>(null);
    const [dispatchingShipment, setDispatchingShipment] = useState<TruckShipment | null>(null);
    const [removingShipment, setRemovingShipment] = useState<TruckShipment | null>(null);
    const [expandedRows, setExpandedRows] = useState<string[]>([]);
    const [expandedCustomerDeliveryId, setExpandedCustomerDeliveryId] = useState<
        Record<string, number | null>
    >({});
    const [expandedDeliveryIndex, setExpandedDeliveryIndex] = useState<number>(0);
    const [wasSubmitted, setWasSubmitted] = useState(false);
    const [search, setSearch] = useState(filters?.search ?? '');

    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== (filters?.search ?? '')) {
                router.get(
                    '/warehouse/deliveries',
                    { search, status: activeTab },
                    { preserveState: true, preserveScroll: true, replace: true },
                );
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [search, filters?.search, activeTab]);

    function toggleRow(ref: string) {
        setExpandedRows((prev) =>
            prev.includes(ref) ? prev.filter((r) => r !== ref) : [...prev, ref],
        );
    }

    function toggleCustomerDelivery(shipmentRef: string, deliveryId: number) {
        setExpandedCustomerDeliveryId((prev) => ({
            ...prev,
            [shipmentRef]: prev[shipmentRef] === deliveryId ? null : deliveryId,
        }));
    }

    const form = useForm({
        dispatch_immediately: false,
        deliveries: [
            {
                client_id: crypto.randomUUID(),
                customer_name: '',
                sales_order: '',
                po: '',
                notes: '',
                lines: [{ client_id: crypto.randomUUID(), warehouse_item_id: '', quantity: '' }],
            },
        ] as DeliveryEntryInput[],
    });

    function handleCreateSubmit(dispatchImmediately: boolean, event: FormEvent) {
        event.preventDefault();
        setWasSubmitted(true);

        for (let i = 0; i < form.data.deliveries.length; i++) {
            const del = form.data.deliveries[i];
            if (!del.customer_name.trim() || !del.sales_order.trim() || !del.po.trim()) {
                setExpandedDeliveryIndex(i);
                return;
            }
            for (const line of del.lines) {
                if (!line.warehouse_item_id || !line.quantity || Number(line.quantity) <= 0) {
                    setExpandedDeliveryIndex(i);
                    return;
                }
            }
        }

        form.setData('dispatch_immediately', dispatchImmediately);
        form.post('/warehouse/deliveries/bulk', {
            preserveScroll: true,
            onError: (errs: Record<string, string>) => {
                const firstErrKey = Object.keys(errs).find((k) => k.startsWith('deliveries.'));
                if (firstErrKey) {
                    const match = firstErrKey.match(/^deliveries\.(\d+)\./);
                    if (match) {
                        setExpandedDeliveryIndex(parseInt(match[1], 10));
                    }
                }
            },
            onSuccess: () => {
                setCreateOpen(false);
                form.reset();
                setExpandedDeliveryIndex(0);
                setWasSubmitted(false);
            },
        });
    }

    function addDeliveryEntry() {
        const nextIndex = form.data.deliveries.length;
        form.setData('deliveries', [
            ...form.data.deliveries,
            {
                client_id: crypto.randomUUID(),
                customer_name: '',
                sales_order: '',
                po: '',
                notes: '',
                lines: [{ client_id: crypto.randomUUID(), warehouse_item_id: '', quantity: '' }],
            },
        ]);
        setExpandedDeliveryIndex(nextIndex);
    }

    function removeDeliveryEntry(delIndex: number) {
        if (form.data.deliveries.length <= 1) return;
        form.setData(
            'deliveries',
            form.data.deliveries.filter((_, i) => i !== delIndex),
        );
        setExpandedDeliveryIndex((prev) => (prev >= delIndex ? Math.max(0, prev - 1) : prev));
    }

    function updateDeliveryField(
        delIndex: number,
        field: keyof Omit<DeliveryEntryInput, 'client_id' | 'lines'>,
        value: string,
    ) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex ? { ...del, [field]: value } : del,
            ),
        );
    }

    function addLine(delIndex: number) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex
                    ? {
                          ...del,
                          lines: [
                              ...del.lines,
                              {
                                  client_id: crypto.randomUUID(),
                                  warehouse_item_id: '',
                                  quantity: '',
                              },
                          ],
                      }
                    : del,
            ),
        );
    }

    function removeLine(delIndex: number, lineIndex: number) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex
                    ? {
                          ...del,
                          lines: del.lines.filter((_, li) => li !== lineIndex),
                      }
                    : del,
            ),
        );
    }

    function updateLine(
        delIndex: number,
        lineIndex: number,
        field: 'warehouse_item_id' | 'quantity',
        value: string,
    ) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex
                    ? {
                          ...del,
                          lines: del.lines.map((line, li) =>
                              li === lineIndex ? { ...line, [field]: value } : line,
                          ),
                      }
                    : del,
            ),
        );
    }

    const errors = form.errors as Record<string, string>;

    return (
        <TooltipProvider delayDuration={150}>
            <Head title="Customer Deliveries" />
            <PageShell
                title="Customer deliveries"
                description="Step 3: create a draft truck shipment, allocate stock at dispatch, then confirm customer receipt."
                actions={
                    <Button
                        size="sm"
                        className="h-8 text-xs"
                        disabled={deliveryItems.length === 0}
                        onClick={() => {
                            setCreateOpen(true);
                            setExpandedDeliveryIndex(0);
                            setWasSubmitted(false);
                        }}
                    >
                        <Plus className="mr-1 size-3.5" /> New truck shipment
                    </Button>
                }
            >
                <FlashMessage />
                <WarehouseProcessNav current="deliveries" />

                <Card>
                    <CardHeader className="flex flex-col items-start justify-between gap-4 border-b px-6 py-4 sm:flex-row sm:items-center">
                        <div className="flex gap-6">
                            <Link
                                href={`/warehouse/deliveries?status=draft${search ? `&search=${search}` : ''}`}
                                className={cn(
                                    'flex items-center gap-2 whitespace-nowrap font-medium text-sm transition-colors hover:text-primary',
                                    activeTab === 'draft'
                                        ? '-mb-4 border-primary border-b-2 pb-4 text-primary'
                                        : 'text-muted-foreground',
                                )}
                            >
                                Draft Shipments
                                <Badge
                                    variant={activeTab === 'draft' ? 'default' : 'secondary'}
                                    className="h-4 min-w-5 justify-center rounded-full px-1.5 py-0 text-[10px] leading-none"
                                >
                                    {counts.draft}
                                </Badge>
                            </Link>
                            <Link
                                href={`/warehouse/deliveries?status=dispatched${search ? `&search=${search}` : ''}`}
                                className={cn(
                                    'flex items-center gap-2 whitespace-nowrap font-medium text-sm transition-colors hover:text-primary',
                                    activeTab === 'dispatched'
                                        ? '-mb-4 border-primary border-b-2 pb-4 text-primary'
                                        : 'text-muted-foreground',
                                )}
                            >
                                Dispatched Shipments
                                <Badge
                                    variant={activeTab === 'dispatched' ? 'default' : 'secondary'}
                                    className="h-4 min-w-5 justify-center rounded-full px-1.5 py-0 text-[10px] leading-none"
                                >
                                    {counts.dispatched}
                                </Badge>
                            </Link>
                            <Link
                                href={`/warehouse/deliveries?status=delivered${search ? `&search=${search}` : ''}`}
                                className={cn(
                                    'flex items-center gap-2 whitespace-nowrap font-medium text-sm transition-colors hover:text-primary',
                                    activeTab === 'delivered'
                                        ? '-mb-4 border-primary border-b-2 pb-4 text-primary'
                                        : 'text-muted-foreground',
                                )}
                            >
                                Delivered Shipments
                            </Link>
                        </div>
                        <div className="relative w-full shrink-0 sm:w-64">
                            <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                type="search"
                                placeholder="Search shipment, customer..."
                                className="h-9 w-full bg-background pl-8"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full whitespace-nowrap text-left text-sm">
                                <thead className="border-b bg-muted/20">
                                    <tr>
                                        <th className="w-10 p-3"></th>
                                        <th className="p-3 font-medium text-muted-foreground">
                                            Shipment Ref
                                        </th>
                                        <th className="p-3 font-medium text-muted-foreground">
                                            Customers
                                        </th>
                                        <th className="p-3 font-medium text-muted-foreground">
                                            {activeTab === 'draft'
                                                ? 'Created'
                                                : activeTab === 'dispatched'
                                                  ? 'Dispatched'
                                                  : 'Delivered'}
                                        </th>
                                        <th className="p-3 text-center font-medium text-muted-foreground">
                                            Total Items
                                        </th>
                                        <th className="p-3 text-right font-medium text-muted-foreground">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {deliveries.data.map((shipment) => {
                                        const isExpanded = expandedRows.includes(
                                            shipment.shipment_reference,
                                        );
                                        return (
                                            <React.Fragment key={shipment.shipment_reference}>
                                                <tr className="group transition-colors hover:bg-muted/10">
                                                    <td className="p-3 text-center align-middle">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-6 shrink-0 text-muted-foreground"
                                                            onClick={() =>
                                                                toggleRow(
                                                                    shipment.shipment_reference,
                                                                )
                                                            }
                                                        >
                                                            {isExpanded ? (
                                                                <ChevronDown className="size-4" />
                                                            ) : (
                                                                <ChevronRight className="size-4" />
                                                            )}
                                                        </Button>
                                                    </td>
                                                    <td className="p-3 align-middle font-mono font-semibold text-foreground">
                                                        <div className="flex items-center gap-1.5">
                                                            <Truck className="size-4 shrink-0 text-primary" />
                                                            {shipment.shipment_reference}
                                                        </div>
                                                    </td>
                                                    <td className="p-3 align-middle font-medium">
                                                        <div className="flex items-center gap-2">
                                                            <span>
                                                                {shipment.customers_summary}
                                                            </span>
                                                            <Badge
                                                                variant="secondary"
                                                                className="h-4 px-1.5 text-[10px]"
                                                            >
                                                                {shipment.customer_count}{' '}
                                                                customer(s)
                                                            </Badge>
                                                        </div>
                                                    </td>
                                                    <td className="whitespace-nowrap p-3 align-middle text-muted-foreground text-xs">
                                                        {activeTab === 'draft' &&
                                                            new Date(
                                                                shipment.created_at,
                                                            ).toLocaleDateString()}
                                                        {activeTab === 'dispatched' &&
                                                            shipment.dispatched_at}
                                                        {activeTab === 'delivered' &&
                                                            (shipment.delivered_at ?? '—')}
                                                    </td>
                                                    <td className="p-3 text-center align-middle">
                                                        <Badge
                                                            variant="outline"
                                                            className="font-mono"
                                                        >
                                                            {shipment.total_items_count} item(s)
                                                        </Badge>
                                                    </td>
                                                    <td className="p-3 text-right align-middle">
                                                        {shipment.status === 'draft' && (
                                                            <div className="flex items-center justify-end gap-1.5">
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            size="icon"
                                                                            variant="outline"
                                                                            className="size-7 text-muted-foreground hover:text-foreground"
                                                                            onClick={() =>
                                                                                setEditingShipment(
                                                                                    shipment,
                                                                                )
                                                                            }
                                                                        >
                                                                            <Pencil className="size-3.5" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>
                                                                        Edit truck shipment
                                                                    </TooltipContent>
                                                                </Tooltip>

                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            size="icon"
                                                                            variant="outline"
                                                                            className="size-7 text-destructive hover:text-destructive"
                                                                            onClick={() =>
                                                                                setRemovingShipment(
                                                                                    shipment,
                                                                                )
                                                                            }
                                                                        >
                                                                            <Trash2 className="size-3.5" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>
                                                                        Delete draft shipment
                                                                    </TooltipContent>
                                                                </Tooltip>

                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            size="icon"
                                                                            className="size-7"
                                                                            onClick={() =>
                                                                                setDispatchingShipment(
                                                                                    shipment,
                                                                                )
                                                                            }
                                                                        >
                                                                            <Send className="size-3.5" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>
                                                                        Dispatch truck shipment
                                                                    </TooltipContent>
                                                                </Tooltip>
                                                            </div>
                                                        )}
                                                        {shipment.status === 'dispatched' && (
                                                            <Badge className="bg-blue-600">
                                                                Dispatched
                                                            </Badge>
                                                        )}
                                                        {shipment.status === 'delivered' && (
                                                            <Badge className="bg-emerald-600">
                                                                Delivered
                                                            </Badge>
                                                        )}
                                                    </td>
                                                </tr>

                                                {/* Expanded Details: List of Compact Collapsible Customer Delivery Header Cards */}
                                                {isExpanded && (
                                                    <tr className="border-b bg-muted/5">
                                                        <td colSpan={6} className="p-0">
                                                            <div className="my-2 ml-4 max-w-3xl space-y-1.5 border-primary/30 border-l-2 py-1 pr-2 pl-4">
                                                                <h4 className="flex items-center gap-1.5 font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                                                    <Truck className="size-3 text-primary" />
                                                                    Customer Deliveries in Shipment
                                                                    ({shipment.deliveries.length})
                                                                </h4>

                                                                {shipment.deliveries.map(
                                                                    (delivery, dIdx) => {
                                                                        const isDeliveryExpanded =
                                                                            expandedCustomerDeliveryId[
                                                                                shipment
                                                                                    .shipment_reference
                                                                            ] === delivery.id;

                                                                        return (
                                                                            <div
                                                                                key={delivery.id}
                                                                                className="overflow-hidden rounded-md border bg-card shadow-2xs"
                                                                            >
                                                                                {/* Compact Collapsible Card Header Bar */}
                                                                                {/* biome-ignore lint/a11y/useSemanticElements: interactive accordion header */}
                                                                                <div
                                                                                    role="button"
                                                                                    tabIndex={0}
                                                                                    className={cn(
                                                                                        'flex cursor-pointer select-none items-center justify-between px-2.5 py-1.5 text-xs transition-colors hover:bg-muted/20',
                                                                                        isDeliveryExpanded &&
                                                                                            'border-b bg-muted/10',
                                                                                    )}
                                                                                    onClick={() =>
                                                                                        toggleCustomerDelivery(
                                                                                            shipment.shipment_reference,
                                                                                            delivery.id,
                                                                                        )
                                                                                    }
                                                                                    onKeyDown={(
                                                                                        e,
                                                                                    ) => {
                                                                                        if (
                                                                                            e.key ===
                                                                                                'Enter' ||
                                                                                            e.key ===
                                                                                                ' '
                                                                                        ) {
                                                                                            e.preventDefault();
                                                                                            toggleCustomerDelivery(
                                                                                                shipment.shipment_reference,
                                                                                                delivery.id,
                                                                                            );
                                                                                        }
                                                                                    }}
                                                                                >
                                                                                    <div className="flex flex-wrap items-center gap-1.5 text-xs">
                                                                                        <Button
                                                                                            type="button"
                                                                                            variant="ghost"
                                                                                            size="icon"
                                                                                            className="pointer-events-none size-4 shrink-0 p-0 text-muted-foreground"
                                                                                        >
                                                                                            {isDeliveryExpanded ? (
                                                                                                <ChevronDown className="size-3.5" />
                                                                                            ) : (
                                                                                                <ChevronRight className="size-3.5" />
                                                                                            )}
                                                                                        </Button>
                                                                                        <Badge
                                                                                            variant="outline"
                                                                                            className="h-4 px-1 font-mono text-[10px]"
                                                                                        >
                                                                                            Delivery
                                                                                            #
                                                                                            {dIdx +
                                                                                                1}
                                                                                        </Badge>
                                                                                        <span className="font-semibold text-foreground text-xs">
                                                                                            {
                                                                                                delivery.customer_name
                                                                                            }
                                                                                        </span>
                                                                                        <span className="text-[10px] text-muted-foreground">
                                                                                            (SO:{' '}
                                                                                            {
                                                                                                delivery.sales_order
                                                                                            }{' '}
                                                                                            • PO:{' '}
                                                                                            {
                                                                                                delivery.po
                                                                                            }
                                                                                            )
                                                                                        </span>
                                                                                    </div>
                                                                                    <div className="flex items-center gap-2">
                                                                                        <span className="font-mono text-[10px] text-muted-foreground">
                                                                                            {
                                                                                                delivery.delivery_reference
                                                                                            }
                                                                                        </span>
                                                                                        <Badge
                                                                                            variant="secondary"
                                                                                            className="h-4 px-1 font-mono text-[10px]"
                                                                                        >
                                                                                            {
                                                                                                delivery
                                                                                                    .lines
                                                                                                    .length
                                                                                            }{' '}
                                                                                            item(s)
                                                                                        </Badge>
                                                                                    </div>
                                                                                </div>

                                                                                {/* Expanded Card Body Details */}
                                                                                {isDeliveryExpanded && (
                                                                                    <div className="space-y-2 bg-background/50 px-2.5 py-2 text-[11px]">
                                                                                        {delivery.notes && (
                                                                                            <p className="text-[11px] text-muted-foreground italic">
                                                                                                Notes:{' '}
                                                                                                {
                                                                                                    delivery.notes
                                                                                                }
                                                                                            </p>
                                                                                        )}
                                                                                        <table className="w-full whitespace-nowrap text-left text-[11px]">
                                                                                            <thead className="border-b font-semibold text-[10px] text-muted-foreground uppercase tracking-wider">
                                                                                                <tr>
                                                                                                    <th className="pb-1 font-medium">
                                                                                                        Item
                                                                                                        Description
                                                                                                    </th>
                                                                                                    <th className="pb-1 font-medium">
                                                                                                        SKU
                                                                                                    </th>
                                                                                                    <th className="pb-1 text-right font-medium">
                                                                                                        Quantity
                                                                                                    </th>
                                                                                                </tr>
                                                                                            </thead>
                                                                                            <tbody className="divide-y divide-muted/20">
                                                                                                {delivery.lines.map(
                                                                                                    (
                                                                                                        line,
                                                                                                    ) => (
                                                                                                        <tr
                                                                                                            key={
                                                                                                                line.id
                                                                                                            }
                                                                                                        >
                                                                                                            <td className="py-1">
                                                                                                                <div className="flex items-center gap-1 font-medium">
                                                                                                                    <Package className="size-3 text-muted-foreground/70" />
                                                                                                                    {
                                                                                                                        line.description
                                                                                                                    }
                                                                                                                </div>
                                                                                                            </td>
                                                                                                            <td className="py-1 font-mono text-[10px] text-muted-foreground">
                                                                                                                {line.sku_number ??
                                                                                                                    '—'}
                                                                                                            </td>
                                                                                                            <td className="py-1 text-right font-medium tabular-nums">
                                                                                                                {quantity(
                                                                                                                    line.quantity,
                                                                                                                )}{' '}
                                                                                                                {line.unit ??
                                                                                                                    ''}
                                                                                                            </td>
                                                                                                        </tr>
                                                                                                    ),
                                                                                                )}
                                                                                            </tbody>
                                                                                        </table>
                                                                                    </div>
                                                                                )}
                                                                            </div>
                                                                        );
                                                                    },
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}
                                            </React.Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>

                            {deliveries.data.length === 0 && (
                                <div className="border-b p-12 text-center">
                                    <Truck className="mx-auto mb-3 size-8 text-muted-foreground/50" />
                                    <p className="font-medium text-muted-foreground">
                                        No {activeTab} truck shipments
                                    </p>
                                </div>
                            )}
                        </div>

                        <div className="bg-muted/10 p-4">
                            <PaginationNav
                                currentPage={deliveries.current_page}
                                lastPage={deliveries.last_page}
                                previousUrl={deliveries.prev_page_url}
                                nextUrl={deliveries.next_page_url}
                                label="Truck shipment pages"
                            />
                        </div>
                    </CardContent>
                </Card>
            </PageShell>

            {/* Create New Truck Shipment Modal */}
            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-2xl">
                    <DialogHeader className="gap-1 border-b px-4 pt-3 pb-2">
                        <DialogTitle className="flex items-center gap-2 font-semibold text-base">
                            <Truck className="size-4 text-primary" /> Create customer deliveries
                            (Truck shipment)
                        </DialogTitle>
                        <DialogDescription className="text-xs leading-tight">
                            Add single or multiple customer deliveries to group into a truck
                            shipment run.
                        </DialogDescription>
                    </DialogHeader>
                    <form className="flex flex-col overflow-hidden">
                        <div className="space-y-3 overflow-y-auto px-4 py-3">
                            {form.data.deliveries.map((del, delIndex) => {
                                const isExpanded = expandedDeliveryIndex === delIndex;
                                const hasMissingItems = del.lines.some(
                                    (l) =>
                                        !l.warehouse_item_id ||
                                        !l.quantity ||
                                        Number(l.quantity) <= 0,
                                );
                                const hasError =
                                    Object.keys(errors).some((key) =>
                                        key.startsWith(`deliveries.${delIndex}.`),
                                    ) ||
                                    (wasSubmitted &&
                                        (hasMissingItems ||
                                            !del.customer_name.trim() ||
                                            !del.sales_order.trim() ||
                                            !del.po.trim()));

                                return (
                                    <div
                                        key={del.client_id}
                                        className={cn(
                                            'overflow-hidden rounded-lg border bg-card shadow-2xs transition-colors',
                                            hasError
                                                ? 'border-destructive/70 bg-destructive/5'
                                                : 'border-border',
                                        )}
                                    >
                                        {/* Card Header (clickable accordion) */}
                                        {/* biome-ignore lint/a11y/useSemanticElements: interactive accordion header */}
                                        <div
                                            role="button"
                                            tabIndex={0}
                                            className={cn(
                                                'flex cursor-pointer select-none items-center justify-between p-3 transition-colors',
                                                hasError
                                                    ? 'bg-destructive/10 hover:bg-destructive/15'
                                                    : 'hover:bg-muted/20',
                                            )}
                                            onClick={() =>
                                                setExpandedDeliveryIndex((prev) =>
                                                    prev === delIndex ? -1 : delIndex,
                                                )
                                            }
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault();
                                                    setExpandedDeliveryIndex((prev) =>
                                                        prev === delIndex ? -1 : delIndex,
                                                    );
                                                }
                                            }}
                                        >
                                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="pointer-events-none size-5 shrink-0 p-0 text-muted-foreground"
                                                >
                                                    {isExpanded ? (
                                                        <ChevronDown className="size-4" />
                                                    ) : (
                                                        <ChevronRight className="size-4" />
                                                    )}
                                                </Button>
                                                <Badge
                                                    variant="outline"
                                                    className="font-mono text-xs"
                                                >
                                                    Customer Delivery #{delIndex + 1}
                                                </Badge>
                                                <span className="font-semibold text-foreground">
                                                    {del.customer_name.trim()
                                                        ? del.customer_name
                                                        : 'New Customer'}
                                                </span>
                                                {(del.sales_order.trim() || del.po.trim()) && (
                                                    <span className="text-[11px] text-muted-foreground">
                                                        (
                                                        {[
                                                            del.sales_order.trim() &&
                                                                `SO: ${del.sales_order}`,
                                                            del.po.trim() && `PO: ${del.po}`,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' • ')}
                                                        )
                                                    </span>
                                                )}
                                                {hasError && (
                                                    <Badge
                                                        variant="destructive"
                                                        className="h-4 gap-1 px-1.5 text-[10px]"
                                                    >
                                                        <AlertCircle className="size-2.5" />
                                                        {hasMissingItems
                                                            ? 'Select item required'
                                                            : 'Missing details'}
                                                    </Badge>
                                                )}
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-[10px] text-muted-foreground">
                                                    {del.lines.length} item(s)
                                                </span>
                                                {form.data.deliveries.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-6 px-2 text-destructive text-xs hover:text-destructive"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            removeDeliveryEntry(delIndex);
                                                        }}
                                                    >
                                                        <Trash2 className="mr-1 size-3" /> Remove
                                                    </Button>
                                                )}
                                            </div>
                                        </div>

                                        {/* Card Content Details (Visible 1 at a time) */}
                                        {isExpanded && (
                                            <div className="space-y-3 border-t bg-background/50 p-3 pt-2">
                                                <div className="grid gap-2 sm:grid-cols-3">
                                                    <WarehouseField
                                                        label="Customer name *"
                                                        error={
                                                            errors[
                                                                `deliveries.${delIndex}.customer_name`
                                                            ]
                                                        }
                                                    >
                                                        <Input
                                                            value={del.customer_name}
                                                            onChange={(event) =>
                                                                updateDeliveryField(
                                                                    delIndex,
                                                                    'customer_name',
                                                                    event.target.value,
                                                                )
                                                            }
                                                            required
                                                            className={cn(
                                                                'h-7 text-xs',
                                                                wasSubmitted &&
                                                                    !del.customer_name.trim() &&
                                                                    'border-destructive bg-destructive/10 text-destructive',
                                                            )}
                                                            placeholder="Required customer name"
                                                        />
                                                    </WarehouseField>
                                                    <WarehouseField
                                                        label="Sales order *"
                                                        error={
                                                            errors[
                                                                `deliveries.${delIndex}.sales_order`
                                                            ]
                                                        }
                                                    >
                                                        <Input
                                                            value={del.sales_order}
                                                            onChange={(event) =>
                                                                updateDeliveryField(
                                                                    delIndex,
                                                                    'sales_order',
                                                                    event.target.value,
                                                                )
                                                            }
                                                            required
                                                            className={cn(
                                                                'h-7 text-xs',
                                                                wasSubmitted &&
                                                                    !del.sales_order.trim() &&
                                                                    'border-destructive bg-destructive/10 text-destructive',
                                                            )}
                                                            placeholder="Required SO #"
                                                        />
                                                    </WarehouseField>
                                                    <WarehouseField
                                                        label="PO (from customer) *"
                                                        error={errors[`deliveries.${delIndex}.po`]}
                                                    >
                                                        <Input
                                                            value={del.po}
                                                            onChange={(event) =>
                                                                updateDeliveryField(
                                                                    delIndex,
                                                                    'po',
                                                                    event.target.value,
                                                                )
                                                            }
                                                            required
                                                            className={cn(
                                                                'h-7 text-xs',
                                                                wasSubmitted &&
                                                                    !del.po.trim() &&
                                                                    'border-destructive bg-destructive/10 text-destructive',
                                                            )}
                                                            placeholder="Required PO #"
                                                        />
                                                    </WarehouseField>
                                                </div>

                                                <div>
                                                    <div className="mb-1 flex items-center justify-between gap-2">
                                                        <h4 className="font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                                            Items for Delivery #{delIndex + 1}
                                                        </h4>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="h-6 px-2 text-[10px]"
                                                            onClick={() => addLine(delIndex)}
                                                            disabled={del.lines.length >= 50}
                                                        >
                                                            <Plus className="mr-1 size-3" /> Add
                                                            item
                                                        </Button>
                                                    </div>
                                                    <div className="space-y-2">
                                                        {del.lines.map((line, lineIndex) => {
                                                            const isItemMissing =
                                                                !line.warehouse_item_id &&
                                                                (wasSubmitted ||
                                                                    Boolean(
                                                                        errors[
                                                                            `deliveries.${delIndex}.lines.${lineIndex}.warehouse_item_id`
                                                                        ],
                                                                    ));
                                                            const isQtyMissing =
                                                                (!line.quantity ||
                                                                    Number(line.quantity) <= 0) &&
                                                                (wasSubmitted ||
                                                                    Boolean(
                                                                        errors[
                                                                            `deliveries.${delIndex}.lines.${lineIndex}.quantity`
                                                                        ],
                                                                    ));

                                                            return (
                                                                <div
                                                                    key={line.client_id}
                                                                    className="grid gap-2 sm:grid-cols-[1fr_7rem_auto] sm:items-start"
                                                                >
                                                                    <div className="min-w-0">
                                                                        <WarehouseField
                                                                            label={
                                                                                lineIndex === 0
                                                                                    ? 'Item *'
                                                                                    : ''
                                                                            }
                                                                            error={
                                                                                errors[
                                                                                    `deliveries.${delIndex}.lines.${lineIndex}.warehouse_item_id`
                                                                                ]
                                                                            }
                                                                        >
                                                                            <Popover>
                                                                                <PopoverTrigger
                                                                                    asChild
                                                                                >
                                                                                    <Button
                                                                                        variant="outline"
                                                                                        role="combobox"
                                                                                        className={cn(
                                                                                            'h-7 w-full justify-between font-normal text-xs transition-colors',
                                                                                            !line.warehouse_item_id &&
                                                                                                'text-muted-foreground',
                                                                                            isItemMissing &&
                                                                                                'border-destructive bg-destructive/10 font-medium text-destructive focus:ring-destructive dark:bg-destructive/20',
                                                                                        )}
                                                                                    >
                                                                                        <span className="truncate text-left">
                                                                                            {line.warehouse_item_id ? (
                                                                                                (() => {
                                                                                                    const item =
                                                                                                        deliveryItems.find(
                                                                                                            (
                                                                                                                i,
                                                                                                            ) =>
                                                                                                                String(
                                                                                                                    i.id,
                                                                                                                ) ===
                                                                                                                line.warehouse_item_id,
                                                                                                        );
                                                                                                    if (
                                                                                                        !item
                                                                                                    )
                                                                                                        return 'Select stock';
                                                                                                    return `${item.description} - ${quantity(item.available_quantity)}`;
                                                                                                })()
                                                                                            ) : isItemMissing ? (
                                                                                                <span className="flex items-center gap-1 font-semibold text-destructive">
                                                                                                    <AlertCircle className="size-3 shrink-0" />
                                                                                                    Select
                                                                                                    item
                                                                                                    required
                                                                                                </span>
                                                                                            ) : (
                                                                                                'Select stock'
                                                                                            )}
                                                                                        </span>
                                                                                        <ChevronsUpDown className="ml-2 h-3 w-3 shrink-0 opacity-50" />
                                                                                    </Button>
                                                                                </PopoverTrigger>
                                                                                <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0">
                                                                                    <Command>
                                                                                        <CommandInput
                                                                                            placeholder="Search items..."
                                                                                            className="h-7 text-xs"
                                                                                        />
                                                                                        <CommandList>
                                                                                            <CommandEmpty className="py-2 text-center text-xs">
                                                                                                No
                                                                                                item
                                                                                                found.
                                                                                            </CommandEmpty>
                                                                                            <CommandGroup>
                                                                                                {deliveryItems.map(
                                                                                                    (
                                                                                                        item,
                                                                                                    ) => {
                                                                                                        const disabled =
                                                                                                            del.lines.some(
                                                                                                                (
                                                                                                                    candidate,
                                                                                                                    candidateIndex,
                                                                                                                ) =>
                                                                                                                    candidateIndex !==
                                                                                                                        lineIndex &&
                                                                                                                    candidate.warehouse_item_id ===
                                                                                                                        String(
                                                                                                                            item.id,
                                                                                                                        ),
                                                                                                            );
                                                                                                        return (
                                                                                                            <CommandItem
                                                                                                                key={
                                                                                                                    item.id
                                                                                                                }
                                                                                                                value={
                                                                                                                    item.description
                                                                                                                }
                                                                                                                disabled={
                                                                                                                    disabled
                                                                                                                }
                                                                                                                className="py-1 text-xs"
                                                                                                                onSelect={() => {
                                                                                                                    updateLine(
                                                                                                                        delIndex,
                                                                                                                        lineIndex,
                                                                                                                        'warehouse_item_id',
                                                                                                                        String(
                                                                                                                            item.id,
                                                                                                                        ),
                                                                                                                    );
                                                                                                                }}
                                                                                                            >
                                                                                                                <Check
                                                                                                                    className={cn(
                                                                                                                        'mr-2 h-3 w-3',
                                                                                                                        line.warehouse_item_id ===
                                                                                                                            String(
                                                                                                                                item.id,
                                                                                                                            )
                                                                                                                            ? 'opacity-100'
                                                                                                                            : 'opacity-0',
                                                                                                                    )}
                                                                                                                />
                                                                                                                <div className="flex flex-col">
                                                                                                                    <span>
                                                                                                                        {
                                                                                                                            item.description
                                                                                                                        }
                                                                                                                    </span>
                                                                                                                    <span className="text-[10px] text-muted-foreground">
                                                                                                                        {item.sku_number ??
                                                                                                                            'No SKU'}{' '}
                                                                                                                        •{' '}
                                                                                                                        {quantity(
                                                                                                                            item.available_quantity,
                                                                                                                        )}{' '}
                                                                                                                        {item.unit ??
                                                                                                                            ''}{' '}
                                                                                                                        available
                                                                                                                    </span>
                                                                                                                </div>
                                                                                                            </CommandItem>
                                                                                                        );
                                                                                                    },
                                                                                                )}
                                                                                            </CommandGroup>
                                                                                        </CommandList>
                                                                                    </Command>
                                                                                </PopoverContent>
                                                                            </Popover>
                                                                            {isItemMissing && (
                                                                                <p className="mt-1 flex items-center gap-1 font-medium text-[10px] text-destructive">
                                                                                    <AlertCircle className="size-3 shrink-0" />
                                                                                    Please select a
                                                                                    warehouse item
                                                                                </p>
                                                                            )}
                                                                        </WarehouseField>
                                                                    </div>
                                                                    <WarehouseField
                                                                        label={
                                                                            lineIndex === 0
                                                                                ? 'Quantity *'
                                                                                : ''
                                                                        }
                                                                        error={
                                                                            errors[
                                                                                `deliveries.${delIndex}.lines.${lineIndex}.quantity`
                                                                            ]
                                                                        }
                                                                    >
                                                                        <Input
                                                                            type="number"
                                                                            min="0.1"
                                                                            step="0.1"
                                                                            value={line.quantity}
                                                                            onChange={(event) =>
                                                                                updateLine(
                                                                                    delIndex,
                                                                                    lineIndex,
                                                                                    'quantity',
                                                                                    event.target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            required
                                                                            className={cn(
                                                                                'h-7 text-xs',
                                                                                isQtyMissing &&
                                                                                    'border-destructive bg-destructive/10 text-destructive',
                                                                            )}
                                                                            placeholder="Qty *"
                                                                        />
                                                                        {isQtyMissing && (
                                                                            <p className="mt-1 font-medium text-[10px] text-destructive">
                                                                                Required qty
                                                                            </p>
                                                                        )}
                                                                    </WarehouseField>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className={cn(
                                                                            'h-7 w-7 text-muted-foreground hover:text-destructive',
                                                                            lineIndex === 0
                                                                                ? 'mt-[20px]'
                                                                                : '',
                                                                        )}
                                                                        aria-label={`Remove item ${lineIndex + 1}`}
                                                                        disabled={
                                                                            del.lines.length === 1
                                                                        }
                                                                        onClick={() =>
                                                                            removeLine(
                                                                                delIndex,
                                                                                lineIndex,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Trash2 className="size-3.5" />
                                                                    </Button>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>

                                                <WarehouseField
                                                    label="Notes"
                                                    error={errors[`deliveries.${delIndex}.notes`]}
                                                >
                                                    <Input
                                                        value={del.notes}
                                                        onChange={(event) =>
                                                            updateDeliveryField(
                                                                delIndex,
                                                                'notes',
                                                                event.target.value,
                                                            )
                                                        }
                                                        className="h-7 text-xs"
                                                        placeholder="Enter any additional notes..."
                                                    />
                                                </WarehouseField>
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        <DialogFooter className="flex flex-col-reverse gap-2.5 border-t bg-muted/30 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap items-center gap-2">
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={addDeliveryEntry}
                                            className="h-7 gap-1.5 text-xs"
                                        >
                                            <Plus className="size-3.5" /> Add customer delivery to
                                            truck
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Add another customer delivery card to group into this truck
                                        shipment run.
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            <div className="flex items-center justify-end gap-2">
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-7 px-3 text-xs"
                                            onClick={() => setCreateOpen(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Close without saving changes.</TooltipContent>
                                </Tooltip>

                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            className="h-7 gap-1.5 px-3 font-medium text-xs"
                                            disabled={form.processing}
                                            onClick={(e) => handleCreateSubmit(false, e)}
                                        >
                                            <FileText className="size-3.5" />
                                            Save draft ({form.data.deliveries.length})
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Creates deliveries as drafts without allocating stock. Will
                                        NOT be marked as dispatched, and no dispatch date will be
                                        recorded yet.
                                    </TooltipContent>
                                </Tooltip>

                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="default"
                                            size="sm"
                                            className="h-7 gap-1.5 px-3 font-medium text-xs"
                                            disabled={form.processing}
                                            onClick={(e) => handleCreateSubmit(true, e)}
                                        >
                                            <Send className="size-3.5" />
                                            Dispatch truck ({form.data.deliveries.length})
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Creates deliveries and allocates stock via FIFO. Will be
                                        marked as DISPATCHED and the exact dispatch timestamp will
                                        be recorded automatically.
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit Truck Shipment Dialog */}
            <EditShipmentDialog
                shipment={editingShipment}
                deliveryItems={deliveryItems}
                onClose={() => setEditingShipment(null)}
            />

            {/* Dispatch Confirmation Dialog */}
            <Dialog
                open={dispatchingShipment !== null}
                onOpenChange={(open) => !open && setDispatchingShipment(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Dispatch truck shipment?</DialogTitle>
                        <DialogDescription>
                            Dispatch truck shipment{' '}
                            <span className="font-mono font-semibold text-foreground">
                                {dispatchingShipment?.shipment_reference}
                            </span>{' '}
                            containing{' '}
                            <span className="font-medium text-foreground">
                                {dispatchingShipment?.customer_count} customer delivery(ies)
                            </span>
                            . FIFO stock allocation will run automatically.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDispatchingShipment(null)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={() => {
                                if (!dispatchingShipment) return;
                                router.post(
                                    `/warehouse/shipments/${dispatchingShipment.shipment_reference}/dispatch`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setDispatchingShipment(null),
                                    },
                                );
                            }}
                        >
                            <Send className="mr-2 size-4" /> Dispatch truck
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Shipment Dialog */}
            <Dialog
                open={removingShipment !== null}
                onOpenChange={(next) => !next && setRemovingShipment(null)}
            >
                <DialogContent hideCloseButton>
                    <DialogHeader>
                        <DialogTitle>Delete draft truck shipment?</DialogTitle>
                        <DialogDescription>
                            This will permanently delete truck shipment{' '}
                            <span className="font-mono font-semibold text-foreground">
                                {removingShipment?.shipment_reference}
                            </span>{' '}
                            and all{' '}
                            <span className="font-medium text-foreground">
                                {removingShipment?.customer_count} customer delivery(ies)
                            </span>{' '}
                            in this run.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRemovingShipment(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!removingShipment) return;
                                router.delete(
                                    `/warehouse/shipments/${removingShipment.shipment_reference}`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setRemovingShipment(null),
                                    },
                                );
                            }}
                        >
                            <Trash2 className="mr-2 size-4" /> Delete truck shipment
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </TooltipProvider>
    );
}

function EditShipmentDialog({
    shipment,
    deliveryItems,
    onClose,
}: {
    shipment: TruckShipment | null;
    deliveryItems: DeliveryItemOption[];
    onClose: () => void;
}) {
    const form = useForm({
        deliveries: [] as DeliveryEntryInput[],
    });
    const [expandedDeliveryIndex, setExpandedDeliveryIndex] = useState<number>(0);
    const [wasSubmitted, setWasSubmitted] = useState(false);

    useEffect(() => {
        if (shipment) {
            form.setData({
                deliveries: shipment.deliveries.map((del) => ({
                    client_id: crypto.randomUUID(),
                    id: del.id,
                    customer_name: del.customer_name ?? '',
                    sales_order: del.sales_order ?? '',
                    po: del.po ?? '',
                    notes: del.notes ?? '',
                    lines: del.lines.map((l) => ({
                        client_id: crypto.randomUUID(),
                        warehouse_item_id: String(l.warehouse_item_id ?? ''),
                        quantity: String(l.quantity),
                    })),
                })),
            });
            setExpandedDeliveryIndex(shipment.deliveries.length > 1 ? -1 : 0);
            setWasSubmitted(false);
        }
    }, [shipment, form.setData]);

    function submit(event: FormEvent) {
        event.preventDefault();
        setWasSubmitted(true);
        if (!shipment) return;

        for (let i = 0; i < form.data.deliveries.length; i++) {
            const del = form.data.deliveries[i];
            if (!del.customer_name.trim() || !del.sales_order.trim() || !del.po.trim()) {
                setExpandedDeliveryIndex(i);
                return;
            }
            for (const line of del.lines) {
                if (!line.warehouse_item_id || !line.quantity || Number(line.quantity) <= 0) {
                    setExpandedDeliveryIndex(i);
                    return;
                }
            }
        }

        form.put(`/warehouse/shipments/${shipment.shipment_reference}`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    function addDeliveryEntry() {
        const nextIndex = form.data.deliveries.length;
        form.setData('deliveries', [
            ...form.data.deliveries,
            {
                client_id: crypto.randomUUID(),
                customer_name: '',
                sales_order: '',
                po: '',
                notes: '',
                lines: [{ client_id: crypto.randomUUID(), warehouse_item_id: '', quantity: '' }],
            },
        ]);
        setExpandedDeliveryIndex(nextIndex);
    }

    function removeDeliveryEntry(delIndex: number) {
        if (form.data.deliveries.length <= 1) return;
        form.setData(
            'deliveries',
            form.data.deliveries.filter((_, i) => i !== delIndex),
        );
        setExpandedDeliveryIndex((prev) => (prev >= delIndex ? Math.max(0, prev - 1) : prev));
    }

    function updateDeliveryField(
        delIndex: number,
        field: keyof Omit<DeliveryEntryInput, 'client_id' | 'lines'>,
        value: string,
    ) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex ? { ...del, [field]: value } : del,
            ),
        );
    }

    function addLine(delIndex: number) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex
                    ? {
                          ...del,
                          lines: [
                              ...del.lines,
                              {
                                  client_id: crypto.randomUUID(),
                                  warehouse_item_id: '',
                                  quantity: '',
                              },
                          ],
                      }
                    : del,
            ),
        );
    }

    function removeLine(delIndex: number, lineIndex: number) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex
                    ? {
                          ...del,
                          lines: del.lines.filter((_, li) => li !== lineIndex),
                      }
                    : del,
            ),
        );
    }

    function updateLine(
        delIndex: number,
        lineIndex: number,
        field: 'warehouse_item_id' | 'quantity',
        value: string,
    ) {
        form.setData(
            'deliveries',
            form.data.deliveries.map((del, i) =>
                i === delIndex
                    ? {
                          ...del,
                          lines: del.lines.map((line, li) =>
                              li === lineIndex ? { ...line, [field]: value } : line,
                          ),
                      }
                    : del,
            ),
        );
    }

    const errors = form.errors as Record<string, string>;

    return (
        <Dialog open={shipment !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-2xl">
                <DialogHeader className="gap-1 border-b px-4 pt-3 pb-2">
                    <DialogTitle className="flex items-center gap-2 font-semibold text-base">
                        <Pencil className="size-4 text-primary" /> Edit truck shipment (
                        {shipment?.shipment_reference})
                    </DialogTitle>
                    <DialogDescription className="text-xs leading-tight">
                        Update customer deliveries grouped in this truck shipment.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col overflow-hidden">
                    <div className="space-y-3 overflow-y-auto px-4 py-3">
                        {form.data.deliveries.map((del, delIndex) => {
                            const isExpanded = expandedDeliveryIndex === delIndex;
                            const hasMissingItems = del.lines.some(
                                (l) =>
                                    !l.warehouse_item_id || !l.quantity || Number(l.quantity) <= 0,
                            );
                            const hasError =
                                Object.keys(errors).some((key) =>
                                    key.startsWith(`deliveries.${delIndex}.`),
                                ) ||
                                (wasSubmitted &&
                                    (hasMissingItems ||
                                        !del.customer_name.trim() ||
                                        !del.sales_order.trim() ||
                                        !del.po.trim()));

                            return (
                                <div
                                    key={del.client_id}
                                    className={cn(
                                        'overflow-hidden rounded-lg border bg-card shadow-2xs transition-colors',
                                        hasError
                                            ? 'border-destructive/70 bg-destructive/5'
                                            : 'border-border',
                                    )}
                                >
                                    {/* biome-ignore lint/a11y/useSemanticElements: interactive accordion header */}
                                    <div
                                        role="button"
                                        tabIndex={0}
                                        className={cn(
                                            'flex cursor-pointer select-none items-center justify-between p-3 transition-colors',
                                            hasError
                                                ? 'bg-destructive/10 hover:bg-destructive/15'
                                                : 'hover:bg-muted/20',
                                        )}
                                        onClick={() =>
                                            setExpandedDeliveryIndex((prev) =>
                                                prev === delIndex ? -1 : delIndex,
                                            )
                                        }
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                e.preventDefault();
                                                setExpandedDeliveryIndex((prev) =>
                                                    prev === delIndex ? -1 : delIndex,
                                                );
                                            }
                                        }}
                                    >
                                        <div className="flex flex-wrap items-center gap-2 text-xs">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="pointer-events-none size-5 shrink-0 p-0 text-muted-foreground"
                                            >
                                                {isExpanded ? (
                                                    <ChevronDown className="size-4" />
                                                ) : (
                                                    <ChevronRight className="size-4" />
                                                )}
                                            </Button>
                                            <Badge variant="outline" className="font-mono text-xs">
                                                Customer Delivery #{delIndex + 1}
                                            </Badge>
                                            <span className="font-semibold text-foreground">
                                                {del.customer_name.trim()
                                                    ? del.customer_name
                                                    : 'New Customer'}
                                            </span>
                                            {(del.sales_order.trim() || del.po.trim()) && (
                                                <span className="text-[11px] text-muted-foreground">
                                                    (
                                                    {[
                                                        del.sales_order.trim() &&
                                                            `SO: ${del.sales_order}`,
                                                        del.po.trim() && `PO: ${del.po}`,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' • ')}
                                                    )
                                                </span>
                                            )}
                                            {hasError && (
                                                <Badge
                                                    variant="destructive"
                                                    className="h-4 gap-1 px-1.5 text-[10px]"
                                                >
                                                    <AlertCircle className="size-2.5" />
                                                    {hasMissingItems
                                                        ? 'Select item required'
                                                        : 'Missing details'}
                                                </Badge>
                                            )}
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <span className="font-mono text-[10px] text-muted-foreground">
                                                {del.lines.length} item(s)
                                            </span>
                                            {form.data.deliveries.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-6 px-2 text-destructive text-xs hover:text-destructive"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        removeDeliveryEntry(delIndex);
                                                    }}
                                                >
                                                    <Trash2 className="mr-1 size-3" /> Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>

                                    {isExpanded && (
                                        <div className="space-y-3 border-t bg-background/50 p-3 pt-2">
                                            <div className="grid gap-2 sm:grid-cols-3">
                                                <WarehouseField
                                                    label="Customer name *"
                                                    error={
                                                        errors[
                                                            `deliveries.${delIndex}.customer_name`
                                                        ]
                                                    }
                                                >
                                                    <Input
                                                        value={del.customer_name}
                                                        onChange={(event) =>
                                                            updateDeliveryField(
                                                                delIndex,
                                                                'customer_name',
                                                                event.target.value,
                                                            )
                                                        }
                                                        required
                                                        className={cn(
                                                            'h-7 text-xs',
                                                            wasSubmitted &&
                                                                !del.customer_name.trim() &&
                                                                'border-destructive bg-destructive/10 text-destructive',
                                                        )}
                                                        placeholder="Required customer name"
                                                    />
                                                </WarehouseField>
                                                <WarehouseField
                                                    label="Sales order *"
                                                    error={
                                                        errors[`deliveries.${delIndex}.sales_order`]
                                                    }
                                                >
                                                    <Input
                                                        value={del.sales_order}
                                                        onChange={(event) =>
                                                            updateDeliveryField(
                                                                delIndex,
                                                                'sales_order',
                                                                event.target.value,
                                                            )
                                                        }
                                                        required
                                                        className={cn(
                                                            'h-7 text-xs',
                                                            wasSubmitted &&
                                                                !del.sales_order.trim() &&
                                                                'border-destructive bg-destructive/10 text-destructive',
                                                        )}
                                                        placeholder="Required SO #"
                                                    />
                                                </WarehouseField>
                                                <WarehouseField
                                                    label="PO (from customer) *"
                                                    error={errors[`deliveries.${delIndex}.po`]}
                                                >
                                                    <Input
                                                        value={del.po}
                                                        onChange={(event) =>
                                                            updateDeliveryField(
                                                                delIndex,
                                                                'po',
                                                                event.target.value,
                                                            )
                                                        }
                                                        required
                                                        className={cn(
                                                            'h-7 text-xs',
                                                            wasSubmitted &&
                                                                !del.po.trim() &&
                                                                'border-destructive bg-destructive/10 text-destructive',
                                                        )}
                                                        placeholder="Required PO #"
                                                    />
                                                </WarehouseField>
                                            </div>

                                            <div>
                                                <div className="mb-1 flex items-center justify-between gap-2">
                                                    <h4 className="font-semibold text-[10px] text-muted-foreground uppercase tracking-widest">
                                                        Items for Delivery #{delIndex + 1}
                                                    </h4>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-6 px-2 text-[10px]"
                                                        onClick={() => addLine(delIndex)}
                                                        disabled={del.lines.length >= 50}
                                                    >
                                                        <Plus className="mr-1 size-3" /> Add item
                                                    </Button>
                                                </div>
                                                <div className="space-y-2">
                                                    {del.lines.map((line, lineIndex) => {
                                                        const isItemMissing =
                                                            !line.warehouse_item_id &&
                                                            (wasSubmitted ||
                                                                Boolean(
                                                                    errors[
                                                                        `deliveries.${delIndex}.lines.${lineIndex}.warehouse_item_id`
                                                                    ],
                                                                ));
                                                        const isQtyMissing =
                                                            (!line.quantity ||
                                                                Number(line.quantity) <= 0) &&
                                                            (wasSubmitted ||
                                                                Boolean(
                                                                    errors[
                                                                        `deliveries.${delIndex}.lines.${lineIndex}.quantity`
                                                                    ],
                                                                ));

                                                        return (
                                                            <div
                                                                key={line.client_id}
                                                                className="grid gap-2 sm:grid-cols-[1fr_7rem_auto] sm:items-start"
                                                            >
                                                                <div className="min-w-0">
                                                                    <WarehouseField
                                                                        label={
                                                                            lineIndex === 0
                                                                                ? 'Item *'
                                                                                : ''
                                                                        }
                                                                        error={
                                                                            errors[
                                                                                `deliveries.${delIndex}.lines.${lineIndex}.warehouse_item_id`
                                                                            ]
                                                                        }
                                                                    >
                                                                        <Popover>
                                                                            <PopoverTrigger asChild>
                                                                                <Button
                                                                                    variant="outline"
                                                                                    role="combobox"
                                                                                    className={cn(
                                                                                        'h-7 w-full justify-between font-normal text-xs transition-colors',
                                                                                        !line.warehouse_item_id &&
                                                                                            'text-muted-foreground',
                                                                                        isItemMissing &&
                                                                                            'border-destructive bg-destructive/10 font-medium text-destructive focus:ring-destructive dark:bg-destructive/20',
                                                                                    )}
                                                                                >
                                                                                    <span className="truncate text-left">
                                                                                        {line.warehouse_item_id ? (
                                                                                            (() => {
                                                                                                const item =
                                                                                                    deliveryItems.find(
                                                                                                        (
                                                                                                            i,
                                                                                                        ) =>
                                                                                                            String(
                                                                                                                i.id,
                                                                                                            ) ===
                                                                                                            line.warehouse_item_id,
                                                                                                    );
                                                                                                if (
                                                                                                    !item
                                                                                                )
                                                                                                    return 'Select stock';
                                                                                                return `${item.description} - ${quantity(item.available_quantity)}`;
                                                                                            })()
                                                                                        ) : isItemMissing ? (
                                                                                            <span className="flex items-center gap-1 font-semibold text-destructive">
                                                                                                <AlertCircle className="size-3 shrink-0" />
                                                                                                Select
                                                                                                item
                                                                                                required
                                                                                            </span>
                                                                                        ) : (
                                                                                            'Select stock'
                                                                                        )}
                                                                                    </span>
                                                                                    <ChevronsUpDown className="ml-2 h-3 w-3 shrink-0 opacity-50" />
                                                                                </Button>
                                                                            </PopoverTrigger>
                                                                            <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0">
                                                                                <Command>
                                                                                    <CommandInput
                                                                                        placeholder="Search items..."
                                                                                        className="h-7 text-xs"
                                                                                    />
                                                                                    <CommandList>
                                                                                        <CommandEmpty className="py-2 text-center text-xs">
                                                                                            No item
                                                                                            found.
                                                                                        </CommandEmpty>
                                                                                        <CommandGroup>
                                                                                            {deliveryItems.map(
                                                                                                (
                                                                                                    item,
                                                                                                ) => {
                                                                                                    const disabled =
                                                                                                        del.lines.some(
                                                                                                            (
                                                                                                                candidate,
                                                                                                                candidateIndex,
                                                                                                            ) =>
                                                                                                                candidateIndex !==
                                                                                                                    lineIndex &&
                                                                                                                candidate.warehouse_item_id ===
                                                                                                                    String(
                                                                                                                        item.id,
                                                                                                                    ),
                                                                                                        );
                                                                                                    return (
                                                                                                        <CommandItem
                                                                                                            key={
                                                                                                                item.id
                                                                                                            }
                                                                                                            value={
                                                                                                                item.description
                                                                                                            }
                                                                                                            disabled={
                                                                                                                disabled
                                                                                                            }
                                                                                                            className="py-1 text-xs"
                                                                                                            onSelect={() => {
                                                                                                                updateLine(
                                                                                                                    delIndex,
                                                                                                                    lineIndex,
                                                                                                                    'warehouse_item_id',
                                                                                                                    String(
                                                                                                                        item.id,
                                                                                                                    ),
                                                                                                                );
                                                                                                            }}
                                                                                                        >
                                                                                                            <Check
                                                                                                                className={cn(
                                                                                                                    'mr-2 h-3 w-3',
                                                                                                                    line.warehouse_item_id ===
                                                                                                                        String(
                                                                                                                            item.id,
                                                                                                                        )
                                                                                                                        ? 'opacity-100'
                                                                                                                        : 'opacity-0',
                                                                                                                )}
                                                                                                            />
                                                                                                            <div className="flex flex-col">
                                                                                                                <span>
                                                                                                                    {
                                                                                                                        item.description
                                                                                                                    }
                                                                                                                </span>
                                                                                                                <span className="text-[10px] text-muted-foreground">
                                                                                                                    {item.sku_number ??
                                                                                                                        'No SKU'}{' '}
                                                                                                                    •{' '}
                                                                                                                    {quantity(
                                                                                                                        item.available_quantity,
                                                                                                                    )}{' '}
                                                                                                                    {item.unit ??
                                                                                                                        ''}{' '}
                                                                                                                    available
                                                                                                                </span>
                                                                                                            </div>
                                                                                                        </CommandItem>
                                                                                                    );
                                                                                                },
                                                                                            )}
                                                                                        </CommandGroup>
                                                                                    </CommandList>
                                                                                </Command>
                                                                            </PopoverContent>
                                                                        </Popover>
                                                                        {isItemMissing && (
                                                                            <p className="mt-1 flex items-center gap-1 font-medium text-[10px] text-destructive">
                                                                                <AlertCircle className="size-3 shrink-0" />
                                                                                Please select a
                                                                                warehouse item
                                                                            </p>
                                                                        )}
                                                                    </WarehouseField>
                                                                </div>
                                                                <WarehouseField
                                                                    label={
                                                                        lineIndex === 0
                                                                            ? 'Quantity *'
                                                                            : ''
                                                                    }
                                                                    error={
                                                                        errors[
                                                                            `deliveries.${delIndex}.lines.${lineIndex}.quantity`
                                                                        ]
                                                                    }
                                                                >
                                                                    <Input
                                                                        type="number"
                                                                        min="0.1"
                                                                        step="0.1"
                                                                        value={line.quantity}
                                                                        onChange={(event) =>
                                                                            updateLine(
                                                                                delIndex,
                                                                                lineIndex,
                                                                                'quantity',
                                                                                event.target.value,
                                                                            )
                                                                        }
                                                                        required
                                                                        className={cn(
                                                                            'h-7 text-xs',
                                                                            isQtyMissing &&
                                                                                'border-destructive bg-destructive/10 text-destructive',
                                                                        )}
                                                                        placeholder="Qty *"
                                                                    />
                                                                    {isQtyMissing && (
                                                                        <p className="mt-1 font-medium text-[10px] text-destructive">
                                                                            Required qty
                                                                        </p>
                                                                    )}
                                                                </WarehouseField>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className={cn(
                                                                        'h-7 w-7 text-muted-foreground hover:text-destructive',
                                                                        lineIndex === 0
                                                                            ? 'mt-[20px]'
                                                                            : '',
                                                                    )}
                                                                    aria-label={`Remove item ${lineIndex + 1}`}
                                                                    disabled={
                                                                        del.lines.length === 1
                                                                    }
                                                                    onClick={() =>
                                                                        removeLine(
                                                                            delIndex,
                                                                            lineIndex,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="size-3.5" />
                                                                </Button>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>

                                            <WarehouseField
                                                label="Notes"
                                                error={errors[`deliveries.${delIndex}.notes`]}
                                            >
                                                <Input
                                                    value={del.notes}
                                                    onChange={(event) =>
                                                        updateDeliveryField(
                                                            delIndex,
                                                            'notes',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="h-7 text-xs"
                                                    placeholder="Enter any additional notes..."
                                                />
                                            </WarehouseField>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    <DialogFooter className="flex flex-col-reverse gap-2.5 border-t bg-muted/30 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addDeliveryEntry}
                                className="h-7 gap-1.5 text-xs"
                            >
                                <Plus className="size-3.5" /> Add customer delivery to truck
                            </Button>
                        </div>
                        <div className="flex items-center justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-7 px-3 text-xs"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button
                                size="sm"
                                className="h-7 px-3 text-xs"
                                disabled={form.processing}
                            >
                                {form.processing ? 'Saving...' : 'Save changes'}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
