import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    ClipboardCheck,
    ClipboardCopy,
    Info,
    Search,
    ShieldAlert,
    Terminal,
    X,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';
import { FilterDropdown } from '@/components/receiving/filter-dropdown';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLivePageData } from '@/hooks/use-live-page-data';

type Log = {
    id: number;
    receiving_upload_id: number | null;
    user_email: string | null;
    role: string;
    module: string;
    action: string;
    status: string;
    message: string;
    error_details: string | null;
    ip_address: string | null;
    created_at: string;
    upload: {
        id: number;
        serial_number: number;
        serial_prefix: string;
        upload_type: { name: string };
    } | null;
};
type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Filters = {
    search: string;
    module: string;
    status: string;
    start_date: string;
    end_date: string;
};

export default function ActivityIndex({
    logs,
    filters,
    filterOptions,
}: {
    logs: Paginator<Log>;
    filters: Filters;
    filterOptions: { modules: string[]; statuses: string[] };
}) {
    useLivePageData(['logs'], 10_000);

    const [values, setValues] = useState(filters);
    const [selectedLog, setSelectedLog] = useState<Log | null>(null);
    const [copied, setCopied] = useState(false);
    const [isDatePickerOpen, setIsDatePickerOpen] = useState(false);
    const [advancedFiltersOpen, setAdvancedFiltersOpen] = useState(false);

    function applyFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        submitFilters();
    }

    function submitFilters() {
        router.get('/admin/activity', values, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function clearFilters() {
        const empty = { search: '', module: '', status: '', start_date: '', end_date: '' };
        setValues(empty);
        router.get('/admin/activity', empty, { preserveScroll: true, replace: true });
    }

    const hasActiveFilters = Object.values(filters).some(Boolean);
    const hasActiveFilterValues = Object.values(values).some(Boolean);
    const advancedFilterCount = [
        values.module,
        values.status,
        values.start_date,
        values.end_date,
    ].filter(Boolean).length;

    function applyPreset(preset: string) {
        const today = new Date();
        const formatDate = (date: Date) => date.toISOString().split('T')[0];

        let start = '';
        let end = formatDate(today);

        if (preset === 'today') {
            start = formatDate(today);
        } else if (preset === 'yesterday') {
            const yesterday = new Date();
            yesterday.setDate(today.getDate() - 1);
            start = formatDate(yesterday);
            end = formatDate(yesterday);
        } else if (preset === '7days') {
            const date = new Date();
            date.setDate(today.getDate() - 6);
            start = formatDate(date);
        } else if (preset === '30days') {
            const date = new Date();
            date.setDate(today.getDate() - 29);
            start = formatDate(date);
        } else if (preset === 'thisMonth') {
            const date = new Date(today.getFullYear(), today.getMonth(), 1);
            start = formatDate(date);
        } else if (preset === 'all') {
            start = '';
            end = '';
        }

        setValues((current) => ({
            ...current,
            start_date: start,
            end_date: end,
        }));
    }

    function getDateRangeLabel(start: string, end: string) {
        if (!start && !end) return 'All time';
        return `${start || '...'} to ${end || '...'}`;
    }

    function handleCopy(text: string) {
        navigator.clipboard.writeText(text);
        setCopied(true);
        toast.success('Technical details copied to clipboard');
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <>
            <Head title="Activity Logs" />
            <PageShell
                title="Activity logs"
                description="Trace user, security, provider, retry, and workflow events without digging through raw application logs."
            >
                <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <form onSubmit={applyFilters} className="flex flex-1 items-center gap-1.5">
                        <div className="relative w-full max-w-sm">
                            <Search className="absolute top-1/2 left-2 size-3 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                id="activity-search"
                                value={values.search}
                                onChange={(event) =>
                                    setValues((current) => ({
                                        ...current,
                                        search: event.target.value,
                                    }))
                                }
                                placeholder="Search SN, POSN, actor, action…"
                                className="h-7 w-full pl-7 text-xs"
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            className="h-7 gap-1 whitespace-nowrap px-2.5 text-xs"
                        >
                            <Search className="size-3" /> Search
                        </Button>
                        <FilterDropdown
                            open={advancedFiltersOpen}
                            onOpenChange={setAdvancedFiltersOpen}
                            activeCount={advancedFilterCount}
                            className="w-[min(28rem,calc(100vw-2rem))]"
                        >
                            <div className="space-y-4">
                                <div>
                                    <h2 className="font-semibold text-sm">More filters</h2>
                                    <p className="mt-1 text-muted-foreground text-xs">
                                        Narrow activity events by module, status, or date range.
                                    </p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <ActivitySelect
                                        id="activity-module"
                                        label="Module"
                                        value={values.module}
                                        options={filterOptions.modules}
                                        onChange={(module) =>
                                            setValues((current) => ({ ...current, module }))
                                        }
                                    />
                                    <ActivitySelect
                                        id="activity-status"
                                        label="Status"
                                        value={values.status}
                                        options={filterOptions.statuses}
                                        onChange={(status) =>
                                            setValues((current) => ({ ...current, status }))
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <div className="font-medium text-foreground text-xs">
                                        Date range
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <Button
                                            id="date-range-trigger"
                                            type="button"
                                            variant="outline"
                                            className="h-7 w-[180px] justify-start bg-background px-2 font-normal text-[11px]"
                                            onClick={() => setIsDatePickerOpen(!isDatePickerOpen)}
                                        >
                                            <CalendarDays className="mr-1 size-3 text-muted-foreground" />
                                            <span className="truncate">
                                                {getDateRangeLabel(
                                                    values.start_date,
                                                    values.end_date,
                                                )}
                                            </span>
                                        </Button>
                                        {(values.start_date || values.end_date) && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 px-2 text-[11px]"
                                                onClick={() =>
                                                    setValues((current) => ({
                                                        ...current,
                                                        start_date: '',
                                                        end_date: '',
                                                    }))
                                                }
                                            >
                                                <X className="size-3" /> Clear dates
                                            </Button>
                                        )}
                                    </div>

                                    {isDatePickerOpen && (
                                        <div className="rounded-md border bg-popover p-2.5 text-popover-foreground">
                                            <div className="space-y-2">
                                                <div className="font-semibold text-[10px] text-muted-foreground uppercase tracking-wider">
                                                    Quick presets
                                                </div>
                                                <div className="grid grid-cols-3 gap-1">
                                                    {[
                                                        { label: 'Today', value: 'today' },
                                                        { label: 'Yesterday', value: 'yesterday' },
                                                        { label: 'Last 7d', value: '7days' },
                                                        { label: 'Last 30d', value: '30days' },
                                                        {
                                                            label: 'This month',
                                                            value: 'thisMonth',
                                                        },
                                                        { label: 'All time', value: 'all' },
                                                    ].map(({ label, value }) => (
                                                        <Button
                                                            key={value}
                                                            type="button"
                                                            variant="outline"
                                                            className="h-6 px-1.5 font-normal text-[10px]"
                                                            onClick={() => applyPreset(value)}
                                                        >
                                                            {label}
                                                        </Button>
                                                    ))}
                                                </div>

                                                <div className="border-t" />

                                                <div className="font-semibold text-[10px] text-muted-foreground uppercase tracking-wider">
                                                    Custom range
                                                </div>
                                                <div className="space-y-1.5">
                                                    <div className="grid grid-cols-[36px_1fr] items-center gap-1.5">
                                                        <label
                                                            htmlFor="custom-start-date"
                                                            className="text-right text-[10px] text-muted-foreground"
                                                        >
                                                            Start
                                                        </label>
                                                        <Input
                                                            id="custom-start-date"
                                                            type="date"
                                                            value={values.start_date}
                                                            onChange={(event) =>
                                                                setValues((current) => ({
                                                                    ...current,
                                                                    start_date: event.target.value,
                                                                }))
                                                            }
                                                            className="h-7 bg-background px-2 py-0.5 text-[11px]"
                                                        />
                                                    </div>
                                                    <div className="grid grid-cols-[36px_1fr] items-center gap-1.5">
                                                        <label
                                                            htmlFor="custom-end-date"
                                                            className="text-right text-[10px] text-muted-foreground"
                                                        >
                                                            End
                                                        </label>
                                                        <Input
                                                            id="custom-end-date"
                                                            type="date"
                                                            value={values.end_date}
                                                            onChange={(event) =>
                                                                setValues((current) => ({
                                                                    ...current,
                                                                    end_date: event.target.value,
                                                                }))
                                                            }
                                                            className="h-7 bg-background px-2 py-0.5 text-[11px]"
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setValues((current) => ({
                                                ...current,
                                                module: '',
                                                status: '',
                                                start_date: '',
                                                end_date: '',
                                            }))
                                        }
                                    >
                                        Clear advanced
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setAdvancedFiltersOpen(false);
                                            submitFilters();
                                        }}
                                    >
                                        Apply filters
                                    </Button>
                                </div>
                            </div>
                        </FilterDropdown>
                        {(hasActiveFilters || hasActiveFilterValues) && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                                className="h-7 gap-1 px-2 text-xs"
                            >
                                <X className="size-3" /> Clear
                            </Button>
                        )}
                    </form>

                    <div className="flex items-center gap-2">
                        <p className="text-muted-foreground text-xs">
                            {hasActiveFilters
                                ? 'Showing filtered results'
                                : 'Showing all activity events'}
                        </p>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
                    <div className="flex items-center justify-between border-b px-3 py-1.5">
                        <p className="font-medium text-xs">Event timeline</p>
                        <p className="text-[11px] text-muted-foreground">
                            {logs.data.length} event{logs.data.length === 1 ? '' : 's'} on this page
                        </p>
                    </div>
                    <table className="w-full text-left text-xs">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="px-2.5 py-1.5 pl-3 font-medium text-[11px] text-muted-foreground">
                                    Time
                                </th>
                                <th className="px-2.5 py-1.5 font-medium text-[11px] text-muted-foreground">
                                    Actor
                                </th>
                                <th className="px-2.5 py-1.5 font-medium text-[11px] text-muted-foreground">
                                    Event
                                </th>
                                <th className="px-2.5 py-1.5 font-medium text-[11px] text-muted-foreground">
                                    Outcome
                                </th>
                                <th className="px-2.5 py-1.5 font-medium text-[11px] text-muted-foreground">
                                    Message
                                </th>
                                <th className="px-2.5 py-1.5 font-medium text-[11px] text-muted-foreground">
                                    Upload
                                </th>
                                <th className="px-2.5 py-1.5 font-medium text-[11px] text-muted-foreground">
                                    IP
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {logs.data.map((log) => {
                                const statusInfo = getStatusDetails(log.status);
                                return (
                                    <tr
                                        key={log.id}
                                        onClick={() => setSelectedLog(log)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                setSelectedLog(log);
                                            }
                                        }}
                                        tabIndex={0}
                                        className={`cursor-pointer align-middle outline-hidden transition-colors hover:bg-muted/30 focus-visible:bg-muted/30 ${statusInfo.borderClass}`}
                                    >
                                        <td className="whitespace-nowrap px-2.5 py-2 pl-3 font-mono text-[11px] text-muted-foreground">
                                            {new Date(log.created_at).toLocaleString()}
                                        </td>
                                        <td className="px-2.5 py-2">
                                            <p className="font-medium text-foreground text-xs leading-tight">
                                                {log.user_email ?? 'System'}
                                            </p>
                                            <p className="text-[10px] text-muted-foreground capitalize leading-tight">
                                                {log.role}
                                            </p>
                                        </td>
                                        <td className="px-2.5 py-2">
                                            <div className="flex items-center gap-1.5">
                                                <span className="inline-flex rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] capitalize">
                                                    {log.module}
                                                </span>
                                                <span className="font-medium text-foreground text-xs">
                                                    {formatAction(log.action)}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-2.5 py-2">
                                            <StatusBadge value={log.status} />
                                        </td>
                                        <td className="max-w-xs px-2.5 py-2 md:max-w-md lg:max-w-lg">
                                            <p className="line-clamp-2 text-muted-foreground leading-snug">
                                                {log.message}
                                            </p>
                                            {log.error_details && (
                                                <span className="inline-flex items-center gap-1 font-medium text-[10px] text-red-600 dark:text-red-400">
                                                    <ShieldAlert className="size-3" /> Technical
                                                    details
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2.5 py-2">
                                            {log.upload ? (
                                                <Link
                                                    href={`/admin/uploads/${log.upload.id}`}
                                                    onClick={(event) => event.stopPropagation()}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {log.upload.serial_prefix}-
                                                    {log.upload.serial_number}
                                                    <span className="block font-normal text-[10px] text-muted-foreground leading-tight no-underline">
                                                        {log.upload.upload_type.name}
                                                    </span>
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </td>
                                        <td className="px-2.5 py-2 font-mono text-[11px] text-muted-foreground">
                                            {log.ip_address ?? '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                            {logs.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-3 py-8 text-center text-muted-foreground text-xs"
                                    >
                                        No events match these filters. Clear a filter to widen the
                                        timeline.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <PaginationNav
                    currentPage={logs.current_page}
                    lastPage={logs.last_page}
                    previousUrl={logs.prev_page_url}
                    nextUrl={logs.next_page_url}
                    label="Activity log pages"
                />
            </PageShell>

            <Sheet
                open={selectedLog !== null}
                onOpenChange={(open) => !open && setSelectedLog(null)}
            >
                <SheetContent className="flex w-full flex-col gap-6 overflow-y-auto p-6 sm:max-w-md md:max-w-lg lg:max-w-xl">
                    {selectedLog && (
                        <>
                            <SheetHeader className="border-b p-0 pb-4">
                                <div className="mb-1 flex items-center gap-2 font-mono text-muted-foreground text-xs">
                                    <Terminal className="size-3.5" />
                                    <span>Log Event ID: {selectedLog.id}</span>
                                </div>
                                <SheetTitle className="flex items-center justify-between font-bold text-xl">
                                    <span>Event Details</span>
                                    <div className="flex items-center gap-1.5">
                                        <span
                                            className={`inline-flex rounded-full px-2 py-0.5 font-medium text-xs ${getStatusDetails(selectedLog.status).bgClass} border`}
                                        >
                                            {selectedLog.status}
                                        </span>
                                    </div>
                                </SheetTitle>
                                <SheetDescription className="mt-1 text-muted-foreground text-xs">
                                    Occurred on {new Date(selectedLog.created_at).toLocaleString()}
                                </SheetDescription>
                            </SheetHeader>

                            <div className="flex-1 space-y-6">
                                {/* Event block */}
                                <div className="space-y-3">
                                    <h4 className="font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                        Event Info
                                    </h4>
                                    <div className="grid gap-3 rounded-lg border border-muted bg-muted/40 p-4 text-sm">
                                        <div className="grid grid-cols-[80px_1fr]">
                                            <span className="font-medium text-muted-foreground">
                                                Module:
                                            </span>
                                            <span className="w-fit rounded bg-muted px-1.5 py-0.5 font-mono text-xs capitalize">
                                                {selectedLog.module}
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-[80px_1fr]">
                                            <span className="font-medium text-muted-foreground">
                                                Action:
                                            </span>
                                            <span className="break-all font-mono text-xs">
                                                {selectedLog.action}
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-[80px_1fr]">
                                            <span className="font-medium text-muted-foreground">
                                                Message:
                                            </span>
                                            <span className="text-foreground leading-relaxed">
                                                {selectedLog.message}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Actor block */}
                                <div className="space-y-3">
                                    <h4 className="font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                        Actor & Security
                                    </h4>
                                    <div className="grid gap-3 rounded-lg border border-muted bg-muted/40 p-4 text-sm">
                                        <div className="grid grid-cols-[80px_1fr]">
                                            <span className="font-medium text-muted-foreground">
                                                Actor:
                                            </span>
                                            <span className="font-medium text-foreground">
                                                {selectedLog.user_email ?? 'System'}
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-[80px_1fr]">
                                            <span className="font-medium text-muted-foreground">
                                                Role:
                                            </span>
                                            <span className="capitalize">{selectedLog.role}</span>
                                        </div>
                                        <div className="grid grid-cols-[80px_1fr]">
                                            <span className="font-medium text-muted-foreground">
                                                IP Address:
                                            </span>
                                            <span className="font-mono text-xs">
                                                {selectedLog.ip_address ?? '—'}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Upload context if exists */}
                                {selectedLog.upload && (
                                    <div className="space-y-3">
                                        <h4 className="font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                            Upload Context
                                        </h4>
                                        <div className="grid gap-3 rounded-lg border border-muted bg-muted/40 p-4 text-sm">
                                            <div className="grid grid-cols-[80px_1fr]">
                                                <span className="font-medium text-muted-foreground">
                                                    Serial No:
                                                </span>
                                                <Link
                                                    href={`/admin/uploads/${selectedLog.upload.id}`}
                                                    className="w-fit font-medium text-primary hover:underline"
                                                >
                                                    {selectedLog.upload.serial_prefix}-
                                                    {selectedLog.upload.serial_number}
                                                </Link>
                                            </div>
                                            <div className="grid grid-cols-[80px_1fr]">
                                                <span className="font-medium text-muted-foreground">
                                                    Type:
                                                </span>
                                                <span>{selectedLog.upload.upload_type.name}</span>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Error details if exist */}
                                {selectedLog.error_details && (
                                    <div className="space-y-3">
                                        <div className="flex items-center justify-between">
                                            <h4 className="flex items-center gap-1.5 font-semibold text-red-600 text-xs uppercase tracking-wider dark:text-red-400">
                                                <ShieldAlert className="size-3.5" /> Technical
                                                Details
                                            </h4>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    handleCopy(selectedLog.error_details || '')
                                                }
                                                className="h-7 px-2.5 text-xs"
                                            >
                                                {copied ? (
                                                    <>
                                                        <ClipboardCheck className="mr-1 size-3 text-emerald-500" />
                                                        Copied
                                                    </>
                                                ) : (
                                                    <>
                                                        <ClipboardCopy className="mr-1 size-3" />
                                                        Copy Details
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                        <div className="relative">
                                            <pre className="max-h-[300px] overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-zinc-800 bg-zinc-950 p-4 font-mono text-xs text-zinc-100 leading-relaxed">
                                                {selectedLog.error_details}
                                            </pre>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

function ActivitySelect({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: string[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <div>
                <Select
                    value={value === '' ? 'all' : value}
                    onValueChange={(next) => onChange(next === 'all' ? '' : next)}
                >
                    <SelectTrigger id={id} className="w-full capitalize">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All</SelectItem>
                        {options.map((option) => (
                            <SelectItem key={option} value={option} className="capitalize">
                                {option.replaceAll('_', ' ')}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </div>
    );
}

function getStatusDetails(status: string) {
    const s = status ? status.toLowerCase() : '';
    if (
        [
            'success',
            'active',
            'sent',
            'clean',
            'valid',
            'compressed',
            'completed',
            'extracted',
            'verified',
        ].includes(s)
    ) {
        return {
            borderClass: 'border-l-4 border-l-emerald-500 dark:border-l-emerald-600',
            bgClass:
                'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-900',
            indicatorClass: 'bg-emerald-500',
            icon: Info,
        };
    }
    if (
        [
            'error',
            'failed',
            'infected',
            'invalid',
            'suspicious',
            'partial_failed',
            'inactive',
            'deactivated',
            'banned',
        ].includes(s)
    ) {
        return {
            borderClass: 'border-l-4 border-l-red-500 dark:border-l-red-600',
            bgClass:
                'bg-red-50 text-red-800 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-900',
            indicatorClass: 'bg-red-500',
            icon: ShieldAlert,
        };
    }
    if (
        [
            'pending',
            'processing',
            'sending',
            'queued',
            'staging',
            'revision',
            'manual_review',
            'warning',
            'waiting',
        ].includes(s)
    ) {
        return {
            borderClass: 'border-l-4 border-l-amber-500 dark:border-l-amber-600',
            bgClass:
                'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-900',
            indicatorClass: 'bg-amber-500',
            icon: Info,
        };
    }
    if (['info', 'notice', 'details', 'log'].includes(s)) {
        return {
            borderClass: 'border-l-4 border-l-blue-500 dark:border-l-blue-600',
            bgClass:
                'bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-900',
            indicatorClass: 'bg-blue-500',
            icon: Info,
        };
    }
    return {
        borderClass: 'border-l-4 border-l-slate-300 dark:border-l-slate-700',
        bgClass:
            'bg-slate-50 text-slate-800 border-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:border-slate-850',
        indicatorClass: 'bg-slate-400',
        icon: Info,
    };
}

function formatAction(action: string): string {
    return action.replaceAll('_', ' ').replace(/^\w/, (character) => character.toUpperCase());
}

ActivityIndex.layout = { breadcrumbs: [{ title: 'Activity logs', href: '/admin/activity' }] };
