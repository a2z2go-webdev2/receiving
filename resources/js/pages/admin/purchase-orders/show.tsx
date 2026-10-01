import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Calendar,
    CheckCircle2,
    Clock,
    FileSpreadsheet,
    FileText,
    Layers,
    Package,
    Receipt,
} from 'lucide-react';
import { PageShell } from '@/components/receiving/page-shell';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';

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

function formatMoney(amount: string | number | null | undefined): string {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }
    const num =
        typeof amount === 'number' ? amount : parseFloat(String(amount).replace(/[^0-9.-]+/g, ''));
    if (isNaN(num)) {
        return String(amount);
    }
    return `₱${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`;
}

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
                {/* Details List Form (replacing cards) */}
                <div className="mb-8 overflow-hidden rounded-lg border bg-card">
                    <div className="border-b bg-muted/40 px-4 py-3">
                        <h2 className="flex items-center gap-2 font-semibold text-sm">
                            {isSheet ? (
                                <FileSpreadsheet className="size-4 text-primary" />
                            ) : (
                                <FileText className="size-4 text-primary" />
                            )}
                            Purchase Order Details
                        </h2>
                    </div>

                    {isSheet ? (
                        <dl className="grid grid-cols-1 divide-y text-xs md:grid-cols-2 md:divide-y-0">
                            {/* Left Column: Order & Financial Details */}
                            <div className="divide-y">
                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">Status:</dt>
                                    <dd className="flex items-center gap-2 font-medium">
                                        <StatusBadge value={purchaseOrder.source_status} />
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] uppercase font-normal"
                                        >
                                            {purchaseOrder.sheet_source?.name ?? 'Google Sheet'}
                                        </Badge>
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        PO Number:
                                    </dt>
                                    <dd className="font-mono font-medium text-foreground">
                                        {purchaseOrder.po_number ?? '—'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">PO Date:</dt>
                                    <dd className="flex items-center gap-1.5 font-medium text-foreground">
                                        <Calendar className="size-3.5 text-muted-foreground" />
                                        {purchaseOrder.po_date_value ??
                                            purchaseOrder.po_date ??
                                            'Not recorded'}
                                    </dd>
                                </div>

                                {purchaseOrder.subtotal !== null && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            Net Total (Subtotal):
                                        </dt>
                                        <dd className="font-medium text-foreground">
                                            {formatMoney(purchaseOrder.subtotal)}
                                        </dd>
                                    </div>
                                )}

                                {purchaseOrder.vat !== null && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            VAT Total:
                                        </dt>
                                        <dd className="font-medium text-foreground">
                                            {formatMoney(purchaseOrder.vat)}
                                        </dd>
                                    </div>
                                )}

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5 bg-muted/20">
                                    <dt className="font-semibold text-foreground">Total Amount:</dt>
                                    <dd className="font-bold text-foreground">
                                        {formatMoney(purchaseOrder.total_amount)}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        Source Channel:
                                    </dt>
                                    <dd className="text-foreground">
                                        Google Sheet ({purchaseOrder.sheet_source?.name ?? 'Sync'})
                                    </dd>
                                </div>

                                {purchaseOrder.snapshot_timestamp && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            Last Synchronized:
                                        </dt>
                                        <dd className="text-muted-foreground">
                                            {new Date(
                                                purchaseOrder.snapshot_timestamp,
                                            ).toLocaleString()}
                                        </dd>
                                    </div>
                                )}
                            </div>

                            {/* Right Column: Supplier & Order Information */}
                            <div className="divide-y md:border-l">
                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">Supplier:</dt>
                                    <dd className="font-medium text-foreground">
                                        {purchaseOrder.vendor_name ?? '—'}
                                    </dd>
                                </div>

                                {purchaseOrder.contact_person && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            Contact Person:
                                        </dt>
                                        <dd className="text-foreground">
                                            {purchaseOrder.contact_person}
                                        </dd>
                                    </div>
                                )}

                                {purchaseOrder.buyer_company && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            Buyer Company:
                                        </dt>
                                        <dd className="text-foreground">
                                            {purchaseOrder.buyer_company}
                                        </dd>
                                    </div>
                                )}

                                {purchaseOrder.buyer_address && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            Delivery Address:
                                        </dt>
                                        <dd
                                            className="text-foreground truncate"
                                            title={purchaseOrder.buyer_address}
                                        >
                                            {purchaseOrder.buyer_address}
                                        </dd>
                                    </div>
                                )}

                                {purchaseOrder.buyer_contact_numbers && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            Buyer Contact:
                                        </dt>
                                        <dd className="text-foreground">
                                            {purchaseOrder.buyer_contact_numbers}
                                        </dd>
                                    </div>
                                )}

                                {purchaseOrder.po_reference && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            PO Reference:
                                        </dt>
                                        <dd className="text-foreground">
                                            {purchaseOrder.po_reference}
                                        </dd>
                                    </div>
                                )}

                                {purchaseOrder.payment_terms && (
                                    <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                        <dt className="font-medium text-muted-foreground">
                                            Payment Terms:
                                        </dt>
                                        <dd className="text-foreground">
                                            {purchaseOrder.payment_terms}
                                        </dd>
                                    </div>
                                )}
                            </div>

                            {/* Full-width bottom row for Notes/Remarks if present */}
                            {purchaseOrder.notes && (
                                <div className="col-span-full border-t px-4 py-3">
                                    <dt className="mb-1 font-medium text-muted-foreground">
                                        Notes / Remarks:
                                    </dt>
                                    <dd className="whitespace-pre-wrap rounded bg-muted/40 p-2.5 font-mono text-xs text-foreground">
                                        {purchaseOrder.notes}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    ) : (
                        <dl className="grid grid-cols-1 divide-y text-xs md:grid-cols-2 md:divide-y-0">
                            {/* Left Column: Upload Order Details */}
                            <div className="divide-y">
                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        Arrival Status:
                                    </dt>
                                    <dd className="flex items-center gap-2">
                                        <StatusBadge value={purchaseOrder.arrival_status} />
                                        {purchaseOrder.waiting_time.days !== null && (
                                            <span className="text-xs text-muted-foreground">
                                                {purchaseOrder.waiting_time.days}d waiting
                                            </span>
                                        )}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        Source Status:
                                    </dt>
                                    <dd className="flex items-center gap-2">
                                        <StatusBadge value={purchaseOrder.source_status} />
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] uppercase font-normal"
                                        >
                                            PDF Upload
                                        </Badge>
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        PO Number:
                                    </dt>
                                    <dd className="font-mono font-medium text-foreground">
                                        {purchaseOrder.po_number ?? '—'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">PO Date:</dt>
                                    <dd className="flex items-center gap-1.5 font-medium text-foreground">
                                        <Calendar className="size-3.5 text-muted-foreground" />
                                        {purchaseOrder.po_date_value ??
                                            purchaseOrder.po_date ??
                                            'Not recorded'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5 bg-muted/20">
                                    <dt className="font-semibold text-foreground">Total Amount:</dt>
                                    <dd className="font-bold text-foreground">
                                        {formatMoney(purchaseOrder.total_amount)}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        Source Channel:
                                    </dt>
                                    <dd className="text-foreground">Uploaded Document</dd>
                                </div>
                            </div>

                            {/* Right Column: Upload Party Details */}
                            <div className="divide-y md:border-l">
                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        Supplier Name:
                                    </dt>
                                    <dd className="font-medium text-foreground">
                                        {purchaseOrder.vendor_name ?? '—'}
                                    </dd>
                                </div>

                                {purchaseOrder.vendor_raw &&
                                    purchaseOrder.vendor_raw !== purchaseOrder.vendor_name && (
                                        <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                            <dt className="font-medium text-muted-foreground">
                                                Source Raw:
                                            </dt>
                                            <dd className="font-mono text-[11px]">
                                                {purchaseOrder.vendor_raw}
                                            </dd>
                                        </div>
                                    )}

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        Contact Person:
                                    </dt>
                                    <dd className="text-foreground">
                                        {purchaseOrder.contact_person ?? '—'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">Email:</dt>
                                    <dd className="text-foreground">
                                        {purchaseOrder.vendor_email ?? '—'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">Mobile:</dt>
                                    <dd className="text-foreground">
                                        {purchaseOrder.vendor_mobile ?? '—'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">Address:</dt>
                                    <dd
                                        className="text-foreground truncate"
                                        title={purchaseOrder.vendor_address ?? ''}
                                    >
                                        {purchaseOrder.vendor_address ?? '—'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        PO Reference:
                                    </dt>
                                    <dd className="text-foreground">
                                        {purchaseOrder.po_reference ?? '—'}
                                    </dd>
                                </div>

                                <div className="grid grid-cols-[9rem_1fr] items-center gap-2 px-4 py-2.5">
                                    <dt className="font-medium text-muted-foreground">
                                        Payment Terms:
                                    </dt>
                                    <dd className="text-foreground">
                                        {purchaseOrder.payment_terms ?? '—'}
                                    </dd>
                                </div>
                            </div>

                            {purchaseOrder.notes && (
                                <div className="col-span-full border-t px-4 py-3">
                                    <dt className="mb-1 font-medium text-muted-foreground">
                                        Notes:
                                    </dt>
                                    <dd className="whitespace-pre-wrap rounded bg-muted/40 p-2 text-foreground">
                                        {purchaseOrder.notes}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    )}
                </div>

                {/* Ordered Items Table */}
                <div className="mb-8">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 font-semibold text-sm">
                            <Package className="size-4 text-primary" />
                            Ordered Items ({purchaseOrder.items.length})
                        </h2>
                    </div>
                    <div className="overflow-x-auto rounded-lg border bg-card">
                        <table className="w-full whitespace-nowrap text-left text-xs">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="w-12 px-3 py-2 text-center">#</th>
                                    <th className="px-3 py-2">Item Code</th>
                                    <th className="px-3 py-2">Description</th>
                                    <th className="px-3 py-2">Quantity</th>
                                    <th className="px-3 py-2">Unit</th>
                                    <th className="px-3 py-2 text-right">Unit Price</th>
                                    <th className="px-3 py-2 text-right">Line Total</th>
                                    {!isSheet && <th className="px-3 py-2">Catalog Schedule</th>}
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
                                        <td className="font-mono font-medium text-foreground px-3 py-2">
                                            {item.item_code ?? '—'}
                                        </td>
                                        <td
                                            className="max-w-xs truncate px-3 py-2"
                                            title={item.product_description ?? ''}
                                        >
                                            {item.product_description ?? '—'}
                                            {item.is_financial_adjustment && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2 border-amber-300 text-[10px] text-amber-600"
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
                                            {formatMoney(item.unit_price)}
                                        </td>
                                        <td className="px-3 py-2 text-right font-medium">
                                            {formatMoney(item.line_total)}
                                        </td>
                                        {!isSheet && (
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
                                        )}
                                    </tr>
                                ))}
                                {purchaseOrder.items.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={isSheet ? 7 : 8}
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

                {/* Receipt Evidence & Warehouse Arrivals (Uploads only) */}
                {!isSheet && (
                    <div className="mb-8">
                        <div className="mb-2 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 font-semibold text-sm">
                                <Receipt className="size-4 text-primary" />
                                Linked Receipts & Warehouse Stock (
                                {purchaseOrder.linked_receipts.length})
                            </h2>
                        </div>

                        {purchaseOrder.linked_receipts.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-8 text-center text-muted-foreground text-xs">
                                <Clock className="mx-auto mb-2 size-8 text-muted-foreground/60" />
                                <p className="font-medium text-foreground text-sm">
                                    No receiving uploads linked yet
                                </p>
                                <p className="mt-1">
                                    When a receiving document (invoice, delivery receipt) matching
                                    this PO is uploaded, receipt evidence and stock will be recorded
                                    automatically.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {purchaseOrder.linked_receipts.map((receipt) => (
                                    <Card key={receipt.id} className="overflow-hidden">
                                        <CardHeader className="flex flex-row items-center justify-between bg-muted/30 px-4 py-2.5">
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className="font-mono text-xs"
                                                >
                                                    {receipt.serial_prefix}-{receipt.serial_number}
                                                </Badge>
                                                <span className="font-medium text-foreground text-xs">
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
                                                    {new Date(
                                                        receipt.linked_at,
                                                    ).toLocaleDateString()}
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
                                                            <th className="px-4 py-1.5">
                                                                Arrived Qty
                                                            </th>
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
                                                                        <span className="block font-mono text-[10px] text-muted-foreground">
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
                                                                            <span className="font-mono font-medium text-xs">
                                                                                Lot #
                                                                                {
                                                                                    arrival
                                                                                        .stock_lot
                                                                                        .id
                                                                                }
                                                                            </span>
                                                                            <span className="text-[10px] text-muted-foreground">
                                                                                (
                                                                                {
                                                                                    arrival
                                                                                        .stock_lot
                                                                                        .posting_provenance
                                                                                }
                                                                                )
                                                                            </span>
                                                                        </div>
                                                                    ) : (
                                                                        <span className="text-[11px] text-muted-foreground">
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
                )}
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
