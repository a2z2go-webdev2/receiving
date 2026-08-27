import { CheckCircle2, ExternalLink, Eye, FileText, MapPin, Tag } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
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

export function SerialDetailsModal({
    open,
    onOpenChange,
    item,
    onSyncClick,
}: SerialDetailsModalProps) {
    const [jsonTab, setJsonTab] = useState<'corrected' | 'raw'>('corrected');

    if (!item) return null;

    let parsedCorrected: any = null;
    let parsedRaw: any = null;

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

    const documents = parsedCorrected?.documents || parsedRaw?.documents || [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-4xl overflow-y-auto border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-2xl">
                <DialogHeader className="border-slate-800 border-b pb-3">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Eye className="h-5 w-5 text-indigo-400" />
                            <DialogTitle className="font-bold text-lg text-white">
                                Serial #{item.serial_number} Details (
                                {item.sheet_slug.toUpperCase()})
                            </DialogTitle>
                        </div>
                        <span
                            className={`rounded-full px-2.5 py-1 font-bold text-xs uppercase tracking-wider ${
                                item.is_synced_to_db
                                    ? 'border border-emerald-500/20 bg-emerald-500/10 text-emerald-400'
                                    : 'border border-amber-500/20 bg-amber-500/10 text-amber-400'
                            }`}
                        >
                            {item.is_synced_to_db
                                ? `Synced in DB (Upload #${item.synced_receiving_upload_id})`
                                : 'Pending Database Sync'}
                        </span>
                    </div>
                    <DialogDescription className="text-slate-400 text-xs">
                        Uploaded on: {item.timestamp || 'N/A'} • Reviewed by:{' '}
                        {item.reviewed_by || 'N/A'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-5 py-3">
                    {/* Metadata Overview Grid */}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div className="rounded-xl border border-slate-800 bg-slate-950 p-3">
                            <span className="font-bold text-[10px] text-slate-500 uppercase">
                                Review Status
                            </span>
                            <div className="mt-0.5 font-semibold text-white text-xs capitalize">
                                {item.review_status || 'Pending'}
                            </div>
                        </div>
                        <div className="rounded-xl border border-slate-800 bg-slate-950 p-3">
                            <span className="font-bold text-[10px] text-slate-500 uppercase">
                                AI Status
                            </span>
                            <div className="mt-0.5 font-semibold text-indigo-300 text-xs capitalize">
                                {item.ai_status || 'Pending'}
                            </div>
                        </div>
                        <div className="rounded-xl border border-slate-800 bg-slate-950 p-3">
                            <span className="font-bold text-[10px] text-slate-500 uppercase">
                                Email Status
                            </span>
                            <div className="mt-0.5 font-semibold text-slate-300 text-xs capitalize">
                                {item.email_status || 'Pending'}
                            </div>
                        </div>
                        <div className="rounded-xl border border-slate-800 bg-slate-950 p-3">
                            <span className="font-bold text-[10px] text-slate-500 uppercase">
                                Files Count
                            </span>
                            <div className="mt-0.5 font-semibold text-emerald-400 text-xs">
                                {item.files?.length || item.file_count || 1} Attached
                            </div>
                        </div>
                    </div>

                    {/* Geolocation if present */}
                    {item.uploader_location && (
                        <div className="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950 p-3 text-slate-300 text-xs">
                            <MapPin className="h-4 w-4 text-rose-400" />
                            <span>
                                Location Coordinates:{' '}
                                <strong className="font-mono text-white">
                                    {item.uploader_location}
                                </strong>
                            </span>
                        </div>
                    )}

                    {/* Attached Files List */}
                    <div>
                        <h4 className="mb-2 font-bold text-slate-400 text-xs uppercase tracking-wider">
                            Attached Files ({item.files?.length || 0})
                        </h4>
                        <div className="space-y-2">
                            {item.files?.map((f) => (
                                <div
                                    key={f.id}
                                    className="flex items-center justify-between rounded-xl border border-slate-800 bg-slate-950/90 p-3 text-xs"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <FileText className="h-4 w-4 text-indigo-400" />
                                        <div>
                                            <div className="font-semibold text-white">
                                                {f.file_name}
                                            </div>
                                            <div className="font-mono text-[10px] text-slate-500">
                                                MIME: {f.mime_type}{' '}
                                                {f.file_id ? `• ID: ${f.file_id}` : ''}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        {f.r2_url ? (
                                            <span className="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 font-semibold text-[10px] text-emerald-400">
                                                In Cloudflare R2
                                            </span>
                                        ) : (
                                            <span className="rounded-full bg-slate-800 px-2 py-0.5 text-[10px] text-slate-400">
                                                No R2 URL
                                            </span>
                                        )}

                                        {f.file_url && (
                                            <a
                                                href={f.file_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="flex items-center gap-1 text-[11px] text-sky-400 hover:text-sky-300"
                                            >
                                                <span>Drive Source</span>
                                                <ExternalLink className="h-3 w-3" />
                                            </a>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Extracted Documents View */}
                    {documents.length > 0 && (
                        <div>
                            <h4 className="mb-2 font-bold text-slate-400 text-xs uppercase tracking-wider">
                                Extracted Documents ({documents.length})
                            </h4>
                            <div className="space-y-3">
                                {documents.map((doc: any) => (
                                    <div
                                        key={`doc-${doc.fileName || doc.documentType || 'document'}`}
                                        className="space-y-3 rounded-xl border border-slate-800 bg-slate-950 p-4"
                                    >
                                        <div className="flex items-center justify-between border-slate-800/80 border-b pb-2">
                                            <span className="font-bold text-white text-xs uppercase tracking-wider">
                                                {doc.documentType || 'Invoice / Receipt'} •{' '}
                                                {doc.fileName || 'Document'}
                                            </span>
                                            <Tag className="h-3.5 w-3.5 text-indigo-400" />
                                        </div>

                                        {/* Field Badges */}
                                        {doc.fields && (
                                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                                {doc.fields.map((fld: any) => (
                                                    <div
                                                        key={`fld-${fld.label || fld.value || 'field'}`}
                                                        className="rounded-lg bg-slate-900/80 p-2 text-[11px]"
                                                    >
                                                        <span className="block font-medium text-[10px] text-slate-500">
                                                            {fld.label}
                                                        </span>
                                                        <span
                                                            className="block truncate font-semibold text-slate-200"
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
                                                <span className="mb-1.5 block font-bold text-[10px] text-slate-400 uppercase tracking-wider">
                                                    Line Items ({doc.items.length})
                                                </span>
                                                <div className="overflow-x-auto">
                                                    <table className="w-full text-left text-[11px] text-slate-300">
                                                        <thead className="bg-slate-900 text-[10px] text-slate-400 uppercase">
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
                                                        <tbody className="divide-y divide-slate-800">
                                                            {doc.items.map((it: any) => (
                                                                <tr
                                                                    key={`it-${it.itemCode || it.description || 'item'}`}
                                                                >
                                                                    <td className="p-1.5 text-slate-200">
                                                                        {it.description || '-'}
                                                                    </td>
                                                                    <td className="p-1.5 text-center font-mono">
                                                                        {it.quantity || '1'}
                                                                    </td>
                                                                    <td className="p-1.5 text-right font-mono text-slate-400">
                                                                        {it.unitPrice || '-'}
                                                                    </td>
                                                                    <td className="p-1.5 text-right font-mono font-semibold text-emerald-400">
                                                                        {it.amount || '-'}
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Raw JSON viewer */}
                    {(item.extraction?.corrected_json || item.extraction?.raw_ai_json) && (
                        <div>
                            <div className="mb-2 flex items-center justify-between">
                                <h4 className="font-bold text-slate-400 text-xs uppercase tracking-wider">
                                    Extraction Payload JSON
                                </h4>
                                <div className="flex items-center gap-1 rounded-lg border border-slate-800 bg-slate-950 p-0.5">
                                    <button
                                        type="button"
                                        onClick={() => setJsonTab('corrected')}
                                        className={`rounded px-2 py-0.5 font-bold text-[10px] ${
                                            jsonTab === 'corrected'
                                                ? 'bg-indigo-600 text-white'
                                                : 'text-slate-400 hover:text-white'
                                        }`}
                                    >
                                        Corrected JSON
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setJsonTab('raw')}
                                        className={`rounded px-2 py-0.5 font-bold text-[10px] ${
                                            jsonTab === 'raw'
                                                ? 'bg-indigo-600 text-white'
                                                : 'text-slate-400 hover:text-white'
                                        }`}
                                    >
                                        Raw AI JSON
                                    </button>
                                </div>
                            </div>

                            <pre className="max-h-60 overflow-x-auto rounded-xl border border-slate-800 bg-slate-950 p-3 font-mono text-[11px] text-emerald-400/90">
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
                        </div>
                    )}
                </div>

                <DialogFooter className="flex items-center justify-between border-slate-800 border-t pt-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                        className="bg-slate-800 text-slate-300 text-xs hover:bg-slate-700"
                    >
                        Close
                    </Button>
                    {!item.is_synced_to_db && onSyncClick && (
                        <Button
                            type="button"
                            onClick={() => {
                                onSyncClick(item.serial_number);
                                onOpenChange(false);
                            }}
                            className="bg-gradient-to-r from-emerald-600 to-teal-600 font-bold text-white text-xs shadow-emerald-600/30 shadow-lg hover:from-emerald-500 hover:to-teal-500"
                        >
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            <span>Sync SN-{item.serial_number} Now</span>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
