import { ArrowUpDown, Sliders, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface BatchSyncModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sheetSlug: string;
    sheetName: string;
    onStartBatchSync: (config: {
        sheetSlug: string;
        limit?: number;
        includeSerials?: string;
        excludeSerials?: string;
        sortOrder: 'ASC' | 'DESC';
    }) => void;
}

export function BatchSyncModal({
    open,
    onOpenChange,
    sheetSlug,
    sheetName,
    onStartBatchSync,
}: BatchSyncModalProps) {
    const [limitPreset, setLimitPreset] = useState<'50' | '100' | '200' | '500' | 'all' | 'custom'>(
        '100',
    );
    const [customLimit, setCustomLimit] = useState<string>('200');
    const [includeSerials, setIncludeSerials] = useState<string>('');
    const [excludeSerials, setExcludeSerials] = useState<string>('');
    const [sortOrder, setSortOrder] = useState<'ASC' | 'DESC'>('ASC');

    const [previewLoading, setPreviewLoading] = useState<boolean>(false);
    const [preview, setPreview] = useState<{
        matchedCount: number;
        totalPendingCount: number;
        excludedCount: number;
        sampleSerials: number[];
    } | null>(null);

    // Fetch batch preview
    useEffect(() => {
        if (!open) return;

        let active = true;
        setPreviewLoading(true);

        const limitVal =
            limitPreset === 'all'
                ? undefined
                : limitPreset === 'custom'
                  ? parseInt(customLimit, 10) || undefined
                  : parseInt(limitPreset, 10);

        fetch('/admin/sheets-sync/batch-preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                        ?.content || '',
            },
            body: JSON.stringify({
                sheetSlug,
                limit: limitVal,
                includeSerials,
                excludeSerials,
                sortOrder,
            }),
        })
            .then((res) => res.json())
            .then((data) => {
                if (active) setPreview(data);
            })
            .catch(() => {
                if (active) setPreview(null);
            })
            .finally(() => {
                if (active) setPreviewLoading(false);
            });

        return () => {
            active = false;
        };
    }, [open, sheetSlug, limitPreset, customLimit, includeSerials, excludeSerials, sortOrder]);

    const handleStart = () => {
        const limitVal =
            limitPreset === 'all'
                ? undefined
                : limitPreset === 'custom'
                  ? parseInt(customLimit, 10) || undefined
                  : parseInt(limitPreset, 10);

        onStartBatchSync({
            sheetSlug,
            limit: limitVal,
            includeSerials,
            excludeSerials,
            sortOrder,
        });
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-2xl">
                <DialogHeader className="border-slate-800 border-b pb-3">
                    <div className="flex items-center gap-2">
                        <Sliders className="h-5 w-5 text-emerald-400" />
                        <DialogTitle className="font-bold text-lg text-white">
                            Configure Batch Sync: {sheetName}
                        </DialogTitle>
                    </div>
                    <DialogDescription className="text-slate-400 text-xs">
                        Select specific ranges, exclusions, or priorities to ingest Google Sheet
                        uploads into the database.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {/* 1. Limit Preset */}
                    <div>
                        <span className="mb-1.5 block font-bold text-slate-400 text-xs uppercase tracking-wider">
                            1. Select Ingestion Batch Limit
                        </span>
                        <div className="grid grid-cols-6 gap-2">
                            {(['50', '100', '200', '500', 'all', 'custom'] as const).map(
                                (preset) => (
                                    <button
                                        key={preset}
                                        type="button"
                                        onClick={() => setLimitPreset(preset)}
                                        className={`rounded-lg border px-2 py-1.5 font-semibold text-xs capitalize transition ${
                                            limitPreset === preset
                                                ? 'border-indigo-500 bg-indigo-600 text-white shadow-indigo-600/30 shadow-md'
                                                : 'border-slate-800 bg-slate-950 text-slate-400 hover:text-slate-200'
                                        }`}
                                    >
                                        {preset === 'all'
                                            ? 'All Rows'
                                            : preset === 'custom'
                                              ? 'Custom'
                                              : `${preset}`}
                                    </button>
                                ),
                            )}
                        </div>
                        {limitPreset === 'custom' && (
                            <input
                                type="number"
                                min="1"
                                placeholder="Enter custom row limit..."
                                value={customLimit}
                                onChange={(e) => setCustomLimit(e.target.value)}
                                className="mt-2 w-full rounded-lg border border-slate-800 bg-slate-950 p-2 font-mono text-slate-200 text-xs focus:border-indigo-500 focus:outline-none"
                            />
                        )}
                    </div>

                    {/* 2. Specific Range */}
                    <div>
                        <span className="mb-1 block font-bold text-slate-400 text-xs uppercase tracking-wider">
                            2. Only Include Specific Serial Numbers (Optional)
                        </span>
                        <input
                            type="text"
                            placeholder="e.g. 1-50 or 100-200 (Leave empty to include all pending)"
                            value={includeSerials}
                            onChange={(e) => setIncludeSerials(e.target.value)}
                            className="w-full rounded-lg border border-slate-800 bg-slate-950 p-2.5 font-mono text-slate-200 text-xs placeholder-slate-600 focus:border-indigo-500 focus:outline-none"
                        />
                    </div>

                    {/* 3. Excluded Serials */}
                    <div>
                        <span className="mb-1 block font-bold text-slate-400 text-xs uppercase tracking-wider">
                            3. Exclude Specific Serial Numbers (Optional)
                        </span>
                        <input
                            type="text"
                            placeholder="e.g. 4, 12, 18-20"
                            value={excludeSerials}
                            onChange={(e) => setExcludeSerials(e.target.value)}
                            className="w-full rounded-lg border border-slate-800 bg-slate-950 p-2.5 font-mono text-slate-200 text-xs placeholder-slate-600 focus:border-indigo-500 focus:outline-none"
                        />
                    </div>

                    {/* 4. Priority Sort Order */}
                    <div>
                        <span className="mb-1.5 block font-bold text-slate-400 text-xs uppercase tracking-wider">
                            4. Ingestion Priority Order
                        </span>
                        <div className="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onClick={() => setSortOrder('ASC')}
                                className={`flex items-center justify-center gap-2 rounded-lg border p-2 font-semibold text-xs transition ${
                                    sortOrder === 'ASC'
                                        ? 'border-indigo-500 bg-indigo-600/30 text-indigo-200'
                                        : 'border-slate-800 bg-slate-950 text-slate-400 hover:text-slate-300'
                                }`}
                            >
                                <ArrowUpDown className="h-3.5 w-3.5" />
                                <span>Oldest First (SN 1 → N)</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setSortOrder('DESC')}
                                className={`flex items-center justify-center gap-2 rounded-lg border p-2 font-semibold text-xs transition ${
                                    sortOrder === 'DESC'
                                        ? 'border-indigo-500 bg-indigo-600/30 text-indigo-200'
                                        : 'border-slate-800 bg-slate-950 text-slate-400 hover:text-slate-300'
                                }`}
                            >
                                <ArrowUpDown className="h-3.5 w-3.5 rotate-180" />
                                <span>Newest First (Highest SN)</span>
                            </button>
                        </div>
                    </div>

                    {/* Live Preview Summary Box */}
                    <div className="flex items-center justify-between rounded-xl border border-slate-800 bg-slate-950/80 p-3.5">
                        <div>
                            <div className="font-medium text-slate-400 text-xs">
                                Batch Preview Summary
                            </div>
                            <div className="mt-0.5 font-bold text-sm text-white">
                                {previewLoading ? (
                                    <span className="animate-pulse text-slate-500">
                                        Calculating matches...
                                    </span>
                                ) : (
                                    <>
                                        Ready to sync{' '}
                                        <span className="font-black font-mono text-emerald-400">
                                            {preview?.matchedCount || 0}
                                        </span>{' '}
                                        uploads
                                    </>
                                )}
                            </div>
                            <div className="mt-0.5 text-[10px] text-slate-500">
                                {preview
                                    ? `Total Pending: ${preview.totalPendingCount} | Excluded: ${preview.excludedCount}`
                                    : ''}
                            </div>
                        </div>

                        <div className="text-right">
                            <span className="rounded-full border border-indigo-500/20 bg-indigo-500/10 px-2.5 py-1 font-mono text-[11px] text-indigo-400">
                                Scope: {sheetName}
                            </span>
                        </div>
                    </div>
                </div>

                <DialogFooter className="border-slate-800 border-t pt-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                        className="bg-slate-800 text-slate-300 text-xs hover:bg-slate-700"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={previewLoading || !preview || preview.matchedCount === 0}
                        onClick={handleStart}
                        className="flex items-center gap-1.5 bg-gradient-to-r from-emerald-600 to-teal-600 font-bold text-white text-xs shadow-emerald-600/30 shadow-lg hover:from-emerald-500 hover:to-teal-500"
                    >
                        <Zap className="h-4 w-4" />
                        <span>Start Sync ({preview?.matchedCount || 0} Uploads)</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
