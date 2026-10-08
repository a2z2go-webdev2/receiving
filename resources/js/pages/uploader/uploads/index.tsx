import { Head, Link, router } from '@inertiajs/react';
import { Eye, Inbox, Search, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { FilterDropdown } from '@/components/receiving/filter-dropdown';
import { FlashMessage } from '@/components/receiving/flash-message';
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
import { useLivePageData } from '@/hooks/use-live-page-data';
import { aiStatusLabel } from '@/lib/upload-status';

type PoLinkDetails = {
    status: string;
    total_invoices: number;
    linked_invoices: number;
    po_numbers: string[];
};

type Upload = {
    id: number;
    serial_number: number;
    serial_prefix: string;
    upload_type: string;
    uploader_email: string;
    created_at: string;
    r2_prefix: string;
    file_count: number;
    review_email_status: string;
    ai_status: string;
    review_status: string;
    purchase_order_status: string;
    detail_url: string;
    po_link_details?: PoLinkDetails | null;
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
    review_email_status: string;
    ai_status: string;
    review_status: string;
    upload_type_id: string;
};

type UploadType = { id: number; name: string; slug?: string };

const emptyFilters: Filters = {
    search: '',
    review_email_status: '',
    ai_status: '',
    review_status: '',
    upload_type_id: '',
};

export default function UploaderUploadsIndex({
    uploads,
    filters,
    uploadTypes,
    basePath,
}: {
    uploads: Paginator<Upload>;
    filters: Filters;
    uploadTypes: UploadType[];
    basePath: string;
}) {
    useLivePageData(['uploads']);

    const [advancedFiltersOpen, setAdvancedFiltersOpen] = useState(false);
    const [filterValues, setFilterValues] = useState(filters);

    function applyFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        submitFilters();
    }

    function submitFilters() {
        router.get(basePath, filterValues, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function handleTabChange(upload_type_id: string) {
        const newFilters = { ...filterValues, upload_type_id };
        setFilterValues(newFilters);
        router.get(basePath, newFilters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function clearFilters() {
        const newFilters = { ...emptyFilters, upload_type_id: filterValues.upload_type_id };
        setFilterValues(newFilters);
        router.get(
            basePath,
            { upload_type_id: filterValues.upload_type_id },
            { preserveScroll: true, replace: true },
        );
    }

    const activeFiltersCount = Object.entries(filters).filter(
        ([key, value]) => key !== 'upload_type_id' && value !== '',
    ).length;
    const hasFilters = activeFiltersCount > 0;
    const hasActiveFilterValues = Object.entries(filterValues).some(
        ([key, value]) => key !== 'upload_type_id' && Boolean(value),
    );

    const advancedFilterCount = [
        filterValues.review_email_status,
        filterValues.ai_status,
        filterValues.review_status,
    ].filter(Boolean).length;

    return (
        <>
            <Head title="My uploads" />
            <PageShell
                title="My uploads"
                description="Search your upload history, check AI extraction status, and view document details."
            >
                <FlashMessage />

                {uploadTypes.length > 0 && (
                    <div className="mb-4 overflow-hidden rounded-xl border bg-card p-1 shadow-sm">
                        <div
                            className="flex w-full gap-1 overflow-x-auto p-1"
                            role="tablist"
                            aria-label="Upload sources"
                        >
                            <Button
                                type="button"
                                role="tab"
                                aria-selected={filterValues.upload_type_id === ''}
                                variant={filterValues.upload_type_id === '' ? 'default' : 'ghost'}
                                onClick={() => handleTabChange('')}
                                className="h-auto shrink-0 justify-start rounded-lg px-3 py-2 sm:flex-1"
                            >
                                <span className="flex items-center gap-2">
                                    <Inbox className="size-4 shrink-0" />
                                    <span className="text-left">
                                        <span className="block font-semibold text-sm leading-tight">
                                            All
                                        </span>
                                        <span
                                            className={`block text-[10px] leading-tight ${filterValues.upload_type_id === '' ? 'text-primary-foreground/75' : 'text-muted-foreground'}`}
                                        >
                                            All upload sources
                                        </span>
                                    </span>
                                </span>
                            </Button>
                            {uploadTypes.map((type) => {
                                const active = filterValues.upload_type_id === String(type.id);
                                return (
                                    <Button
                                        key={type.id}
                                        type="button"
                                        role="tab"
                                        aria-selected={active}
                                        variant={active ? 'default' : 'ghost'}
                                        onClick={() => handleTabChange(String(type.id))}
                                        className="h-auto shrink-0 justify-start rounded-lg px-3 py-2 sm:flex-1"
                                    >
                                        <span className="flex items-center gap-2">
                                            <Inbox className="size-4 shrink-0" />
                                            <span className="text-left">
                                                <span className="block truncate font-semibold text-sm leading-tight">
                                                    {type.name}
                                                </span>
                                                <span
                                                    className={`block text-[10px] leading-tight ${active ? 'text-primary-foreground/75' : 'text-muted-foreground'}`}
                                                >
                                                    Uploads
                                                </span>
                                            </span>
                                        </span>
                                    </Button>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <form onSubmit={applyFilters} className="flex flex-1 items-center gap-1.5">
                        <div className="relative w-full max-w-sm">
                            <Search className="absolute top-1/2 left-2 size-3 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                id="upload-search"
                                value={filterValues.search}
                                onChange={(event) =>
                                    setFilterValues((current) => ({
                                        ...current,
                                        search: event.target.value,
                                    }))
                                }
                                placeholder="Search serial numbers, files, PO numbers..."
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
                        >
                            <div className="space-y-4">
                                <div>
                                    <h2 className="font-semibold text-sm">More filters</h2>
                                    <p className="mt-1 text-muted-foreground text-xs">
                                        Narrow your uploads by email, extraction, or review
                                        progress.
                                    </p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FilterSelect
                                        id="review-email-status"
                                        label="Review email"
                                        value={filterValues.review_email_status}
                                        onChange={(review_email_status) =>
                                            setFilterValues((current) => ({
                                                ...current,
                                                review_email_status,
                                            }))
                                        }
                                        options={[
                                            { value: 'pending', label: 'Pending' },
                                            { value: 'sending', label: 'Sending' },
                                            { value: 'sent', label: 'Sent' },
                                            { value: 'failed', label: 'Failed' },
                                        ]}
                                    />
                                    <FilterSelect
                                        id="ai-status"
                                        label="AI extraction"
                                        value={filterValues.ai_status}
                                        onChange={(ai_status) =>
                                            setFilterValues((current) => ({
                                                ...current,
                                                ai_status,
                                            }))
                                        }
                                        options={[
                                            { value: 'waiting', label: 'Waiting' },
                                            { value: 'in_progress', label: 'In progress' },
                                            { value: 'completed', label: 'Completed' },
                                            { value: 'failed', label: 'Failed' },
                                        ]}
                                    />
                                    <FilterSelect
                                        id="review-status"
                                        label="Review status"
                                        value={filterValues.review_status}
                                        onChange={(review_status) =>
                                            setFilterValues((current) => ({
                                                ...current,
                                                review_status,
                                            }))
                                        }
                                        options={[
                                            { value: 'pending', label: 'Pending' },
                                            { value: 'revision', label: 'Changes saved' },
                                            { value: 'verified', label: 'Verified' },
                                        ]}
                                    />
                                </div>
                                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setFilterValues((current) => ({
                                                ...current,
                                                review_email_status: '',
                                                ai_status: '',
                                                review_status: '',
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
                        {(hasFilters || hasActiveFilterValues) && (
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
                        {hasFilters ? (
                            <p className="text-muted-foreground text-xs">
                                Showing filtered results
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-xs">
                                Showing{' '}
                                {filterValues.upload_type_id === ''
                                    ? 'all uploads'
                                    : `${uploadTypes.find((t) => String(t.id) === filterValues.upload_type_id)?.name.toLowerCase()} uploads`}
                            </p>
                        )}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full whitespace-nowrap text-left text-xs">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="px-3 py-2">Serial / type</th>
                                <th className="px-3 py-2">Files</th>
                                <th className="px-3 py-2">Review email</th>
                                <th className="px-3 py-2">AI extraction</th>
                                <th className="px-3 py-2">PO link</th>
                                <th className="px-3 py-2">Review status</th>
                                <th className="px-3 py-2">Uploaded</th>
                                <th className="px-3 py-2 text-right">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {uploads.data.map((upload) => (
                                <tr key={upload.id}>
                                    <td className="px-3 py-2">
                                        <p className="font-medium">
                                            {upload.serial_prefix}-{upload.serial_number}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {upload.upload_type}
                                        </p>
                                    </td>
                                    <td className="px-3 py-2">{upload.file_count}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge value={upload.review_email_status} />
                                    </td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            value={upload.ai_status}
                                            label={aiStatusLabel(upload.ai_status)}
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex flex-col gap-0.5">
                                            <div className="flex items-center gap-1.5">
                                                <StatusBadge value={upload.purchase_order_status} />
                                                {upload.po_link_details &&
                                                    upload.po_link_details.total_invoices > 1 && (
                                                        <span className="font-medium text-[11px] text-muted-foreground">
                                                            {upload.po_link_details.linked_invoices}
                                                            /{upload.po_link_details.total_invoices}{' '}
                                                            linked
                                                        </span>
                                                    )}
                                            </div>
                                            {upload.po_link_details?.po_numbers &&
                                                upload.po_link_details.po_numbers.length > 0 && (
                                                    <div className="flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground">
                                                        <span>PO:</span>
                                                        {upload.po_link_details.po_numbers.map(
                                                            (poNum) => (
                                                                <span
                                                                    key={poNum}
                                                                    className="font-medium text-foreground"
                                                                >
                                                                    {poNum}
                                                                </span>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        <StatusBadge value={upload.review_status} />
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {new Date(upload.created_at).toLocaleString()}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Button
                                            asChild
                                            size="icon"
                                            variant="ghost"
                                            className="size-7"
                                        >
                                            <Link
                                                href={upload.detail_url}
                                                aria-label={`View details for ${upload.serial_prefix}-${upload.serial_number}`}
                                                title="View upload details"
                                            >
                                                <Eye className="size-4" />
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {uploads.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-3 py-10 text-center text-muted-foreground"
                                    >
                                        No uploads match this search and filter combination.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationNav
                    currentPage={uploads.current_page}
                    lastPage={uploads.last_page}
                    previousUrl={uploads.prev_page_url}
                    nextUrl={uploads.next_page_url}
                    label="My upload pages"
                />
            </PageShell>
        </>
    );
}

function FilterSelect({
    id,
    label,
    value,
    onChange,
    options,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <div>
                <Select
                    value={value === '' ? 'all' : value}
                    onValueChange={(nextValue) => onChange(nextValue === 'all' ? '' : nextValue)}
                >
                    <SelectTrigger id={id} className="w-full">
                        <SelectValue placeholder="All" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All</SelectItem>
                        {options.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </div>
    );
}
