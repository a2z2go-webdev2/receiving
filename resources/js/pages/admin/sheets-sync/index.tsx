import { Head, Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    Cloud,
    ExternalLink,
    Eye,
    FileSpreadsheet,
    FileText,
    Inbox,
    Layers,
    RefreshCw,
    Search,
    Settings,
    Sliders,
    Sparkles,
    Square,
    Zap,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { EmptyState } from '@/components/receiving/empty-state';
import { PageShell } from '@/components/receiving/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { BatchSyncModal } from './components/batch-sync-modal';
import { RawImportModal } from './components/raw-import-modal';
import { SerialDetailsModal } from './components/serial-details-modal';
import { SheetSettingsModal } from './components/sheet-settings-modal';

interface SheetConfig {
    id: number;
    slug: string;
    name: string;
    spreadsheet_id: string | null;
    webhook_secret?: string | null;
    auto_sync_on_webhook?: boolean;
    last_synced_at: string | null;
    total_serials: number;
    synced_serials: number;
    pending_serials: number;
    failed_serials: number;
}

interface StagedFile {
    id: number;
    serial_number: number;
    file_id: string | null;
    file_name: string;
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
    reviewed_at: string | null;
    reviewed_by: string | null;
    uploader_location: string | null;
    is_synced_to_db: boolean;
    synced_receiving_upload_id: number | null;
    synced_at?: string | null;
    updated_at?: string | null;
    has_update_available?: boolean;
    files: StagedFile[];
    extraction: StagedExtraction | null;
    synced_upload?: {
        id: number;
        submission_id: string;
        file_count: number;
        review_status: string;
        ai_status: string;
        created_at: string;
    } | null;
}

interface SyncOverview {
    total_serials: number;
    synced_serials: number;
    pending_serials: number;
    failed_serials: number;
    total_files: number;
    files_pending_r2?: number;
    files_synced_r2?: number;
    total_extractions: number;
    completion_percentage: number;
}

interface SyncProgress {
    jobId?: number;
    sheetSlug?: string;
    status: 'idle' | 'running' | 'completed' | 'failed' | 'cancelled';
    isRunning: boolean;
    total: number;
    current: number;
    successful: number;
    failed: number;
    currentSerial: number | null;
    percentage: number;
    statusText: string;
    startedAt?: string;
    completedAt?: string;
    logs: Array<{
        id: string;
        timestamp: string;
        message: string;
        status: 'info' | 'success' | 'warning' | 'error';
    }>;
}

interface Props {
    sheets: SheetConfig[];
    overview: SyncOverview;
    initialSheet: string;
}

export default function SheetsSyncPage({
    sheets: initialSheets,
    overview: initialOverview,
    initialSheet,
}: Props) {
    const [sheets, setSheets] = useState<SheetConfig[]>(initialSheets);
    const [overview, setOverview] = useState<SyncOverview>(initialOverview);
    const [activeSheet, setActiveSheet] = useState<string>(initialSheet || 'a2z2go');

    const [items, setItems] = useState<StagedLogItem[]>([]);
    const [loading, setLoading] = useState<boolean>(true);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [sortBy, setSortBy] = useState<string>('priority');
    const [page, setPage] = useState<number>(1);
    const [pagination, setPagination] = useState({
        total: 0,
        per_page: 25,
        current_page: 1,
        last_page: 1,
    });

    const [progress, setProgress] = useState<SyncProgress | null>(null);
    const [toastMessage, setToastMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    // Modals state
    const [settingsOpen, setSettingsOpen] = useState<boolean>(false);
    const [batchModalOpen, setBatchModalOpen] = useState<boolean>(false);
    const [rawImportOpen, setRawImportOpen] = useState<boolean>(false);
    const [detailsItem, setDetailsItem] = useState<StagedLogItem | null>(null);
    const [syncingSerial, setSyncingSerial] = useState<number | null>(null);
    const [refreshingApi, setRefreshingApi] = useState<boolean>(false);

    const showToast = (text: string, type: 'success' | 'error' = 'success') => {
        setToastMessage({ text, type });
        setTimeout(() => setToastMessage(null), 4500);
    };

    const loadItems = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({
                sheet: activeSheet,
                search: searchQuery,
                status: statusFilter,
                sort: sortBy,
                page: String(page),
                limit: '25',
            });

            const res = await fetch(`/admin/sheets-sync/items?${params.toString()}`);
            if (!res.ok) {
                const errorData = await res.json().catch(() => ({}));
                console.error('Failed to load sheet items:', errorData);
                setItems([]);
                return;
            }
            const data = await res.json();

            setItems(data.items || []);
            setPagination(
                data.pagination || {
                    total: 0,
                    per_page: 25,
                    current_page: 1,
                    last_page: 1,
                },
            );
        } catch (err) {
            console.error('Error fetching sheet items:', err);
            setItems([]);
        } finally {
            setLoading(false);
        }
    }, [activeSheet, searchQuery, statusFilter, sortBy, page]);

    useEffect(() => {
        loadItems();
    }, [loadItems]);

    const pollProgress = useCallback(async () => {
        try {
            const res = await fetch('/admin/sheets-sync/progress');
            const data = await res.json();
            setProgress(data);

            if (data.overview) {
                setOverview(data.overview);
            }
            if (data.sheets) {
                setSheets(data.sheets);
            }
        } catch {}
    }, []);

    useEffect(() => {
        pollProgress();
        const timer = setInterval(() => {
            pollProgress();
        }, 5000);

        return () => clearInterval(timer);
    }, [pollProgress]);

    const handleSyncSerial = async (serialNumber: number) => {
        setSyncingSerial(serialNumber);
        try {
            const res = await fetch(
                `/admin/sheets-sync/sync-serial/${activeSheet}/${serialNumber}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                                ?.content || '',
                    },
                },
            );

            const data = await res.json();
            if (res.ok && data.success) {
                showToast(data.message, 'success');
                loadItems();
                pollProgress();
            } else {
                showToast(data.error || 'Failed to sync serial number', 'error');
            }
        } catch (e) {
            showToast(e instanceof Error ? e.message : 'Sync error', 'error');
        } finally {
            setSyncingSerial(null);
        }
    };

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
                loadItems();
                pollProgress();
            } else {
                showToast(data.error || 'Failed to refresh from Google Sheets', 'error');
            }
        } catch (e) {
            showToast(e instanceof Error ? e.message : 'Network error', 'error');
        } finally {
            setRefreshingApi(false);
        }
    };

    const handleStartBatchSync = async (config: {
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
                body: JSON.stringify(config),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                showToast(data.message, 'success');
                await pollProgress();
            } else {
                showToast(data.error || 'Failed to launch batch sync', 'error');
            }
        } catch (e) {
            showToast(e instanceof Error ? e.message : 'Batch sync error', 'error');
        }
    };

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

    const stats = [
        ['Total Sheet Rows', overview.total_serials, FileSpreadsheet, 'Across all 4 lanes'],
        [
            'Synced in Database',
            overview.synced_serials,
            CheckCircle2,
            `${overview.completion_percentage}% Ingested`,
        ],
        ['Pending Ingestion', overview.pending_serials, Clock, 'Awaiting sync'],
        [
            'Attached Files',
            overview.total_files,
            Cloud,
            overview.files_pending_r2 !== undefined && overview.files_pending_r2 > 0
                ? `${overview.files_pending_r2} Pending R2 sync`
                : `${overview.files_synced_r2 ?? overview.total_files} Linked in R2`,
        ],
        ['AI Extractions', overview.total_extractions, Zap, 'PO & Invoice JSONs'],
    ] as const;

    return (
        <>
            <Head title="Google Sheets Upload Sync" />

            <PageShell
                title="Google Sheets Serial Sync"
                description="Ingest upload submissions, Cloudflare R2 file links, and AI extraction JSONs by Serial Number."
                actions={
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setSettingsOpen(true)}
                            className="gap-1.5 text-xs font-semibold"
                        >
                            <Settings className="size-3.5" />
                            <span>Sheet Settings</span>
                        </Button>

                        <Button
                            asChild
                            variant="secondary"
                            size="sm"
                            className="gap-1.5 text-xs font-semibold"
                        >
                            <Link href="/admin/uploads">
                                <Layers className="size-3.5" />
                                <span>View Receive Logs</span>
                            </Link>
                        </Button>
                    </div>
                }
            >
                {/* Toast Notification */}
                {toastMessage && (
                    <div
                        className={`flex items-center justify-between rounded-lg border p-3 font-semibold text-xs shadow-sm ${
                            toastMessage.type === 'success'
                                ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                : 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                        }`}
                    >
                        <span>{toastMessage.text}</span>
                        <button
                            type="button"
                            onClick={() => setToastMessage(null)}
                            className="text-muted-foreground hover:text-foreground"
                        >
                            ✕
                        </button>
                    </div>
                )}

                {/* Top Metrics Cards */}
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {stats.map(([label, value, Icon, subtext]) => (
                        <Card key={label}>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-1">
                                <CardTitle className="text-xs text-muted-foreground">
                                    {label}
                                </CardTitle>
                                <Icon className="size-3.5 text-primary" />
                            </CardHeader>
                            <CardContent className="pt-0">
                                <p className="font-semibold text-2xl tracking-tight">{value}</p>
                                <p className="mt-0.5 text-[10px] text-muted-foreground">
                                    {subtext}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Real-time Progress Banner (when active) */}
                {progress && (progress.isRunning || progress.percentage > 0) && (
                    <Card className="border-primary/30 bg-primary/5">
                        <CardContent className="space-y-3 p-4">
                            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                <div className="flex items-center gap-3">
                                    <div className="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                                        {progress.isRunning ? (
                                            <RefreshCw className="size-4 animate-spin" />
                                        ) : (
                                            <CheckCircle2 className="size-4" />
                                        )}
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h3 className="font-semibold text-sm">
                                                {progress.isRunning
                                                    ? `Syncing ${progress.sheetSlug?.toUpperCase()} into Database`
                                                    : `Batch Sync ${progress.statusText}`}
                                            </h3>
                                            <Badge
                                                variant={progress.isRunning ? 'default' : 'outline'}
                                                className="text-[10px]"
                                            >
                                                {progress.isRunning ? 'In Progress' : 'Completed'}
                                            </Badge>
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {progress.isRunning
                                                ? `Ingesting upload ${progress.current} of ${progress.total}: SN-${progress.currentSerial || '...'}`
                                                : `${progress.successful} submissions successfully synchronized`}
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-center gap-3">
                                    <div className="text-right">
                                        <div className="font-mono font-bold text-xl text-primary">
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
                                            className="h-8 gap-1 text-xs"
                                        >
                                            <Square className="size-3 fill-current" />
                                            <span>Stop</span>
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {/* Progress bar */}
                            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full bg-primary transition-all duration-300"
                                    style={{ width: `${progress.percentage}%` }}
                                />
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Sheet Lane Tab Bar */}
                <div className="overflow-hidden rounded-xl border bg-card p-1 shadow-sm">
                    <div
                        className="flex w-full gap-1 overflow-x-auto p-1"
                        role="tablist"
                        aria-label="Google Sheet Lanes"
                    >
                        {sheets.map((sheet) => {
                            const active = activeSheet === sheet.slug;
                            return (
                                <Button
                                    key={sheet.slug}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    variant={active ? 'default' : 'ghost'}
                                    onClick={() => setActiveSheet(sheet.slug)}
                                    className="h-auto shrink-0 justify-start rounded-lg px-3 py-2 sm:flex-1"
                                >
                                    <span className="flex items-center gap-2">
                                        <Inbox className="size-4 shrink-0" />
                                        <span className="text-left">
                                            <span className="block truncate font-semibold text-sm leading-tight">
                                                {sheet.name}
                                            </span>
                                            <span
                                                className={`block text-[10px] leading-tight ${
                                                    active
                                                        ? 'text-primary-foreground/75'
                                                        : 'text-muted-foreground'
                                                }`}
                                            >
                                                {sheet.synced_serials}/{sheet.total_serials} Synced
                                            </span>
                                        </span>
                                    </span>
                                </Button>
                            );
                        })}
                    </div>
                </div>

                {/* Search, Filter, and Action Toolbar */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-1 items-center gap-2">
                        <div className="relative w-full max-w-sm">
                            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="text"
                                placeholder="Search Serial #, File name, Reviewer..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="h-8 pl-8 text-xs"
                            />
                        </div>

                        <select
                            value={statusFilter}
                            onChange={(e) => {
                                setStatusFilter(e.target.value);
                                setPage(1);
                            }}
                            className="h-8 rounded-md border border-input bg-background px-2.5 text-xs text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            <option value="all">All Uploads ({pagination.total})</option>
                            <option value="pending">Pending Database Sync</option>
                            <option value="updates_available">
                                Updates Available (Needs Re-sync)
                            </option>
                            <option value="synced">Synced in Database</option>
                            <option value="pending_r2">Has Files Pending R2</option>
                            <option value="all_in_r2">All Files in R2</option>
                            <option value="verified">Verified Only</option>
                            <option value="with_extractions">Has AI Extractions</option>
                        </select>

                        <select
                            value={sortBy}
                            onChange={(e) => {
                                setSortBy(e.target.value);
                                setPage(1);
                            }}
                            className="h-8 rounded-md border border-input bg-background px-2.5 text-xs text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            title="Sort order"
                        >
                            <option value="priority">Priority: Action Needed First</option>
                            <option value="sn_desc">Serial # (Newest First)</option>
                            <option value="sn_asc">Serial # (Oldest First)</option>
                            <option value="latest">Recently Updated in Sheets</option>
                        </select>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setRawImportOpen(true)}
                            className="h-8 gap-1.5 text-xs font-semibold"
                            title="Directly paste or upload HTML tables or CSV files"
                        >
                            <FileText className="size-3.5 text-indigo-500" />
                            <span>Import Table</span>
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={refreshingApi || loading}
                            onClick={handleRefreshSheet}
                            className="h-8 gap-1.5 text-xs font-semibold"
                            title="Fetch latest rows from Google Sheets API"
                        >
                            <RefreshCw
                                className={`size-3.5 text-sky-500 ${refreshingApi ? 'animate-spin' : ''}`}
                            />
                            <span>{refreshingApi ? 'Fetching...' : 'Refresh Sheet'}</span>
                        </Button>

                        <Button
                            type="button"
                            size="sm"
                            disabled={progress?.isRunning}
                            onClick={() => setBatchModalOpen(true)}
                            className="h-8 gap-1.5 bg-emerald-600 text-xs font-bold text-white shadow-sm hover:bg-emerald-500"
                        >
                            <Sliders className="size-3.5" />
                            <span>Batch Sync ({currentSheetConfig?.pending_serials || 0})</span>
                        </Button>
                    </div>
                </div>

                {/* Data Table */}
                <div className="overflow-hidden rounded-lg border bg-card shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b bg-muted/50 font-medium text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2">Serial #</th>
                                    <th className="px-3 py-2">Upload Date & Reviewer</th>
                                    <th className="px-3 py-2">Attached Files (R2)</th>
                                    <th className="px-3 py-2">AI & Review Status</th>
                                    <th className="px-3 py-2">Database Status</th>
                                    <th className="px-3 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {items.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="py-8">
                                            <EmptyState
                                                title="No upload records found"
                                                description="Click 'Refresh Sheet' or 'Import Table' to stage Google Sheet rows."
                                            />
                                        </td>
                                    </tr>
                                ) : (
                                    items.map((item) => {
                                        const isSyncing = syncingSerial === item.serial_number;
                                        const isSynced = item.is_synced_to_db;
                                        const fileList = item.files || [];
                                        const totalFiles = fileList.length || item.file_count || 0;
                                        const r2Count = fileList.filter((f) =>
                                            Boolean(f.r2_url && f.r2_url.trim()),
                                        ).length;
                                        const pendingR2Count =
                                            totalFiles > 0 ? Math.max(0, totalFiles - r2Count) : 0;

                                        return (
                                            <tr key={item.id} className="hover:bg-muted/30">
                                                {/* Serial Number */}
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="font-mono font-bold text-foreground">
                                                            SN-{item.serial_number}
                                                        </span>
                                                        {item.drive_folder_link && (
                                                            <a
                                                                href={item.drive_folder_link}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-muted-foreground hover:text-primary"
                                                                title="Open Drive Folder"
                                                            >
                                                                <ExternalLink className="size-3" />
                                                            </a>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Upload Date & Reviewer */}
                                                <td className="px-3 py-2">
                                                    <div className="font-medium text-foreground">
                                                        {item.timestamp
                                                            ? new Date(
                                                                  item.timestamp,
                                                              ).toLocaleDateString()
                                                            : 'N/A'}
                                                    </div>
                                                    <div className="text-[11px] text-muted-foreground">
                                                        {item.reviewed_by ||
                                                            'jaezelle.benito@pingconmarketing.com'}
                                                    </div>
                                                </td>

                                                {/* Attached Files */}
                                                <td className="px-3 py-2">
                                                    {totalFiles === 0 ? (
                                                        <span className="font-mono text-[11px] text-muted-foreground">
                                                            0 Files
                                                        </span>
                                                    ) : (
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <Badge
                                                                variant="outline"
                                                                className="font-mono text-[11px]"
                                                            >
                                                                {totalFiles}{' '}
                                                                {totalFiles === 1
                                                                    ? 'File'
                                                                    : 'Files'}
                                                            </Badge>

                                                            {pendingR2Count > 0 ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-amber-500/30 bg-amber-500/10 font-mono text-[10px] font-semibold text-amber-600 dark:text-amber-400"
                                                                    title={`${pendingR2Count} out of ${totalFiles} file(s) have no R2 URL in receive_files sheet tab`}
                                                                >
                                                                    {pendingR2Count} Pending R2
                                                                </Badge>
                                                            ) : (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-emerald-500/30 bg-emerald-500/10 font-mono text-[10px] font-semibold text-emerald-600 dark:text-emerald-400"
                                                                    title="All files have valid Cloudflare R2 storage URLs"
                                                                >
                                                                    {r2Count} in R2
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>

                                                {/* AI & Review Status */}
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-1.5">
                                                        <Badge
                                                            variant="outline"
                                                            className={`text-[10px] capitalize ${
                                                                item.review_status === 'verified'
                                                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                                    : 'text-muted-foreground'
                                                            }`}
                                                        >
                                                            {item.review_status || 'Pending'}
                                                        </Badge>
                                                        {item.extraction && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-indigo-500/30 bg-indigo-500/10 text-[10px] text-indigo-600 dark:text-indigo-400"
                                                            >
                                                                AI
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Database Status */}
                                                <td className="px-3 py-2">
                                                    {!isSynced ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-amber-500/30 bg-amber-500/10 text-[10px] font-semibold text-amber-600 dark:text-amber-400"
                                                        >
                                                            Pending Sync
                                                        </Badge>
                                                    ) : item.has_update_available ? (
                                                        <div className="flex flex-col items-start gap-0.5">
                                                            <Badge
                                                                variant="outline"
                                                                className="gap-1 border-indigo-500/30 bg-indigo-500/10 text-[10px] font-semibold text-indigo-600 dark:text-indigo-400"
                                                                title="Google Sheets has newer updates since last database sync"
                                                            >
                                                                <Sparkles className="size-2.5" />
                                                                <span>Update Available</span>
                                                            </Badge>
                                                            <span className="font-mono text-[9px] text-muted-foreground">
                                                                Synced #
                                                                {item.synced_receiving_upload_id}
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <Badge
                                                            variant="default"
                                                            className="gap-1 bg-emerald-600 text-[10px] text-white"
                                                        >
                                                            <CheckCircle2 className="size-3" />
                                                            <span>
                                                                Synced #
                                                                {item.synced_receiving_upload_id}
                                                            </span>
                                                        </Badge>
                                                    )}
                                                </td>

                                                {/* Actions */}
                                                <td className="px-3 py-2 text-right">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => setDetailsItem(item)}
                                                            className="h-7 gap-1 px-2 text-xs"
                                                            title="Inspect details, files, and AI JSON"
                                                        >
                                                            <Eye className="size-3.5" />
                                                            <span>Details</span>
                                                        </Button>

                                                        {!isSynced ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={isSyncing}
                                                                onClick={() =>
                                                                    handleSyncSerial(
                                                                        item.serial_number,
                                                                    )
                                                                }
                                                                className="h-7 gap-1 px-2 text-xs font-semibold text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-950/50"
                                                            >
                                                                <RefreshCw
                                                                    className={`size-3 ${isSyncing ? 'animate-spin' : ''}`}
                                                                />
                                                                <span>
                                                                    {isSyncing
                                                                        ? 'Syncing...'
                                                                        : 'Sync Now'}
                                                                </span>
                                                            </Button>
                                                        ) : item.has_update_available ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={isSyncing}
                                                                onClick={() =>
                                                                    handleSyncSerial(
                                                                        item.serial_number,
                                                                    )
                                                                }
                                                                className="h-7 gap-1 px-2 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 hover:text-indigo-700 dark:text-indigo-400 dark:hover:bg-indigo-950/50"
                                                                title="Re-synchronize with latest Google Sheet updates"
                                                            >
                                                                <RefreshCw
                                                                    className={`size-3 ${isSyncing ? 'animate-spin' : ''}`}
                                                                />
                                                                <span>
                                                                    {isSyncing
                                                                        ? 'Re-syncing...'
                                                                        : 'Re-sync'}
                                                                </span>
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pagination.last_page > 1 && (
                        <div className="flex items-center justify-between border-t px-4 py-2.5">
                            <span className="text-xs text-muted-foreground">
                                Page {pagination.current_page} of {pagination.last_page} (
                                {pagination.total} total)
                            </span>
                            <div className="flex items-center gap-1.5">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.current_page <= 1}
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    className="h-7 px-2.5 text-xs"
                                >
                                    Previous
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.current_page >= pagination.last_page}
                                    onClick={() => setPage((p) => p + 1)}
                                    className="h-7 px-2.5 text-xs"
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
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
                        setSheets((prev) =>
                            prev.map((s) => (s.slug === updated.slug ? updated : s)),
                        );
                    }}
                />

                {/* Serial Details Modal */}
                <SerialDetailsModal
                    open={!!detailsItem}
                    onOpenChange={(op) => !op && setDetailsItem(null)}
                    item={detailsItem}
                    onSyncClick={(sn) => handleSyncSerial(sn)}
                />
            </PageShell>
        </>
    );
}

SheetsSyncPage.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Google Sheets Sync', href: '/admin/sheets-sync' },
    ],
};
