import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    ExternalLink,
    Files,
    Link2,
    Mail,
    MapPin,
    RotateCcw,
    Unlink,
} from 'lucide-react';
import { useState } from 'react';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { StatusBadge } from '@/components/receiving/status-badge';
import { announceSessionExpired } from '@/components/session-expired-dialog';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLivePageData } from '@/hooks/use-live-page-data';
import { csrfToken } from '@/lib/csrf';
import { aiStatusLabel } from '@/lib/upload-status';

type ExtractedField = { label: string; value: string };
type ExtractedData = {
    document_type: string;
    fields: ExtractedField[];
    items: Record<string, string>[];
    _warnings?: string[];
};
type PurchaseOrderCandidate = {
    id: number;
    upload_id: number;
    po_number: string | null;
    po_date: string | null;
    vendor_name: string | null;
    uploaded_at: string;
};
type PurchaseOrderLink = {
    id: number;
    po_extraction_id: number;
    po_upload_id: number;
    po_number: string | null;
    po_date: string | null;
    vendor_name: string | null;
    source: string;
    linked_at: string;
};
type PurchaseOrderLinkedUpload = {
    id: number;
    serial_number: number;
    upload_type: string;
    document_type: string | null;
    source: string;
    linked_at: string;
};
type FileExtraction = {
    id: number;
    document_type: string | null;
    extracted_data: ExtractedData | null;
    corrected_data: ExtractedData | null;
    extracted_at: string | null;
    reviewed_at: string | null;
    reviewed_by_email: string | null;
    po_number?: string | null;
    po_date?: string | null;
    po_link_status?: string | null;
    po_link?: PurchaseOrderLink | null;
    po_link_candidates?: PurchaseOrderCandidate[];
    purchase_order_status?: string | null;
    purchase_order_linked_uploads?: PurchaseOrderLinkedUpload[];
};
type FileRow = {
    id: number;
    name: string;
    content_type: string | null;
    size: number | null;
    virus_scan_status: string;
    failure_reason: string | null;
    extraction: FileExtraction | null;
};
export type Upload = {
    id: number;
    serial_number: number;
    serial_prefix: string;
    upload_type: string;
    sends_notifications: boolean;
    requires_review: boolean;
    uploader_email: string;
    created_at: string;
    location: {
        latitude: number;
        longitude: number;
        accuracy_meters: number | null;
        captured_at: string | null;
    } | null;
    review_email_status: string;
    ai_status: string;
    review_status: string;
    can_resend: boolean;
    can_retry_ai: boolean;
    can_manage_purchase_order_links: boolean;
    receiving_email_failed: boolean;
    files: FileRow[];
};

export default function UploadDetail({
    upload,
    adminView = false,
}: {
    upload: Upload;
    adminView?: boolean;
}) {
    useLivePageData(['upload']);

    const [opening, setOpening] = useState<number | null>(null);
    const [expandedFileId, setExpandedFileId] = useState<number | null>(null);

    async function openFile(file: FileRow) {
        setOpening(file.id);
        try {
            const response = await fetch(`/receiving/files/${file.id}/url`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            });
            if (!response.ok) {
                if (response.status === 401 || response.status === 419) {
                    const body = (await response.json().catch(() => null)) as {
                        message?: string;
                    } | null;
                    announceSessionExpired(body?.message);
                }

                return;
            }
            const { url } = (await response.json()) as { url: string };
            window.open(url, '_blank', 'noopener,noreferrer');
        } finally {
            setOpening(null);
        }
    }

    const actions =
        !adminView && (upload.can_resend || upload.can_retry_ai) ? (
            <div className="flex flex-wrap gap-2">
                {upload.can_resend && (
                    <Button
                        variant="outline"
                        onClick={() => router.post(`/receiving/uploads/${upload.id}/resend`)}
                    >
                        <Mail /> Resend receiving email
                    </Button>
                )}
                {upload.can_retry_ai && (
                    <Button
                        variant="outline"
                        onClick={() => router.post(`/receiving/uploads/${upload.id}/retry-ai`)}
                    >
                        <RotateCcw /> Retry failed AI
                    </Button>
                )}
            </div>
        ) : undefined;

    const headerDetails = (
        <div className="flex flex-col items-start gap-3 sm:items-end">
            <div className="flex flex-wrap gap-2">
                {upload.requires_review && (
                    <CompactStatus label="Review email" value={upload.review_email_status} />
                )}
                <CompactStatus
                    label="AI extraction"
                    value={upload.ai_status}
                    valueLabel={aiStatusLabel(upload.ai_status)}
                />
                {upload.requires_review && (
                    <CompactStatus label="Review status" value={upload.review_status} />
                )}
            </div>
            {actions}
        </div>
    );

    return (
        <>
            <Head title={`${upload.upload_type} ${upload.serial_prefix}-${upload.serial_number}`} />
            <PageShell
                title={`${upload.upload_type} ${upload.serial_prefix}-${upload.serial_number}`}
                description={`${upload.upload_type} · Uploaded by ${upload.uploader_email} on ${new Date(upload.created_at).toLocaleString()}`}
                actions={headerDetails}
            >
                <FlashMessage />

                {adminView && <FloatingUploadLocation location={upload.location} />}

                <section className="mt-4 space-y-3">
                    <div className="flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 shadow-sm">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Files className="size-4" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="font-semibold text-base">Uploaded files</h2>
                                <span className="flex h-4 items-center justify-center rounded-full bg-muted px-2 font-medium text-[10px] text-muted-foreground">
                                    {upload.files.length}
                                </span>
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Select one file to view its{' '}
                                {upload.requires_review
                                    ? 'extracted and corrected'
                                    : 'full extracted'}{' '}
                                data.
                            </p>
                        </div>
                    </div>
                    <div className="space-y-2">
                        {upload.files.map((file) => (
                            <FileDetails
                                key={`${upload.id}-${file.id}`}
                                file={file}
                                opening={opening === file.id}
                                expanded={expandedFileId === file.id}
                                onExpandedChange={(expanded) =>
                                    setExpandedFileId(expanded ? file.id : null)
                                }
                                onOpen={() => openFile(file)}
                                adminView={adminView}
                                uploadId={upload.id}
                                canManagePurchaseOrderLinks={upload.can_manage_purchase_order_links}
                            />
                        ))}
                    </div>
                </section>

                {upload.receiving_email_failed && !upload.can_resend && !adminView && (
                    <div className="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                        <Mail className="size-5" />
                        <p className="text-sm">
                            The receiving email failed, but the upload is safely recorded. Contact
                            an administrator if you cannot retry it.
                        </p>
                    </div>
                )}
            </PageShell>
        </>
    );
}

function FloatingUploadLocation({ location }: { location: Upload['location'] }) {
    const [isOpen, setIsOpen] = useState(false);
    const coordinates = location ? `${location.latitude},${location.longitude}` : '';
    const mapUrl = location
        ? `https://maps.google.com/maps?q=${encodeURIComponent(coordinates)}&z=15&output=embed`
        : '';
    const directionsUrl = location
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(coordinates)}`
        : '';

    return (
        <div className="fixed right-6 bottom-6 z-50 flex flex-col items-end gap-3">
            {isOpen && (
                <section className="w-80 overflow-hidden rounded-xl border bg-card shadow-xl transition-all sm:w-[400px]">
                    <div className="flex flex-col gap-3 border-b p-3">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-start gap-3">
                                <div
                                    className={`shrink-0 rounded-full p-2 ${location ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'}`}
                                >
                                    <MapPin className="size-5" />
                                </div>
                                <div>
                                    <h2 className="font-semibold text-sm">Upload location</h2>
                                    {location ? (
                                        <>
                                            <p className="text-muted-foreground text-xs">
                                                {location.latitude.toFixed(6)},{' '}
                                                {location.longitude.toFixed(6)}
                                                {location.accuracy_meters !== null
                                                    ? ` · accuracy ${Math.round(location.accuracy_meters)}m`
                                                    : ''}
                                            </p>
                                            {location.captured_at && (
                                                <p className="mt-0.5 text-[10px] text-muted-foreground">
                                                    Captured{' '}
                                                    {new Date(
                                                        location.captured_at,
                                                    ).toLocaleString()}
                                                </p>
                                            )}
                                        </>
                                    ) : (
                                        <p className="text-muted-foreground text-xs">
                                            Location was not captured for this upload.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                        {location && (
                            <Button asChild variant="outline" size="sm" className="w-full">
                                <a href={directionsUrl} target="_blank" rel="noreferrer">
                                    <ExternalLink className="mr-2 size-4" /> Open in Google Maps
                                </a>
                            </Button>
                        )}
                    </div>
                    {location && (
                        <iframe
                            title="Captured upload location on Google Maps"
                            src={mapUrl}
                            className="h-64 w-full border-0 sm:h-72"
                            loading="lazy"
                            referrerPolicy="no-referrer-when-downgrade"
                        />
                    )}
                </section>
            )}
            <Button
                variant={location ? 'default' : 'secondary'}
                size="icon"
                className={`h-12 w-12 rounded-full shadow-lg transition-all hover:shadow-xl ${!location ? 'opacity-70' : ''}`}
                onClick={() => setIsOpen(!isOpen)}
                aria-label="Toggle upload location"
            >
                <MapPin className={`size-5 ${!location ? 'text-muted-foreground' : ''}`} />
            </Button>
        </div>
    );
}

function CompactStatus({
    label,
    value,
    valueLabel,
}: {
    label: string;
    value: string;
    valueLabel?: string;
}) {
    return (
        <div className="flex flex-col gap-1.5 rounded-lg border bg-card px-3 py-2 shadow-sm">
            <span className="font-medium text-[10px] text-muted-foreground uppercase tracking-wider">
                {label}
            </span>
            <div>
                <StatusBadge value={value} label={valueLabel} />
            </div>
        </div>
    );
}

function FileDetails({
    file,
    opening,
    expanded,
    onExpandedChange,
    onOpen,
    adminView,
    uploadId,
    canManagePurchaseOrderLinks,
}: {
    file: FileRow;
    opening: boolean;
    expanded: boolean;
    onExpandedChange: (expanded: boolean) => void;
    onOpen: () => void;
    adminView: boolean;
    uploadId: number;
    canManagePurchaseOrderLinks: boolean;
}) {
    const detailsId = `file-details-${file.id}`;

    return (
        <section className="overflow-hidden rounded-lg border bg-card">
            <div className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                <button
                    type="button"
                    className="min-w-0 flex-1 rounded-md text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    aria-expanded={expanded}
                    aria-controls={detailsId}
                    onClick={() => onExpandedChange(!expanded)}
                >
                    <span className="block truncate font-medium text-sm">{file.name}</span>
                    <span className="mt-0.5 block text-[11px] text-muted-foreground">
                        {file.size ? `${(file.size / 1024).toFixed(1)} KB` : 'Size unavailable'}
                        {file.content_type ? ` · ${file.content_type}` : ''}
                    </span>
                </button>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        value={file.virus_scan_status}
                        label={`Security: ${friendlyStatus(file.virus_scan_status)}`}
                    />
                    <StatusBadge
                        value={file.extraction?.document_type}
                        label={`Document: ${file.extraction?.document_type ?? 'Not identified'}`}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!file.size || opening}
                        onClick={onOpen}
                    >
                        <ExternalLink /> {opening ? 'Opening…' : 'Open file'}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="rounded-full"
                        aria-label={`${expanded ? 'Hide' : 'View'} details for ${file.name}`}
                        aria-expanded={expanded}
                        aria-controls={detailsId}
                        onClick={() => onExpandedChange(!expanded)}
                    >
                        <ChevronDown
                            className={`transition-transform ${expanded ? 'rotate-180' : ''}`}
                        />
                    </Button>
                </div>
            </div>
            {expanded && (
                <div id={detailsId} className="space-y-4 border-t p-3">
                    {file.failure_reason && (
                        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-red-800 text-sm">
                            {file.failure_reason}
                        </p>
                    )}

                    {file.extraction ? (
                        <div
                            className={
                                file.extraction.extracted_data && file.extraction.corrected_data
                                    ? 'grid gap-4 xl:grid-cols-2 xl:items-start'
                                    : 'space-y-4'
                            }
                        >
                            <PurchaseOrderPanel
                                extraction={file.extraction}
                                adminView={adminView}
                                uploadId={uploadId}
                                canManagePurchaseOrderLinks={canManagePurchaseOrderLinks}
                            />
                            {file.extraction.extracted_data && (
                                <FriendlyData
                                    title={
                                        file.extraction.corrected_data
                                            ? 'Raw AI data'
                                            : 'AI extracted data'
                                    }
                                    data={file.extraction.extracted_data}
                                    variant={file.extraction.corrected_data ? 'raw' : 'default'}
                                />
                            )}
                            {file.extraction.corrected_data && (
                                <FriendlyData
                                    title="Corrected data"
                                    data={file.extraction.corrected_data}
                                    variant="corrected"
                                />
                            )}
                        </div>
                    ) : (
                        <p className="rounded-lg border border-dashed p-4 text-muted-foreground text-sm">
                            Extracted data is not available for this file yet.
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

function PurchaseOrderPanel({
    extraction,
    adminView,
    uploadId,
    canManagePurchaseOrderLinks,
}: {
    extraction: FileExtraction;
    adminView: boolean;
    uploadId: number;
    canManagePurchaseOrderLinks: boolean;
}) {
    const [selectedPoId, setSelectedPoId] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const invoiceStatus = extraction.po_link_status;
    const purchaseOrderStatus = extraction.purchase_order_status;
    const linkedUploads = extraction.purchase_order_linked_uploads ?? [];

    if (!invoiceStatus && !purchaseOrderStatus) {
        return null;
    }

    function linkPurchaseOrder() {
        if (!selectedPoId) return;
        setSubmitting(true);
        router.post(
            `/admin/uploads/${uploadId}/extractions/${extraction.id}/purchase-order-link`,
            { po_extraction_id: selectedPoId },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedPoId(''),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    function unlinkPurchaseOrder() {
        setSubmitting(true);
        router.delete(
            `/admin/uploads/${uploadId}/extractions/${extraction.id}/purchase-order-link`,
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    }

    if (purchaseOrderStatus) {
        return (
            <section className="rounded-lg border bg-background p-3 xl:col-span-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 className="font-semibold text-sm">Arrival status</h3>
                        <p className="text-muted-foreground text-xs">
                            {linkedUploads.length > 0
                                ? `${linkedUploads.length} invoice or receipt upload${linkedUploads.length === 1 ? '' : 's'} linked.`
                                : 'No invoice or receipt has been linked yet.'}
                        </p>
                    </div>
                    <StatusBadge value={purchaseOrderStatus} />
                </div>
                {linkedUploads.length > 0 && (
                    <div className="mt-2 space-y-1 text-xs">
                        {linkedUploads.map((linkedUpload) => (
                            <div
                                key={linkedUpload.id}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <StatusBadge value={linkedUpload.source} />
                                <a
                                    href={`/admin/uploads/${linkedUpload.id}`}
                                    className="font-medium underline"
                                >
                                    Open SN-{linkedUpload.serial_number}
                                </a>
                            </div>
                        ))}
                    </div>
                )}
            </section>
        );
    }

    const candidates = extraction.po_link_candidates ?? [];
    const canManualLink =
        adminView && canManagePurchaseOrderLinks && !extraction.po_link && candidates.length > 0;

    return (
        <section className="rounded-lg border bg-background p-3 xl:col-span-2">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="font-semibold text-sm">Purchase order link</h3>
                    <p className="text-muted-foreground text-xs">
                        {poLinkHelpText(invoiceStatus ?? 'not_applicable', extraction)}
                    </p>
                </div>
                <StatusBadge value={invoiceStatus} />
            </div>

            {extraction.po_link && (
                <div className="mt-3 flex flex-col gap-2 rounded-md border p-2 text-xs sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="font-medium">
                            {extraction.po_link.po_number ||
                                `PO upload ${extraction.po_link.po_upload_id}`}
                        </p>
                        <p className="text-muted-foreground">
                            {[
                                extraction.po_link.po_date || 'No PO date',
                                extraction.po_link.vendor_name,
                                extraction.po_link.source,
                            ]
                                .filter(Boolean)
                                .join(' - ')}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <a href={`/admin/uploads/${extraction.po_link.po_upload_id}`}>
                                <ExternalLink /> Open PO
                            </a>
                        </Button>
                        {adminView && canManagePurchaseOrderLinks && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={unlinkPurchaseOrder}
                                disabled={submitting}
                            >
                                <Unlink /> Unlink
                            </Button>
                        )}
                    </div>
                </div>
            )}

            {canManualLink && (
                <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Select value={selectedPoId} onValueChange={setSelectedPoId}>
                        <SelectTrigger className="min-w-0 sm:max-w-md">
                            <SelectValue placeholder="Choose uploaded PO" />
                        </SelectTrigger>
                        <SelectContent>
                            {candidates.map((candidate) => (
                                <SelectItem key={candidate.id} value={String(candidate.id)}>
                                    {candidateLabel(candidate)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        type="button"
                        size="sm"
                        onClick={linkPurchaseOrder}
                        disabled={!selectedPoId || submitting}
                    >
                        <Link2 /> Link PO
                    </Button>
                </div>
            )}
        </section>
    );
}

function FriendlyData({
    title,
    data,
    variant = 'default',
}: {
    title: string;
    data: ExtractedData;
    variant?: 'default' | 'raw' | 'corrected';
}) {
    const columns = Array.from(new Set(data.items.flatMap((item) => Object.keys(item))));

    const headerStyles = {
        default: 'border-b bg-muted/25',
        raw: 'border-b border-blue-200 bg-blue-50/50 dark:border-blue-900/50 dark:bg-blue-950/20',
        corrected:
            'border-b border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/50 dark:bg-emerald-950/20',
    };

    const containerStyles = {
        default: 'border',
        raw: 'border border-blue-200/50 shadow-sm shadow-blue-500/5 dark:border-blue-900/50',
        corrected:
            'border border-emerald-200/50 shadow-sm shadow-emerald-500/5 dark:border-emerald-900/50',
    };

    return (
        <section className={`overflow-hidden rounded-lg bg-card ${containerStyles[variant]}`}>
            <div
                className={`flex flex-wrap items-center justify-between gap-2 px-3 py-2 ${headerStyles[variant]}`}
            >
                <div>
                    <h3
                        className={`font-semibold text-sm ${
                            variant === 'raw'
                                ? 'text-blue-900 dark:text-blue-300'
                                : variant === 'corrected'
                                  ? 'text-emerald-900 dark:text-emerald-300'
                                  : ''
                        }`}
                    >
                        {title}
                    </h3>
                    <p
                        className={`mt-0.5 text-[11px] ${
                            variant === 'raw'
                                ? 'text-blue-700/70 dark:text-blue-400/70'
                                : variant === 'corrected'
                                  ? 'text-emerald-700/70 dark:text-emerald-400/70'
                                  : 'text-muted-foreground'
                        }`}
                    >
                        Extracted document information
                    </p>
                </div>
                <StatusBadge value={data.document_type} label={data.document_type} />
            </div>
            <div className="space-y-3 p-3">
                {data._warnings?.map((warning) => (
                    <p
                        key={warning}
                        className="rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-900 text-sm"
                    >
                        {warning}
                    </p>
                ))}
                {data.fields.length > 0 ? (
                    <dl className="divide-y overflow-hidden rounded-md border">
                        {data.fields.map((field) => (
                            <div
                                key={`${field.label}-${field.value}`}
                                className="grid gap-1 px-2 py-1.5 sm:grid-cols-[minmax(10rem,0.35fr)_1fr] sm:gap-4"
                            >
                                <dt className="font-medium text-muted-foreground text-xs">
                                    {field.label}
                                </dt>
                                <dd className="break-words text-xs">{field.value || '—'}</dd>
                            </div>
                        ))}
                    </dl>
                ) : (
                    <p className="text-muted-foreground text-xs">No fields were extracted.</p>
                )}
                {data.items.length > 0 && (
                    <div>
                        <h4 className="mb-2 font-medium text-xs">Line items</h4>
                        <div className="overflow-x-auto rounded-md border bg-background">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        {columns.map((column) => (
                                            <th key={column} className="px-2 py-1.5">
                                                {friendlyLabel(column)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {data.items.map((item, index) => (
                                        // biome-ignore lint/suspicious/noArrayIndexKey: read-only extraction rows have no persisted row identifier
                                        <tr key={index}>
                                            {columns.map((column) => (
                                                <td key={column} className="px-2 py-1.5">
                                                    {item[column] || '—'}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}

function poLinkHelpText(status: string, extraction: FileExtraction): string {
    if (status === 'linked') {
        return 'This invoice or receipt is linked to an uploaded purchase order.';
    }

    if (status === 'missing_po_number') {
        return 'No PO number was found. Choose an uploaded PO to fill the missing PO details.';
    }

    if (status === 'awaiting_purchase_order') {
        return extraction.po_number
            ? `No uploaded PO matches ${extraction.po_number} yet.`
            : 'No matching uploaded PO is available yet.';
    }

    if (status === 'purchase_order_already_linked') {
        return 'A matching PO is already linked to another invoice or receipt.';
    }

    if (status === 'ready_to_link') {
        return 'A matching uploaded PO is available for manual linking.';
    }

    return 'PO linking does not apply to this document.';
}

function candidateLabel(candidate: PurchaseOrderCandidate): string {
    return [
        candidate.po_number || `PO upload ${candidate.upload_id}`,
        candidate.po_date || 'No PO date',
        candidate.vendor_name,
    ]
        .filter(Boolean)
        .join(' - ');
}

function friendlyLabel(value: string): string {
    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function friendlyStatus(value: string): string {
    return value.replaceAll('_', ' ').replace(/^\w/, (character) => character.toUpperCase());
}
