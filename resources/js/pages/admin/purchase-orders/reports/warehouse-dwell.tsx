import { Head } from '@inertiajs/react';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import {
    DateRangeFilter,
    ReportLayout,
    ReportSection,
    ReportStatCard,
} from '@/components/reports/report-layout';
import { formatDateRangePeriod } from '@/components/reports/week-grouping';

type Allocation = {
    id: number;
    stock_lot_id: number;
    po_number: string | null;
    lot_number: string | null;
    quantity: number;
    received_at: string | null;
    received_date_quality: 'confirmed' | 'estimated' | 'unknown';
    warehouse_holding_days: number | null;
    warehouse_dwell_days: number | null;
    allocation_method: 'fifo';
};

type DwellRow = {
    id: number;
    delivery_id: number;
    customer_name: string;
    delivery_reference: string | null;
    item_id: number;
    sku_number: string | null;
    description: string;
    quantity: number;
    unit: string | null;
    dispatched_at: string;
    delivered_at: string;
    first_received_at: string | null;
    last_received_at: string | null;
    warehouse_holding_days: number | null;
    warehouse_dwell_days: number | null;
    maximum_warehouse_dwell_days: number | null;
    date_coverage_percent: number;
    allocations: Allocation[];
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Summary = {
    delivered_lines: number;
    fully_dated_lines: number;
    date_coverage_percent: number;
    average_line_warehouse_holding_days: number | null;
    average_line_warehouse_dwell_days: number | null;
    maximum_warehouse_dwell_days: number | null;
};

export default function WarehouseDwellReport({
    rows,
    summary,
    filters,
    backHref,
}: {
    rows: Paginator<DwellRow>;
    summary: Summary;
    filters: { from: string; to: string };
    backHref: string;
}) {
    return (
        <>
            <Head title="Warehouse Dwell Time Report" />
            <ReportLayout
                title="Warehouse dwell time report"
                period={formatDateRangePeriod(filters.from, filters.to)}
                backHref={backHref}
                filterBar={
                    <DateRangeFilter
                        from={filters.from}
                        to={filters.to}
                        basePath={window.location.pathname}
                    />
                }
            >
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-5 print:grid-cols-5">
                    <ReportStatCard
                        label="Delivered item lines"
                        value={formatNumber(summary.delivered_lines)}
                    />
                    <ReportStatCard
                        label="Average warehouse holding"
                        value={days(summary.average_line_warehouse_holding_days)}
                    />
                    <ReportStatCard
                        label="Average warehouse dwell"
                        value={days(summary.average_line_warehouse_dwell_days)}
                    />
                    <ReportStatCard
                        label="Maximum batch dwell"
                        value={days(summary.maximum_warehouse_dwell_days)}
                    />
                    <ReportStatCard
                        label="Fully dated lines"
                        value={`${formatNumber(summary.fully_dated_lines)} / ${formatNumber(summary.delivered_lines)}`}
                        detail={`${formatNumber(summary.date_coverage_percent)}% of lines`}
                    />
                </div>

                <ReportSection title="Customer Deliveries">
                    <div className="overflow-x-auto border-black border-t-2 border-b">
                        <table className="w-full text-left font-sans text-xs">
                            <thead className="border-black border-b">
                                <tr>
                                    <th className="px-2 py-1 font-bold text-[10px] uppercase tracking-wider">
                                        Customer / reference
                                    </th>
                                    <th className="px-2 py-1 font-bold text-[10px] uppercase tracking-wider">
                                        Item
                                    </th>
                                    <th className="px-2 py-1 text-right font-bold text-[10px] uppercase tracking-wider">
                                        Quantity
                                    </th>
                                    <th className="px-2 py-1 font-bold text-[10px] uppercase tracking-wider">
                                        Warehouse placement range
                                    </th>
                                    <th className="px-2 py-1 font-bold text-[10px] uppercase tracking-wider">
                                        Dispatched
                                    </th>
                                    <th className="px-2 py-1 font-bold text-[10px] uppercase tracking-wider">
                                        Customer delivered
                                    </th>
                                    <th className="px-2 py-1 text-right font-bold text-[10px] uppercase tracking-wider">
                                        Warehouse holding
                                    </th>
                                    <th className="px-2 py-1 text-right font-bold text-[10px] uppercase tracking-wider">
                                        Warehouse dwell
                                    </th>
                                    <th className="px-2 py-1 text-right font-bold text-[10px] uppercase tracking-wider">
                                        Dated quantity
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-black/20">
                                {rows.data.map((row) => (
                                    <tr key={row.id} className="align-top">
                                        <td className="px-2 py-2">
                                            <p className="font-medium">{row.customer_name}</p>
                                            <p className="text-[10px] text-black/60 italic">
                                                {row.delivery_reference ??
                                                    `Delivery #${row.delivery_id}`}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2">
                                            <p className="font-medium">{row.description}</p>
                                            <p className="text-[10px] text-black/60 italic">
                                                {row.sku_number ?? 'No SKU'}
                                            </p>
                                            <AllocationDetails
                                                allocations={row.allocations}
                                                unit={row.unit}
                                            />
                                        </td>
                                        <td className="px-2 py-2 text-right tabular-nums">
                                            {formatNumber(row.quantity)} {row.unit ?? ''}
                                        </td>
                                        <td className="px-2 py-2 tabular-nums">
                                            {dateRange(row.first_received_at, row.last_received_at)}
                                        </td>
                                        <td className="px-2 py-2 tabular-nums">
                                            {row.dispatched_at}
                                        </td>
                                        <td className="px-2 py-2 tabular-nums">
                                            {row.delivered_at}
                                        </td>
                                        <td className="px-2 py-2 text-right tabular-nums">
                                            {days(row.warehouse_holding_days)}
                                        </td>
                                        <td className="px-2 py-2 text-right font-semibold tabular-nums">
                                            {days(row.warehouse_dwell_days)}
                                        </td>
                                        <td className="px-2 py-2 text-right tabular-nums">
                                            <span
                                                className={
                                                    row.date_coverage_percent < 100
                                                        ? 'font-semibold text-amber-700'
                                                        : 'text-emerald-700'
                                                }
                                            >
                                                {formatNumber(row.date_coverage_percent)}%
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                                {rows.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="py-12 text-center text-[11px] text-black/60 italic"
                                        >
                                            No customer deliveries were completed in this date
                                            range.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </ReportSection>
                <div className="report-no-print mt-4">
                    <PaginationNav
                        currentPage={rows.current_page}
                        lastPage={rows.last_page}
                        previousUrl={rows.prev_page_url}
                        nextUrl={rows.next_page_url}
                        label="Warehouse dwell report pages"
                    />
                </div>
            </ReportLayout>
        </>
    );
}

function AllocationDetails({
    allocations,
    unit,
}: {
    allocations: Allocation[];
    unit: string | null;
}) {
    if (allocations.length <= 1) {
        return null;
    }

    return (
        <div className="mt-1 max-w-md">
            <div className="font-medium text-[10px] text-black/70 uppercase tracking-wide">
                {allocations.length} FIFO batch allocations
            </div>
            <div className="mt-1 space-y-0.5 border-black/20 border-l-2 pl-2 text-[10px] text-black/70 italic">
                {allocations.map((allocation) => (
                    <p key={allocation.id}>
                        {formatNumber(allocation.quantity)} {unit ?? ''} placed{' '}
                        {allocation.received_at ?? 'on an unknown date'}
                        {' - '}
                        {allocation.warehouse_dwell_days === null
                            ? 'dwell unknown'
                            : `${allocation.warehouse_dwell_days} days`}
                        {allocation.warehouse_holding_days === null
                            ? ''
                            : ` - ${allocation.warehouse_holding_days} days holding`}
                        {allocation.po_number ? ` - PO ${allocation.po_number}` : ''}
                        {allocation.lot_number ? ` - batch ${allocation.lot_number}` : ''}
                        {allocation.received_date_quality === 'estimated'
                            ? ' - estimated date'
                            : ''}
                    </p>
                ))}
            </div>
        </div>
    );
}

function dateRange(first: string | null, last: string | null) {
    if (first === null || last === null) return 'Unknown';
    return first === last ? first : `${first} to ${last}`;
}

function days(value: number | null) {
    return value === null ? 'Unknown' : `${formatNumber(value)} days`;
}

function formatNumber(value: number) {
    return value.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

WarehouseDwellReport.layout = {
    breadcrumbs: [
        { title: 'Purchase orders', href: '/admin/purchase-orders' },
        { title: 'Reports', href: '/admin/purchase-orders/reports' },
        { title: 'Warehouse dwell time', href: '/admin/purchase-orders/reports/warehouse-dwell' },
    ],
};
