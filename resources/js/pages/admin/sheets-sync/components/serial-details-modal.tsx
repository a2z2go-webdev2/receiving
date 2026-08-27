import { CheckCircle2, ExternalLink, Eye, FileText, MapPin, Tag } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
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

interface StagedFile {
    id: number;
    file_name: string;
    file_id: string | null;
    file_url: string | null;
    mime_type: string;
    r2_url: string | null;
}

interface StagedExtraction {
    id: number;
    ai_status: string | null;
    raw_ai_json: string | null;
    corrected_json: string | null;
    extracted_at: string | null;
    error_message: string | null;
}

interface SerialItem {
    id: number;
    sheet_slug: string;
    serial_number: number;
    timestamp: string | null;
    drive_folder_link: string | null;
    file_count: number;
    email_status: string | null;
    ai_status: string | null;
    review_status: string | null;
    reviewed_at: string | null;
    reviewed_by: string | null;
    uploader_location: string | null;
    is_synced_to_db: boolean;
    synced_receiving_upload_id: number | null;
    files?: StagedFile[];
    extraction?: StagedExtraction | null;
}

interface SerialDetailsModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: SerialItem | null;
    onSyncClick?: (sn: number) => void;
}

interface ExtractedField {
    label?: string;
    value?: string;
}

interface ExtractedLineItem {
    itemCode?: string;
    description?: string;
    quantity?: string | number;
    unitPrice?: string | number;
    amount?: string | number;
}

interface ExtractedDoc {
    fileName?: string;
    documentType?: string;
    fields?: ExtractedField[];
    items?: ExtractedLineItem[];
}

export function SerialDetailsModal({
    open,
    onOpenChange,
    item,
    onSyncClick,
}: SerialDetailsModalProps) {
    const [jsonTab, setJsonTab] = useState<'corrected' | 'raw'>('corrected');

    if (!item) return null;

    let parsedCorrected: { documents?: ExtractedDoc[] } | null = null;
    let parsedRaw: { documents?: ExtractedDoc[] } | null = null;

    try {
        if (item.extraction?.corrected_json) {
            parsedCorrected = JSON.parse(item.extraction.corrected_json);
        }
    } catch {}

    try {
        if (item.extraction?.raw_ai_json) {
            parsedRaw = JSON.parse(item.extraction.raw_ai_json);
        }
    } catch {}

    const documents: ExtractedDoc[] = parsedCorrected?.documents || parsedRaw?.documents || [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] w-full max-w-full overflow-y-auto bg-card text-foreground sm:max-w-3xl md:max-w-4xl">
                <DialogHeader className="border-b pb-3">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="flex size-8 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                                <Eye className="size-4" />
                            </div>
                            <div>
                                <DialogTitle className="text-base font-semibold">
                                    Serial #{item.serial_number} ({item.sheet_slug.toUpperCase()})
                                </DialogTitle>
                                <DialogDescription className="text-xs">
                                    Uploaded: {item.timestamp || 'N/A'} • Reviewed by:{' '}
                                    {item.reviewed_by || 'jaezelle.benito@pingconmarketing.com'}
                                </DialogDescription>
                            </div>
                        </div>

                        <Badge
                            variant={item.is_synced_to_db ? 'default' : 'outline'}
                            className={`text-xs ${
                                item.is_synced_to_db
                                    ? 'bg-emerald-600 text-white'
                                    : 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400'
                            }`}
                        >
                            {item.is_synced_to_db
                                ? `Synced (Upload #${item.synced_receiving_upload_id})`
                                : 'Pending Sync'}
                        </Badge>
                    </div>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {/* Metadata Overview Cards */}
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <Card className="border p-2.5">
                            <div className="text-[10px] font-medium text-muted-foreground uppercase">
                                Review Status
                            </div>
                            <div className="mt-0.5 text-xs font-semibold capitalize">
                                {item.review_status || 'Pending'}
                            </div>
                        </Card>

                        <Card className="border p-2.5">
                            <div className="text-[10px] font-medium text-muted-foreground uppercase">
                                AI Status
                            </div>
                            <div className="mt-0.5 text-xs font-semibold capitalize text-indigo-600 dark:text-indigo-400">
                                {item.ai_status || 'Pending'}
                            </div>
                        </Card>

                        <Card className="border p-2.5">
                            <div className="text-[10px] font-medium text-muted-foreground uppercase">
                                Email Status
                            </div>
                            <div className="mt-0.5 text-xs font-semibold capitalize">
                                {item.email_status || 'Pending'}
                            </div>
                        </Card>

                        <Card className="border p-2.5">
                            <div className="text-[10px] font-medium text-muted-foreground uppercase">
                                R2 File Status
                            </div>
                            <div className="mt-0.5 font-mono text-xs font-semibold">
                                {(() => {
                                    const total = item.files?.length || item.file_count || 0;
                                    const r2 =
                                        item.files?.filter((f) =>
                                            Boolean(f.r2_url && f.r2_url.trim()),
                                        ).length || 0;
                                    const pending = Math.max(0, total - r2);
                                    return pending > 0 ? (
                                        <span className="text-amber-600 dark:text-amber-400">
                                            {r2}/{total} R2 ({pending} Pending)
                                        </span>
                                    ) : (
                                        <span className="text-emerald-600 dark:text-emerald-400">
                                            {r2}/{total} in R2
                                        </span>
                                    );
                                })()}
                            </div>
                        </Card>
                    </div>

                    {/* Geolocation if present */}
                    {item.uploader_location && (
                        <div className="flex items-center gap-2 rounded-lg border bg-muted/40 p-2.5 text-xs text-muted-foreground">
                            <MapPin className="size-4 text-rose-500" />
                            <span>
                                Location Coordinates:{' '}
                                <strong className="font-mono text-foreground">
                                    {item.uploader_location}
                                </strong>
                            </span>
                        </div>
                    )}

                    {/* Attached Files List */}
                    <Card className="border">
                        <CardHeader className="p-3 pb-1.5">
                            <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Attached Files ({item.files?.length || 0})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1.5 p-3 pt-1">
                            {(!item.files || item.files.length === 0) && (
                                <div className="py-2 text-center text-xs text-muted-foreground">
                                    No direct file records attached.
                                </div>
                            )}
                            {item.files?.map((f) => (
                                <div
                                    key={f.id}
                                    className="flex items-center justify-between rounded-md border bg-muted/30 p-2 text-xs"
                                >
                                    <div className="flex items-center gap-2">
                                        <FileText className="size-4 text-indigo-500" />
                                        <div>
                                            <div className="font-medium text-foreground">
                                                {f.file_name}
                                            </div>
                                            <div className="font-mono text-[10px] text-muted-foreground">
                                                {f.mime_type}{' '}
                                                {f.file_id ? `• ID: ${f.file_id}` : ''}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {f.r2_url && f.r2_url.trim() ? (
                                            <Badge
                                                variant="outline"
                                                className="border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-mono text-[10px]"
                                            >
                                                R2 Ready
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400 font-mono text-[10px] font-semibold"
                                            >
                                                Pending R2 URL
                                            </Badge>
                                        )}

                                        {f.file_url && (
                                            <a
                                                href={f.file_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="flex items-center gap-1 text-[11px] text-primary hover:underline"
                                            >
                                                <span>Drive Source</span>
                                                <ExternalLink className="size-3" />
                                            </a>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    {/* Extracted Documents View */}
                    {documents.length > 0 && (
                        <div className="space-y-2">
                            <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Extracted PO / Invoice Documents ({documents.length})
                            </div>
                            <div className="space-y-2.5">
                                {documents.map((doc) => (
                                    <Card
                                        key={`doc-${doc.fileName || doc.documentType || 'document'}`}
                                        className="border"
                                    >
                                        <CardHeader className="flex flex-row items-center justify-between space-y-0 p-3 pb-2 border-b">
                                            <CardTitle className="text-xs font-semibold">
                                                {doc.documentType || 'Document'} •{' '}
                                                {doc.fileName || 'Attachment'}
                                            </CardTitle>
                                            <Tag className="size-3.5 text-primary" />
                                        </CardHeader>
                                        <CardContent className="space-y-3 p-3 pt-2">
                                            {/* Field Badges */}
                                            {doc.fields && (
                                                <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                                                    {doc.fields.map((fld) => (
                                                        <div
                                                            key={`fld-${fld.label || fld.value || 'field'}`}
                                                            className="rounded-md border bg-muted/40 p-1.5 text-[11px]"
                                                        >
                                                            <span className="block text-[10px] font-medium text-muted-foreground">
                                                                {fld.label}
                                                            </span>
                                                            <span
                                                                className="block truncate font-semibold text-foreground"
                                                                title={fld.value}
                                                            >
                                                                {fld.value || '-'}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}

                                            {/* Line Items */}
                                            {doc.items && doc.items.length > 0 && (
                                                <div>
                                                    <span className="mb-1 block text-[10px] font-medium uppercase text-muted-foreground">
                                                        Line Items ({doc.items.length})
                                                    </span>
                                                    <div className="overflow-x-auto rounded-md border">
                                                        <table className="w-full text-left text-[11px]">
                                                            <thead className="border-b bg-muted/60 text-[10px] text-muted-foreground uppercase">
                                                                <tr>
                                                                    <th className="p-1.5">
                                                                        Description
                                                                    </th>
                                                                    <th className="p-1.5 text-center">
                                                                        Qty
                                                                    </th>
                                                                    <th className="p-1.5 text-right">
                                                                        Unit Price
                                                                    </th>
                                                                    <th className="p-1.5 text-right">
                                                                        Amount
                                                                    </th>
                                                                </tr>
                                                            </thead>
                                                            <tbody className="divide-y">
                                                                {doc.items.map((it) => (
                                                                    <tr
                                                                        key={`it-${it.itemCode || it.description || 'item'}`}
                                                                    >
                                                                        <td className="p-1.5">
                                                                            {it.description || '-'}
                                                                        </td>
                                                                        <td className="p-1.5 text-center font-mono">
                                                                            {it.quantity || '1'}
                                                                        </td>
                                                                        <td className="p-1.5 text-right font-mono text-muted-foreground">
                                                                            {it.unitPrice || '-'}
                                                                        </td>
                                                                        <td className="p-1.5 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                                                            {it.amount || '-'}
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Raw JSON viewer */}
                    {(item.extraction?.corrected_json || item.extraction?.raw_ai_json) && (
                        <Card className="border">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 p-3 pb-2 border-b">
                                <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                                    AI Extraction JSON
                                </CardTitle>
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={jsonTab === 'corrected' ? 'default' : 'ghost'}
                                        onClick={() => setJsonTab('corrected')}
                                        className="h-6 px-2 text-[10px]"
                                    >
                                        Corrected JSON
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={jsonTab === 'raw' ? 'default' : 'ghost'}
                                        onClick={() => setJsonTab('raw')}
                                        className="h-6 px-2 text-[10px]"
                                    >
                                        Raw AI JSON
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="p-3">
                                <pre className="max-h-48 overflow-auto rounded-md border bg-muted/60 p-2.5 font-mono text-[11px] text-foreground">
                                    {jsonTab === 'corrected'
                                        ? JSON.stringify(
                                              parsedCorrected || item.extraction?.corrected_json,
                                              null,
                                              2,
                                          )
                                        : JSON.stringify(
                                              parsedRaw || item.extraction?.raw_ai_json,
                                              null,
                                              2,
                                          )}
                                </pre>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <DialogFooter className="flex items-center justify-between border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                        className="text-xs"
                    >
                        Close
                    </Button>
                    {!item.is_synced_to_db && onSyncClick && (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                onSyncClick(item.serial_number);
                                onOpenChange(false);
                            }}
                            className="gap-1.5 bg-emerald-600 text-xs font-bold text-white hover:bg-emerald-500"
                        >
                            <CheckCircle2 className="size-3.5" />
                            <span>Sync SN-{item.serial_number} Now</span>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
