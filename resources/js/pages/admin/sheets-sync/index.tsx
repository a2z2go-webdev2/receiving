import { Head, Link } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock,
    Cloud,
    Eye,
    FileSpreadsheet,
    FileText,
    Layers,
    RefreshCw,
    Search,
    Settings,
    Sliders,
    Square,
    Zap,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { BatchSyncModal } from './components/batch-sync-modal';
import { RawImportModal } from './components/raw-import-modal';
import { SerialDetailsModal } from './components/serial-details-modal';
import { SheetSettingsModal } from './components/sheet-settings-modal';

interface SheetConfig {
    id: number;
    slug: string;
    name: string;
    spreadsheet_id: string | null;
    last_synced_at: string | null;
    total_serials: number;
    synced_serials: number;
    pending_serials: number;
    failed_serials: number;
}

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

interface StagedLogItem {
    id: number;
    sheet_slug: string;
    serial_number: number;
    timestamp: string | null;
    drive_folder_link: string | null;
    file_count: number;
    email_status: string | null;
    ai_status: string | null;
    review_status: string | null;
    review_token: string | null;
    reviewed_at: string | null;
    reviewed_by: string | null;
    uploader_location: string | null;
    is_synced_to_db: boolean;
    synced_receiving_upload_id: number | null;
    files?: StagedFile[];
    extraction?: StagedExtraction | null;
    synced_upload?: {
        id: number;
        submission_id: string;
        file_count: number;
        review_status: string;
        ai_status: string;
    } | null;
}

interface OverviewStats {
    total_serials: number;
    synced_serials: number;
    pending_serials: number;
    total_files: number;
    total_extractions: number;
    completion_percentage: number;
}

interface ProgressLog {
    id: number;
    serial_number: number;
    status: 'success' | 'failed';
    message: string;
    timestamp: string;
}

interface SyncProgress {
    isRunning: boolean;
    sheetSlug: string | null;
    total: number;
    current: number;
    successful: number;
    failed: number;
    currentSerial: number | null;
    percentage: number;
    statusText: string;
    startedAt: string | null;
    completedAt: string | null;
    logs: ProgressLog[];
}

interface Props {
    sheets: SheetConfig[];
    overview: OverviewStats;
    initialSheet?: string;
}

export default function SheetsSyncPage({
    sheets: initialSheets,
    overview: initialOverview,
    initialSheet = 'a2z2go',
}: Props) {
    const [sheets, setSheets] = useState<SheetConfig[]>(initialSheets);
    const [overview, setOverview] = useState<OverviewStats>(initialOverview);
    const [activeSheet, setActiveSheet] = useState<string>(initialSheet);

    const [items, setItems] = useState<StagedLogItem[]>([]);
    const [pagination, setPagination] = useState<{
        current_page: number;
        last_page: number;
        total: number;
    }>({
        current_page: 1,
        last_page: 1,
        total: 0,
    });
    const [loading, setLoading] = useState<boolean>(false);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [page, setPage] = useState<number>(1);
    const [limit, _setLimit] = useState<number>(25);

    // Sync Action states
    const [syncingSerial, setSyncingSerial] = useState<number | null>(null);
    const [refreshingApi, setRefreshingApi] = useState<boolean>(false);
    const [toastMessage, setToastMessage] = useState<{
        text: string;
        type: 'success' | 'error';
    } | null>(null);

    // Modals
    const [batchModalOpen, setBatchModalOpen] = useState<boolean>(false);
    const [rawImportOpen, setRawImportOpen] = useState<boolean>(false);
    const [settingsOpen, setSettingsOpen] = useState<boolean>(false);
    const [detailsItem, setDetailsItem] = useState<StagedLogItem | null>(null);

    // Live progress state
    const [progress, setProgress] = useState<SyncProgress | null>(null);
    const [showLogs, setShowLogs] = useState<boolean>(false);

    const showToast = (text: string, type: 'success' | 'error' = 'success') => {
        setToastMessage({ text, type });
        setTimeout(() => setToastMessage(null), 4000);
    };

    // Load items for active sheet
    const loadItems = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({
                sheet: activeSheet,
                search: searchQuery,
                status: statusFilter,
                page: String(page),
                limit: String(limit),
            });

            const res = await fetch(`/admin/sheets-sync/items?${params.toString()}`);
            const data = await res.json();
            setItems(data.items || []);
            if (data.pagination) {
                setPagination({
                    current_page: data.pagination.current_page,
                    last_page: data.pagination.last_page,
                    total: data.pagination.total,
                });
            }
        } catch {
        } finally {
            setLoading(false);
        }
    }, [activeSheet, searchQuery, statusFilter, page, limit]);

    useEffect(() => {
        loadItems();
    }, [loadItems]);

    useEffect(() => {
        setPage(1);
    }, []);

    // Poll live progress
    const pollProgress = useCallback(async () => {
        try {
            const res = await fetch('/admin/sheets-sync/progress');
            const data: SyncProgress = await res.json();
            setProgress(data);

            if (data.isRunning) {
                // Keep polling while running
            } else if (progress?.isRunning && !data.isRunning) {
                // Just completed
                await loadItems();
            }
        } catch {}
    }, [progress?.isRunning, loadItems]);

    useEffect(() => {
        pollProgress();
        const interval = setInterval(pollProgress, 1000);
        return () => clearInterval(interval);
    }, [pollProgress]);

    // Live Auto-Refresh for new Webhook uploads
    useEffect(() => {
        const liveInterval = setInterval(() => {
            if (document.visibilityState === 'visible' && !loading && !progress?.isRunning) {
                loadItems();
            }
        }, 5000);
        return () => clearInterval(liveInterval);
    }, [loading, progress?.isRunning, loadItems]);

    // Handle single serial sync
    const handleSyncSerial = async (sn: number) => {
        setSyncingSerial(sn);
        try {
            const res = await fetch(`/admin/sheets-sync/sync-serial/${activeSheet}/${sn}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                            ?.content || '',
                },
            });

            const data = await res.json();
            if (res.ok && data.success) {
                showToast(data.message, 'success');
                if (data.sheet) {
                    setSheets((prev) =>
                        prev.map((s) => (s.slug === data.sheet.slug ? data.sheet : s)),
                    );
                }
                if (data.overview) setOverview(data.overview);
                await loadItems();
            } else {
                showToast(data.error || 'Failed to sync serial number', 'error');
            }
        } catch (e: any) {
            showToast(e.message || 'Sync error', 'error');
        } finally {
            setSyncingSerial(null);
        }
    };

    // Handle refresh from Google Sheets API
    const handleRefreshSheet = async () => {
        setRefreshingApi(true);
        try {
            const res = await fetch(`/admin/sheets-sync/refresh/${activeSheet}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                            ?.content || '',
                },
            });

            const data = await res.json();
            if (res.ok && data.success) {
                showToast(data.message, 'success');
                if (data.sheet) {
                    setSheets((prev) =>
                        prev.map((s) => (s.slug === data.sheet.slug ? data.sheet : s)),
                    );
                }
                if (data.overview) setOverview(data.overview);
                await loadItems();
            } else {
                showToast(data.error || 'Failed to refresh from Google Sheets', 'error');
            }
        } catch (e: any) {
            showToast(e.message || 'Network error', 'error');
        } finally {
            setRefreshingApi(false);
        }
    };

    // Handle Start Batch Sync
    const handleStartBatchSync = async (batchConfig: {
        sheetSlug: string;
        limit?: number;
        includeSerials?: string;
        excludeSerials?: string;
        sortOrder: 'ASC' | 'DESC';
    }) => {
        try {
            const res = await fetch('/admin/sheets-sync/batch-sync', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                            ?.content || '',
                },
                body: JSON.stringify(batchConfig),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                showToast(`Batch sync started for ${data.result.total} uploads.`, 'success');
                if (data.sheet) {
                    setSheets((prev) =>
                        prev.map((s) => (s.slug === data.sheet.slug ? data.sheet : s)),
                    );
                }
                if (data.overview) setOverview(data.overview);
                await pollProgress();
                await loadItems();
            } else {
                showToast(data.error || 'Failed to launch batch sync', 'error');
            }
        } catch (e: any) {
            showToast(e.message || 'Batch sync error', 'error');
        }
    };

    // Handle Cancel Sync
    const handleCancelSync = async () => {
        try {
            await fetch('/admin/sheets-sync/cancel', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                            ?.content || '',
                },
            });
            showToast('Sync cancelled.', 'error');
            await pollProgress();
        } catch {}
    };

    const currentSheetConfig = sheets.find((s) => s.slug === activeSheet);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Dashboard', href: '/dashboard' },
                { title: 'Google Sheets Sync', href: '/admin/sheets-sync' },
            ]}
        >
            <Head title="Google Sheets Upload Sync" />

            <div className="mx-auto w-full max-w-7xl space-y-6 p-6">
                {/* Toast Notification */}
                {toastMessage && (
                    <div
                        className={`fade-in slide-in-from-top-3 flex animate-in items-center justify-between rounded-xl border p-4 font-semibold text-xs shadow-lg duration-200 ${
                            toastMessage.type === 'success'
                                ? 'border-emerald-500/40 bg-emerald-950/90 text-emerald-300'
                                : 'border-rose-500/40 bg-rose-950/90 text-rose-300'
                        }`}
                    >
                        <span>{toastMessage.text}</span>
                        <button
                            type="button"
                            onClick={() => setToastMessage(null)}
                            className="text-slate-400 hover:text-white"
                        >
                            ✕
                        </button>
                    </div>
                )}

                {/* Header Strip */}
                <div className="flex flex-col justify-between gap-4 border-sidebar-border/80 border-b pb-5 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-3.5">
                        <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 via-purple-500 to-emerald-500 shadow-indigo-500/25 shadow-lg">
                            <FileSpreadsheet className="h-6 w-6 text-white" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="font-black text-foreground text-xl tracking-tight">
                                    Google Sheets Serial Sync
                                </h1>
                                <span className="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 font-semibold text-[11px] text-emerald-400">
                                    Live Database Sync
                                </span>
                            </div>
                            <p className="mt-0.5 text-muted-foreground text-xs">
                                Ingest upload submissions, Cloudflare R2 file links, and AI
                                extraction JSONs by Serial Number.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2.5">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setSettingsOpen(true)}
                            className="flex items-center gap-1.5 font-semibold text-xs"
                        >
                            <Settings className="h-3.5 w-3.5 text-slate-400" />
                            <span>Sheet Settings</span>
                        </Button>

                        <Link
                            href="/admin/uploads"
                            className="flex items-center gap-1.5 rounded-lg bg-secondary px-3 py-1.5 font-semibold text-secondary-foreground text-xs transition hover:bg-secondary/80"
                        >
                            <Layers className="h-3.5 w-3.5" />
                            <span>View Receive Logs</span>
                        </Link>
                    </div>
                </div>

                {/* Top Metrics Strip */}
                <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-5">
                    <div className="space-y-1 rounded-2xl border border-border/80 bg-card p-4 shadow-sm">
                        <div className="flex items-center justify-between font-bold text-[11px] text-muted-foreground uppercase tracking-wider">
                            <span>Total Sheet Rows</span>
                            <FileSpreadsheet className="h-4 w-4 text-indigo-400" />
                        </div>
                        <div className="font-black font-mono text-2xl text-foreground">
                            {overview.total_serials}
                        </div>
                        <div className="text-[10px] text-muted-foreground">Across all 4 lanes</div>
                    </div>

                    <div className="space-y-1 rounded-2xl border border-border/80 bg-card p-4 shadow-sm">
                        <div className="flex items-center justify-between font-bold text-[11px] text-muted-foreground uppercase tracking-wider">
                            <span>Synced in Database</span>
                            <CheckCircle2 className="h-4 w-4 text-emerald-400" />
                        </div>
                        <div className="font-black font-mono text-2xl text-emerald-400">
                            {overview.synced_serials}
                        </div>
                        <div className="font-semibold text-[10px] text-emerald-400/80">
                            {overview.completion_percentage}% Ingested
                        </div>
                    </div>

                    <div className="space-y-1 rounded-2xl border border-border/80 bg-card p-4 shadow-sm">
                        <div className="flex items-center justify-between font-bold text-[11px] text-muted-foreground uppercase tracking-wider">
                            <span>Pending Ingestion</span>
                            <Clock className="h-4 w-4 text-amber-400" />
                        </div>
                        <div className="font-black font-mono text-2xl text-amber-400">
                            {overview.pending_serials}
                        </div>
                        <div className="text-[10px] text-muted-foreground">Awaiting sync</div>
                    </div>

                    <div className="space-y-1 rounded-2xl border border-border/80 bg-card p-4 shadow-sm">
                        <div className="flex items-center justify-between font-bold text-[11px] text-muted-foreground uppercase tracking-wider">
                            <span>Attached Files</span>
                            <Cloud className="h-4 w-4 text-sky-400" />
                        </div>
                        <div className="font-black font-mono text-2xl text-sky-400">
                            {overview.total_files}
                        </div>
                        <div className="text-[10px] text-muted-foreground">Linked in R2</div>
                    </div>

                    <div className="col-span-2 space-y-1 rounded-2xl border border-border/80 bg-card p-4 shadow-sm lg:col-span-1">
                        <div className="flex items-center justify-between font-bold text-[11px] text-muted-foreground uppercase tracking-wider">
                            <span>AI Extractions</span>
                            <Zap className="h-4 w-4 text-purple-400" />
                        </div>
                        <div className="font-black font-mono text-2xl text-purple-400">
                            {overview.total_extractions}
                        </div>
                        <div className="text-[10px] text-muted-foreground">PO & Invoice JSONs</div>
                    </div>
                </div>

                {/* Real-time Progress Banner (when syncing or active) */}
                {progress && (progress.isRunning || progress.percentage > 0) && (
                    <div className="relative space-y-3.5 overflow-hidden rounded-2xl border border-indigo-500/30 bg-gradient-to-br from-card via-indigo-950/20 to-card p-5 shadow-xl">
                        <div className="relative z-10 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                            <div className="flex items-center gap-3">
                                <div
                                    className={`flex h-9 w-9 items-center justify-center rounded-xl ${
                                        progress.isRunning
                                            ? 'bg-indigo-600 text-white shadow-indigo-600/40 shadow-lg'
                                            : progress.failed > 0
                                              ? 'bg-amber-600 text-white'
                                              : 'bg-emerald-600 text-white'
                                    }`}
                                >
                                    {progress.isRunning ? (
                                        <RefreshCw className="h-5 w-5 animate-spin" />
                                    ) : (
                                        <CheckCircle2 className="h-5 w-5" />
                                    )}
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="font-bold text-foreground text-sm">
                                            {progress.isRunning
                                                ? `Syncing ${progress.sheetSlug?.toUpperCase()} into Database`
                                                : `Batch Sync ${progress.statusText}`}
                                        </h3>
                                        <span
                                            className={`rounded-full px-2 py-0.5 font-bold text-[10px] uppercase tracking-wider ${
                                                progress.isRunning
                                                    ? 'animate-pulse border border-indigo-500/30 bg-indigo-500/20 text-indigo-300'
                                                    : 'border border-emerald-500/30 bg-emerald-500/20 text-emerald-300'
                                            }`}
                                        >
                                            {progress.isRunning ? 'In Progress' : 'Completed'}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-muted-foreground text-xs">
                                        {progress.isRunning
                                            ? `Ingesting upload ${progress.current} of ${progress.total}: SN-${progress.currentSerial || '...'}`
                                            : `${progress.successful} submissions successfully synchronized`}
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <div className="text-right">
                                    <div className="font-black font-mono text-2xl text-emerald-400">
                                        {progress.percentage}%
                                    </div>
                                    <div className="font-mono text-[10px] text-muted-foreground">
                                        {progress.current} / {progress.total} serials
                                    </div>
                                </div>

                                {progress.isRunning && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        onClick={handleCancelSync}
                                        className="h-8 font-bold text-xs"
                                    >
                                        <Square className="mr-1 h-3 w-3 fill-current" />
                                        <span>Stop</span>
                                    </Button>
                                )}
                            </div>
                        </div>

                        {/* Animated progress bar */}
                        <div className="h-2.5 w-full overflow-hidden rounded-full border border-slate-800 bg-slate-950 p-0.5">
                            <div
                                className={`h-full rounded-full transition-all duration-300 ${
                                    progress.isRunning
                                        ? 'bg-gradient-to-r from-indigo-500 via-purple-500 to-emerald-400 shadow-indigo-500/50 shadow-md'
                                        : 'bg-emerald-500'
                                }`}
                                style={{ width: `${progress.percentage}%` }}
                            />
                        </div>

                        {/* Logs collapsible */}
                        {progress.logs && progress.logs.length > 0 && (
                            <div className="pt-2">
                                <button
                                    type="button"
                                    onClick={() => setShowLogs(!showLogs)}
                                    className="flex items-center gap-1.5 font-semibold text-[11px] text-muted-foreground hover:text-foreground"
                                >
                                    <span>Sync Activity Logs ({progress.logs.length})</span>
                                    {showLogs ? (
                                        <ChevronUp className="h-3.5 w-3.5" />
                                    ) : (
                                        <ChevronDown className="h-3.5 w-3.5" />
                                    )}
                                </button>

                                {showLogs && (
                                    <div className="mt-2 max-h-48 space-y-1 overflow-y-auto rounded-xl border border-slate-800 bg-slate-950 p-3 font-mono text-[11px]">
                                        {progress.logs.map((lg) => (
                                            <div
                                                key={lg.id}
                                                className={`flex items-center justify-between ${
                                                    lg.status === 'success'
                                                        ? 'text-emerald-400/90'
                                                        : 'text-rose-400'
                                                }`}
                                            >
                                                <span>• {lg.message}</span>
                                                <span className="text-[10px] text-slate-500">
                                                    {new Date(lg.timestamp).toLocaleTimeString()}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {/* Tenant Navigation Tabs & Action Buttons */}
                <div className="flex flex-col gap-3.5 border-border border-b pb-4">
                    <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
                        {/* Sheet Tabs */}
                        <div className="flex flex-wrap items-center gap-2">
                            {sheets.map((sheet) => {
                                const isActive = activeSheet === sheet.slug;
                                return (
                                    <button
                                        key={sheet.slug}
                                        type="button"
                                        onClick={() => setActiveSheet(sheet.slug)}
                                        className={`flex items-center gap-2 rounded-xl px-3.5 py-2 font-bold text-xs transition shadow-sm ${
                                            isActive
                                                ? 'bg-primary text-primary-foreground shadow-md ring-2 ring-primary/20'
                                                : 'border border-border bg-card text-muted-foreground hover:bg-muted hover:text-foreground'
                                        }`}
                                    >
                                        <Layers className="h-3.5 w-3.5" />
                                        <span>{sheet.name}</span>
                                        <span
                                            className={`rounded-full px-2 py-0.5 font-bold text-[10px] ${
                                                isActive
                                                    ? 'bg-black/20 text-primary-foreground'
                                                    : 'border border-border/80 bg-muted text-muted-foreground'
                                            }`}
                                        >
                                            {sheet.synced_serials}/{sheet.total_serials}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Action Buttons */}
                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setRawImportOpen(true)}
                                className="border-border bg-card font-semibold text-foreground text-xs hover:bg-muted"
                                title="Directly paste or upload HTML tables or CSV files"
                            >
                                <FileText className="mr-1.5 h-3.5 w-3.5 text-indigo-500" />
                                <span>Import Table / HTML</span>
                            </Button>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={refreshingApi || loading}
                                onClick={handleRefreshSheet}
                                className="border-border bg-card font-semibold text-foreground text-xs hover:bg-muted"
                                title="Fetch latest rows from Google Sheets API"
                            >
                                <RefreshCw
                                    className={`mr-1.5 h-3.5 w-3.5 text-sky-500 ${refreshingApi ? 'animate-spin' : ''}`}
                                />
                                <span>{refreshingApi ? 'Fetching...' : 'Refresh Sheet'}</span>
                            </Button>

                            <Button
                                type="button"
                                size="sm"
                                disabled={progress?.isRunning}
                                onClick={() => setBatchModalOpen(true)}
                                className="bg-emerald-600 font-bold text-white text-xs shadow-emerald-600/20 shadow-sm hover:bg-emerald-500"
                            >
                                <Sliders className="mr-1.5 h-3.5 w-3.5" />
                                <span>
                                    Batch Sync ({currentSheetConfig?.pending_serials || 0} Pending)
                                </span>
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Filter and Search Bar */}
                <div className="flex flex-col items-center justify-between gap-4 rounded-2xl border border-border/80 bg-card p-3.5 shadow-sm sm:flex-row">
                    <div className="relative flex w-full items-center gap-2 sm:w-80">
                        <Search className="absolute left-3 h-4 w-4 text-muted-foreground" />
                        <input
                            type="text"
                            placeholder="Search Serial #, File name, Reviewer..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full rounded-xl border border-border bg-background py-1.5 pr-3 pl-9 text-foreground text-xs placeholder-muted-foreground focus:border-indigo-500 focus:outline-none"
                        />
                    </div>

                    <div className="flex w-full items-center justify-end gap-3 sm:w-auto">
                        <span className="font-semibold text-muted-foreground text-xs">Filter:</span>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="rounded-xl border border-border bg-background px-3 py-1.5 font-medium text-foreground text-xs focus:border-indigo-500 focus:outline-none"
                        >
                            <option value="all">All Uploads ({pagination.total})</option>
                            <option value="pending">Pending Database Sync</option>
                            <option value="synced">Synced in Database</option>
                            <option value="verified">Verified Only</option>
                            <option value="with_extractions">Has AI Extractions</option>
                        </select>
                    </div>
                </div>

                {/* Data Table */}
                <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-muted-foreground text-xs">
                            <thead className="border-border border-b bg-muted/60 font-bold text-[10px] text-muted-foreground uppercase tracking-wider">
                                <tr>
                                    <th className="px-4 py-3.5">Serial #</th>
                                    <th className="px-4 py-3.5">Upload Date & Reviewer</th>
                                    <th className="px-4 py-3.5">Attached Files (R2)</th>
                                    <th className="px-4 py-3.5">AI & Review Status</th>
                                    <th className="px-4 py-3.5">Database Status</th>
                                    <th className="px-4 py-3.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/60">
                                {items.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="py-12 text-center text-muted-foreground"
                                        >
                                            {loading
                                                ? 'Loading staged records...'
                                                : 'No uploads found for this filter. Click "Refresh Sheet" or "Import Table" to load rows.'}
                                        </td>
                                    </tr>
                                ) : (
                                    items.map((item) => {
                                        const isSyncing = syncingSerial === item.serial_number;
                                        const isSynced = item.is_synced_to_db;

                                        return (
                                            <tr
                                                key={item.id}
                                                className="transition hover:bg-muted/40"
                                            >
                                                {/* Serial Number */}
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    <span className="font-black font-mono text-indigo-400 text-sm">
                                                        SN-{item.serial_number}
                                                    </span>
                                                </td>

                                                {/* Timestamp & Reviewer */}
                                                <td className="max-w-xs px-4 py-3">
                                                    <div className="font-medium text-foreground">
                                                        {item.timestamp || 'N/A'}
                                                    </div>
                                                    <div
                                                        className="truncate text-[11px] text-muted-foreground"
                                                        title={item.reviewed_by || ''}
                                                    >
                                                        {item.reviewed_by || 'No reviewer assigned'}
                                                    </div>
                                                    {item.uploader_location && (
                                                        <div className="truncate font-mono text-[10px] text-slate-500">
                                                            📍 {item.uploader_location}
                                                        </div>
                                                    )}
                                                </td>

                                                {/* Files */}
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="font-semibold text-foreground">
                                                            {item.files?.length ||
                                                                item.file_count ||
                                                                1}{' '}
                                                            file(s)
                                                        </span>
                                                        {item.files?.some((f) => f.r2_url) && (
                                                            <span className="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.2 font-semibold text-[10px] text-emerald-400">
                                                                In R2
                                                            </span>
                                                        )}
                                                    </div>
                                                    {item.files && item.files.length > 0 && (
                                                        <div className="mt-0.5 max-w-xs truncate font-mono text-[10px] text-muted-foreground">
                                                            {item.files[0].file_name}
                                                            {item.files.length > 1
                                                                ? ` +${item.files.length - 1} more`
                                                                : ''}
                                                        </div>
                                                    )}
                                                </td>

                                                {/* AI & Review Status */}
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    <div className="flex items-center gap-1.5">
                                                        <span
                                                            className={`rounded-md px-2 py-0.5 font-bold text-[10px] uppercase ${
                                                                item.review_status?.toLowerCase() ===
                                                                'verified'
                                                                    ? 'border border-emerald-500/20 bg-emerald-500/10 text-emerald-400'
                                                                    : item.review_status?.toLowerCase() ===
                                                                        'rejected'
                                                                      ? 'border border-rose-500/20 bg-rose-500/10 text-rose-400'
                                                                      : 'border border-amber-500/20 bg-amber-500/10 text-amber-400'
                                                            }`}
                                                        >
                                                            {item.review_status || 'Pending Review'}
                                                        </span>
                                                        <span className="rounded bg-muted px-1.5 py-0.5 font-semibold text-[10px] text-muted-foreground">
                                                            AI: {item.ai_status || 'N/A'}
                                                        </span>
                                                    </div>
                                                </td>

                                                {/* Database Status */}
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    {isSynced ? (
                                                        <div className="inline-flex items-center gap-1 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 font-semibold text-[11px] text-emerald-400">
                                                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                                                            <span>
                                                                Synced (ID #
                                                                {item.synced_receiving_upload_id})
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-slate-800 px-2.5 py-1 font-semibold text-[11px] text-slate-400">
                                                            <Clock className="h-3 w-3 text-slate-500" />
                                                            <span>Pending DB Sync</span>
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Actions */}
                                                <td className="whitespace-nowrap px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="secondary"
                                                            size="sm"
                                                            onClick={() => setDetailsItem(item)}
                                                            className="h-7 px-2.5 text-xs"
                                                            title="Inspect row metadata & AI JSON"
                                                        >
                                                            <Eye className="mr-1 h-3.5 w-3.5" />
                                                            <span>Details</span>
                                                        </Button>

                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            disabled={isSyncing || isSynced}
                                                            onClick={() =>
                                                                handleSyncSerial(item.serial_number)
                                                            }
                                                            className={`h-7 px-3 font-bold text-xs ${
                                                                isSynced
                                                                    ? 'cursor-default bg-muted text-muted-foreground'
                                                                    : 'bg-indigo-600 text-white shadow-indigo-600/30 shadow-md hover:bg-indigo-500'
                                                            }`}
                                                        >
                                                            {isSyncing ? (
                                                                <>
                                                                    <RefreshCw className="mr-1 h-3 w-3 animate-spin" />
                                                                    <span>Syncing...</span>
                                                                </>
                                                            ) : isSynced ? (
                                                                <>
                                                                    <Check className="mr-1 h-3 w-3" />
                                                                    <span>Synced</span>
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <Zap className="mr-1 h-3 w-3" />
                                                                    <span>
                                                                        Sync SN-{item.serial_number}
                                                                    </span>
                                                                </>
                                                            )}
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination Bar */}
                    {pagination.total > 0 && (
                        <div className="flex items-center justify-between border-border border-t p-4 text-muted-foreground text-xs">
                            <div>
                                Page{' '}
                                <strong className="text-foreground">
                                    {pagination.current_page}
                                </strong>{' '}
                                of{' '}
                                <strong className="text-foreground">{pagination.last_page}</strong>{' '}
                                ({pagination.total} total rows)
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.current_page <= 1}
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    className="h-8 text-xs"
                                >
                                    Previous
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.current_page >= pagination.last_page}
                                    onClick={() => setPage((p) => p + 1)}
                                    className="h-8 text-xs"
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Batch Sync Modal */}
            <BatchSyncModal
                open={batchModalOpen}
                onOpenChange={setBatchModalOpen}
                sheetSlug={activeSheet}
                sheetName={currentSheetConfig?.name || activeSheet.toUpperCase()}
                onStartBatchSync={handleStartBatchSync}
            />

            {/* Raw Import Modal */}
            <RawImportModal
                open={rawImportOpen}
                onOpenChange={setRawImportOpen}
                sheetSlug={activeSheet}
                sheetName={currentSheetConfig?.name || activeSheet.toUpperCase()}
                onImportSuccess={(msg) => {
                    showToast(msg, 'success');
                    loadItems();
                }}
            />

            {/* Sheet Settings Modal */}
            <SheetSettingsModal
                open={settingsOpen}
                onOpenChange={setSettingsOpen}
                sheets={sheets}
                onSaved={(updated) => {
                    setSheets((prev) => prev.map((s) => (s.slug === updated.slug ? updated : s)));
                }}
            />

            {/* Serial Details Modal */}
            <SerialDetailsModal
                open={!!detailsItem}
                onOpenChange={(op) => !op && setDetailsItem(null)}
                item={detailsItem}
                onSyncClick={(sn) => handleSyncSerial(sn)}
            />
        </AppLayout>
    );
}
