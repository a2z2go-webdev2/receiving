import { ArrowUpDown, Sliders, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
            <DialogContent className="max-w-lg bg-card text-foreground">
                <DialogHeader className="border-b pb-3">
                    <div className="flex items-center gap-2">
                        <div className="flex size-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <Sliders className="size-4" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-semibold">
                                Configure Batch Sync: {sheetName}
                            </DialogTitle>
                            <DialogDescription className="text-xs">
                                Ingest pending Google Sheet submissions into the database with
                                custom filters.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {/* 1. Limit Preset */}
                    <div className="space-y-1.5">
                        <Label className="text-xs">Batch Row Limit</Label>
                        <div className="grid grid-cols-6 gap-1.5">
                            {(['50', '100', '200', '500', 'all', 'custom'] as const).map(
                                (preset) => (
                                    <Button
                                        key={preset}
                                        type="button"
                                        size="sm"
                                        variant={limitPreset === preset ? 'default' : 'outline'}
                                        onClick={() => setLimitPreset(preset)}
                                        className="h-8 text-xs font-semibold"
                                    >
                                        {preset === 'all'
                                            ? 'All'
                                            : preset === 'custom'
                                              ? 'Custom'
                                              : preset}
                                    </Button>
                                ),
                            )}
                        </div>
                        {limitPreset === 'custom' && (
                            <Input
                                type="number"
                                min="1"
                                placeholder="Enter custom row limit..."
                                value={customLimit}
                                onChange={(e) => setCustomLimit(e.target.value)}
                                className="mt-2 font-mono text-xs"
                            />
                        )}
                    </div>

                    {/* 2. Specific Range */}
                    <div className="space-y-1.5">
                        <Label htmlFor="batch-include-input" className="text-xs">
                            Include Specific Serial Numbers (Optional)
                        </Label>
                        <Input
                            id="batch-include-input"
                            type="text"
                            placeholder="e.g. 1-50 or 100-200 (Leave empty to include all pending)"
                            value={includeSerials}
                            onChange={(e) => setIncludeSerials(e.target.value)}
                            className="font-mono text-xs"
                        />
                    </div>

                    {/* 3. Excluded Serials */}
                    <div className="space-y-1.5">
                        <Label htmlFor="batch-exclude-input" className="text-xs">
                            Exclude Specific Serial Numbers (Optional)
                        </Label>
                        <Input
                            id="batch-exclude-input"
                            type="text"
                            placeholder="e.g. 4, 12, 18-20"
                            value={excludeSerials}
                            onChange={(e) => setExcludeSerials(e.target.value)}
                            className="font-mono text-xs"
                        />
                    </div>

                    {/* 4. Priority Sort Order */}
                    <div className="space-y-1.5">
                        <Label className="text-xs">Priority Order</Label>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant={sortOrder === 'ASC' ? 'default' : 'outline'}
                                onClick={() => setSortOrder('ASC')}
                                className="h-8 justify-center gap-1.5 text-xs font-semibold"
                            >
                                <ArrowUpDown className="size-3.5" />
                                <span>Oldest First (SN 1 &rarr; N)</span>
                            </Button>

                            <Button
                                type="button"
                                size="sm"
                                variant={sortOrder === 'DESC' ? 'default' : 'outline'}
                                onClick={() => setSortOrder('DESC')}
                                className="h-8 justify-center gap-1.5 text-xs font-semibold"
                            >
                                <ArrowUpDown className="size-3.5 rotate-180" />
                                <span>Newest First (Highest SN)</span>
                            </Button>
                        </div>
                    </div>

                    {/* Live Preview Card */}
                    <Card className="border bg-muted/40">
                        <CardContent className="flex items-center justify-between p-3.5">
                            <div>
                                <div className="text-[11px] font-medium text-muted-foreground">
                                    Batch Preview Summary
                                </div>
                                <div className="text-sm font-semibold">
                                    {previewLoading ? (
                                        <span className="animate-pulse text-muted-foreground">
                                            Calculating matches...
                                        </span>
                                    ) : (
                                        <>
                                            Ready to ingest{' '}
                                            <span className="font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                                {preview?.matchedCount || 0}
                                            </span>{' '}
                                            submissions
                                        </>
                                    )}
                                </div>
                                {preview && (
                                    <div className="mt-0.5 font-mono text-[10px] text-muted-foreground">
                                        Total Pending: {preview.totalPendingCount} | Excluded:{' '}
                                        {preview.excludedCount}
                                    </div>
                                )}
                            </div>

                            <Badge variant="outline" className="font-mono text-xs">
                                {sheetName}
                            </Badge>
                        </CardContent>
                    </Card>
                </div>

                <DialogFooter className="border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                        className="text-xs"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        disabled={previewLoading || !preview || preview.matchedCount === 0}
                        onClick={handleStart}
                        className="gap-1.5 bg-emerald-600 text-xs font-bold text-white hover:bg-emerald-500"
                    >
                        <Zap className="size-3.5" />
                        <span>Start Batch Sync ({preview?.matchedCount || 0})</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
