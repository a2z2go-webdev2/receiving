import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    Calendar,
    CheckCircle2,
    Clock,
    FileSpreadsheet,
    FileText,
    Layers,
    Package,
    Receipt,
    Truck,
    User as UserIcon,
} from 'lucide-react';
import { PageShell } from '@/components/receiving/page-shell';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type PurchaseOrderItem = {
    id: number;
    sort_order: number;
    item_code: string | null;
    product_description: string | null;
    package: string | null;
    quantity: string | null;
    unit: string | null;
    unit_price: string | null;
    line_total: string | null;
    is_financial_adjustment: boolean;
    is_catalog_matched: boolean;
    matched_schedule: {
        id: number;
        sku_number: string;
        description: string;
        unit: string;
    } | null;
};

type LinkedArrival = {
    id: number;
    item_code: string | null;
    product_description: string | null;
    arrived_quantity: number;
    unit: string | null;
    arrival_date: string | null;
    posting_status: string | null;
    stock_lot: {
        id: number;
        lot_number: string;
        quantity_received: number;
        posting_provenance: string | null;
    } | null;
};

type LinkedReceipt = {
    id: number;
    upload_id: number | null;
    serial_number: number;
    serial_prefix: string;
    upload_type: string;
    document_type: string | null;
    source: string;
    linked_at: string;
    arrivals: LinkedArrival[];
};

type PurchaseOrder = {
    id: number;
    po_number: string | null;
    po_reference: string | null;
    po_date: string | null;
    po_date_value: string | null;
    buyer_company: string | null;
    buyer_address: string | null;
    buyer_contact_numbers: string | null;
    vendor_name: string | null;
    vendor_raw: string | null;
    contact_person: string | null;
    vendor_email: string | null;
    vendor_mobile: string | null;
    vendor_address: string | null;
    payment_terms: string | null;
    subtotal: string | null;
    vat: string | null;
    total_amount: string | null;
    arrival_status: string;
    source_type: string;
    source_status: string;
    sync_key: string | null;
    snapshot_timestamp: string | null;
    notes: string | null;
    financial_adjustments: Array<{
        code?: string;
        name?: string;
        description?: string;
        amount?: string | number;
        unit_price?: string | number;
    }>;
    sheet_source: {
        id: number;
        name: string;
        slug: string;
        last_synced_at: string | null;
    } | null;
    upload: {
        id: number;
        serial_number: number;
        serial_prefix: string;
        created_at: string;
    } | null;
    items: PurchaseOrderItem[];
    linked_receipts: LinkedReceipt[];
    waiting_time: {
        days: number | null;
        arrived: boolean;
    };
};

export default function PurchaseOrderShow({ purchaseOrder }: { purchaseOrder: PurchaseOrder }) {
    const isSheet = purchaseOrder.source_type === 'google_sheet';

    return (
        <>
            <Head title={`Purchase Order ${purchaseOrder.po_number ?? `#${purchaseOrder.id}`}`} />
            <PageShell
                title={`Purchase Order: ${purchaseOrder.po_number ?? `#${purchaseOrder.id}`}`}
                description={
                    isSheet
                        ? `Synchronized from Google Sheet (${purchaseOrder.sheet_source?.name ?? 'Sheet'})`
                        : `Uploaded document ${purchaseOrder.upload ? `${purchaseOrder.upload.serial_prefix}-${purchaseOrder.upload.serial_number}` : ''}`
                }
                actions={
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href="/admin/purchase-orders" className="gap-1.5">
                                <ArrowLeft className="size-3.5" />
                                Back to POs
                            </Link>
                        </Button>
                        {purchaseOrder.upload && (
                            <Button asChild variant="secondary" size="sm">
                                <Link
                                    href={`/admin/uploads/${purchaseOrder.upload.id}`}
                                    className="gap-1.5"
                                >
                                    <FileText className="size-3.5" />
                                    View upload PDF
                                </Link>
                            </Button>
                        )}
                    </div>
                }
            >
                {/* Header status bar */}
                <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-xs font-medium">
                                Arrival status
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center justify-between">
                            <StatusBadge value={purchaseOrder.arrival_status} />
                            {purchaseOrder.waiting_time.days !== null && (
                                <span className="text-xs text-muted-foreground">
                                    {purchaseOrder.waiting_time.days}d waiting
                                </span>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-xs font-medium">
                                Source status
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center justify-between">
                            <StatusBadge value={purchaseOrder.source_status} />
                            <Badge variant="outline" className="text-[10px] font-normal uppercase">
                                {isSheet ? 'Google Sheet' : 'PDF Upload'}
                            </Badge>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-xs font-medium">
                                PO date
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center gap-2 text-sm font-semibold">
                            <Calendar className="size-4 text-muted-foreground" />
                            {purchaseOrder.po_date_value ?? purchaseOrder.po_date ?? 'Not recorded'}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-xs font-medium">
                                Total amount
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-base font-bold text-foreground">
                            {purchaseOrder.total_amount ? `₱${purchaseOrder.total_amount}` : '—'}
                        </CardContent>
                    </Card>
                </div>

                {/* Details grid */}
                <div className="mb-6 grid gap-6 md:grid-cols-2">
                    {/* Vendor details */}
                    <Card>
                        <CardHeader className="pb-3 border-b">
                            <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                <Building2 className="size-4 text-primary" />
                                Vendor / Supplier
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-3 text-xs space-y-2">
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">Name:</span>
                                <span className="font-medium text-foreground">
                                    {purchaseOrder.vendor_name ?? '—'}
                                </span>
                            </div>
                            {purchaseOrder.vendor_raw &&
                                purchaseOrder.vendor_raw !== purchaseOrder.vendor_name && (
                                    <div className="flex justify-between py-1 border-b border-muted">
                                        <span className="text-muted-foreground">Source raw:</span>
                                        <span className="font-mono text-[11px]">
                                            {purchaseOrder.vendor_raw}
                                        </span>
                                    </div>
                                )}
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">Contact person:</span>
                                <span>{purchaseOrder.contact_person ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">Email:</span>
                                <span>{purchaseOrder.vendor_email ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">Mobile:</span>
                                <span>{purchaseOrder.vendor_mobile ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1">
                                <span className="text-muted-foreground">Address:</span>
                                <span className="text-right max-w-[240px] truncate">
                                    {purchaseOrder.vendor_address ?? '—'}
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Order & Source Info */}
                    <Card>
                        <CardHeader className="pb-3 border-b">
                            <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                {isSheet ? (
                                    <FileSpreadsheet className="size-4 text-primary" />
                                ) : (
                                    <FileText className="size-4 text-primary" />
                                )}
                                Order & Provenance
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-3 text-xs space-y-2">
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">PO Number:</span>
                                <span className="font-mono font-medium text-foreground">
                                    {purchaseOrder.po_number ?? '—'}
                                </span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">PO Reference:</span>
                                <span>{purchaseOrder.po_reference ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">Payment terms:</span>
                                <span>{purchaseOrder.payment_terms ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-muted">
                                <span className="text-muted-foreground">Source channel:</span>
                                <span>
                                    {isSheet
                                        ? `Google Sheet (${purchaseOrder.sheet_source?.name ?? 'Sync'})`
                                        : 'Uploaded Document'}
                                </span>
                            </div>
                            {isSheet && purchaseOrder.snapshot_timestamp && (
                                <div className="flex justify-between py-1 border-b border-muted">
                                    <span className="text-muted-foreground">
                                        Last synchronized:
                                    </span>
                                    <span>
                                        {new Date(
                                            purchaseOrder.snapshot_timestamp,
                                        ).toLocaleString()}
                                    </span>
                                </div>
                            )}
                            {purchaseOrder.notes && (
                                <div className="py-1">
                                    <span className="text-muted-foreground block mb-0.5">
                                        Notes:
                                    </span>
                                    <p className="rounded bg-muted/50 p-2 text-foreground/80">
                                        {purchaseOrder.notes}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Ordered Items Table */}
                <div className="mb-8">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-sm font-semibold flex items-center gap-2">
                            <Package className="size-4 text-primary" />
                            Ordered Items ({purchaseOrder.items.length})
                        </h2>
                    </div>
                    <div className="overflow-x-auto rounded-lg border bg-card">
                        <table className="w-full whitespace-nowrap text-left text-xs">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-3 py-2 w-12 text-center">#</th>
                                    <th className="px-3 py-2">Item Code</th>
                                    <th className="px-3 py-2">Description</th>
                                    <th className="px-3 py-2">Quantity</th>
                                    <th className="px-3 py-2">Unit</th>
                                    <th className="px-3 py-2 text-right">Unit Price</th>
                                    <th className="px-3 py-2 text-right">Line Total</th>
                                    <th className="px-3 py-2">Catalog Schedule</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {purchaseOrder.items.map((item, idx) => (
                                    <tr
                                        key={item.id}
                                        className={
                                            item.is_financial_adjustment
                                                ? 'bg-amber-50/40 dark:bg-amber-950/20'
                                                : ''
                                        }
                                    >
                                        <td className="px-3 py-2 text-center text-muted-foreground">
                                            {idx + 1}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-foreground font-medium">
                                            {item.item_code ?? '—'}
                                        </td>
                                        <td
                                            className="px-3 py-2 max-w-xs truncate"
                                            title={item.product_description ?? ''}
                                        >
                                            {item.product_description ?? '—'}
                                            {item.is_financial_adjustment && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2 text-[10px] text-amber-600 border-amber-300"
                                                >
                                                    Adjustment
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 font-semibold">
                                            {item.quantity ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {item.unit ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {item.unit_price ? `₱${item.unit_price}` : '—'}
                                        </td>
                                        <td className="px-3 py-2 text-right font-medium">
                                            {item.line_total ? `₱${item.line_total}` : '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {item.matched_schedule ? (
                                                <span className="inline-flex items-center gap-1 text-[11px] text-emerald-600">
                                                    <CheckCircle2 className="size-3" />
                                                    {item.matched_schedule.sku_number}
                                                </span>
                                            ) : (
                                                <span className="text-[11px] text-muted-foreground">
                                                    Unmatched
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {purchaseOrder.items.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No line items recorded for this purchase order.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Receipt Evidence & Warehouse Arrivals */}
                <div className="mb-8">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-sm font-semibold flex items-center gap-2">
                            <Receipt className="size-4 text-primary" />
                            Linked Receipts & Warehouse Stock (
                            {purchaseOrder.linked_receipts.length})
                        </h2>
                    </div>

                    {purchaseOrder.linked_receipts.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-8 text-center text-muted-foreground text-xs">
                            <Clock className="size-8 mx-auto mb-2 text-muted-foreground/60" />
                            <p className="font-medium text-sm text-foreground">
                                No receiving uploads linked yet
                            </p>
                            <p className="mt-1">
                                When a receiving document (invoice, delivery receipt) matching this
                                PO is uploaded, receipt evidence and stock will be recorded
                                automatically.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {purchaseOrder.linked_receipts.map((receipt) => (
                                <Card key={receipt.id} className="overflow-hidden">
                                    <CardHeader className="bg-muted/30 py-2.5 px-4 flex flex-row items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Badge variant="outline" className="font-mono text-xs">
                                                {receipt.serial_prefix}-{receipt.serial_number}
                                            </Badge>
                                            <span className="text-xs font-medium text-foreground">
                                                {receipt.upload_type}{' '}
                                                {receipt.document_type
                                                    ? `· ${receipt.document_type}`
                                                    : ''}
                                            </span>
                                            <Badge variant="secondary" className="text-[10px]">
                                                Linked {receipt.source}
                                            </Badge>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-[11px] text-muted-foreground">
                                                {new Date(receipt.linked_at).toLocaleDateString()}
                                            </span>
                                            {receipt.upload_id && (
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 text-xs"
                                                >
                                                    <Link
                                                        href={`/admin/uploads/${receipt.upload_id}`}
                                                    >
                                                        View receipt
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        {receipt.arrivals.length > 0 ? (
                                            <table className="w-full text-left text-xs">
                                                <thead className="border-b bg-muted/20 text-muted-foreground">
                                                    <tr>
                                                        <th className="px-4 py-1.5">Item</th>
                                                        <th className="px-4 py-1.5">Arrived Qty</th>
                                                        <th className="px-4 py-1.5">
                                                            Arrival Date
                                                        </th>
                                                        <th className="px-4 py-1.5">
                                                            Posting Status
                                                        </th>
                                                        <th className="px-4 py-1.5">
                                                            Warehouse Lot
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y">
                                                    {receipt.arrivals.map((arrival) => (
                                                        <tr key={arrival.id}>
                                                            <td className="px-4 py-2">
                                                                <span className="font-medium text-foreground">
                                                                    {arrival.product_description ??
                                                                        arrival.item_code}
                                                                </span>
                                                                {arrival.item_code && (
                                                                    <span className="block text-[10px] font-mono text-muted-foreground">
                                                                        {arrival.item_code}
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-2 font-semibold">
                                                                {arrival.arrived_quantity}{' '}
                                                                {arrival.unit ?? ''}
                                                            </td>
                                                            <td className="px-4 py-2 text-muted-foreground">
                                                                {arrival.arrival_date ?? '—'}
                                                            </td>
                                                            <td className="px-4 py-2">
                                                                <StatusBadge
                                                                    value={
                                                                        arrival.posting_status ??
                                                                        'pending'
                                                                    }
                                                                />
                                                            </td>
                                                            <td className="px-4 py-2">
                                                                {arrival.stock_lot ? (
                                                                    <div className="flex items-center gap-1.5">
                                                                        <Layers className="size-3.5 text-emerald-600" />
                                                                        <span className="font-mono text-xs font-medium">
                                                                            Lot #
                                                                            {arrival.stock_lot.id}
                                                                        </span>
                                                                        <span className="text-[10px] text-muted-foreground">
                                                                            (
                                                                            {
                                                                                arrival.stock_lot
                                                                                    .posting_provenance
                                                                            }
                                                                            )
                                                                        </span>
                                                                    </div>
                                                                ) : (
                                                                    <span className="text-muted-foreground text-[11px]">
                                                                        —
                                                                    </span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        ) : (
                                            <p className="p-4 text-xs text-muted-foreground">
                                                No specific arrival line items tracked for this
                                                linked receipt.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>
            </PageShell>
        </>
    );
}

PurchaseOrderShow.layout = {
    breadcrumbs: [
        { title: 'Purchase orders', href: '/admin/purchase-orders' },
        { title: 'Details', href: '#' },
    ],
};
