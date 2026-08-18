import { Head, Link } from '@inertiajs/react';
import { MonthWeekFilter } from '@/components/reports/month-week-filter';
import { ReportLayout, ReportSection, ReportStatCard } from '@/components/reports/report-layout';
import { formatReportPeriod, groupRowsByWeek } from '@/components/reports/week-grouping';

type Order = {
    id: number;
    upload_id: number;
    serial_number: number;
    po_number: string | null;
    po_date: string | null;
    po_week: number | null;
    quantity: number;
    unit: string | null;
    matched_by: string;
    item_description: string | null;
    data_source: 'verified' | 'unverified';
};

type Arrival = {
    id: number;
    upload_id: number;
    serial_number: number;
    po_number: string | null;
    po_date: string | null;
    arrival_date: string | null;
    waiting_days: number | null;
    po_week: number | null;
    quantity: number;
    unit: string | null;
    matched_by: string;
    status: string;
    item_description: string | null;
    data_source: 'verified' | 'unverified';
};

type Row = {
    schedule_id: number;
    sku_number: string | null;
    description: string;
    target_quantity: number;
    ordered_quantity: number;
    arrived_quantity: number;
    remaining_quantity: number;
    arrival_remaining_quantity: number;
    unit: string | null;
    expected_week: number | null;
    schedule_label: string;
    first_arrival_date: string | null;
    last_arrival_date: string | null;
    average_waiting_days: number | null;
    max_waiting_days: number | null;
    status: 'fulfilled' | 'over_target' | 'short';
    orders: Order[];
    arrivals: Arrival[];
    has_unverified_data: boolean;
};

type Filters = {
    month: string;
    week: number | null;
};

type Summary = {
    item_count: number;
    fulfilled_count: number;
    short_count: number;
    arrived_count: number;
};

function GroupedWeekRows({ weekLabel, rows }: { weekLabel: string; rows: Row[] }) {
    const colCount = 7;
    return (
        <>
            <tr className="report-week-group bg-gray-100/70">
                <th
                    colSpan={colCount}
                    scope="colgroup"
                    className="border-black border-b px-2 py-1 text-center font-bold text-[10px] text-black uppercase tracking-widest"
                >
                    {weekLabel}
                </th>
            </tr>
            {rows.map((row) => (
                <tr key={row.schedule_id} className="align-top">
                    <td className="px-2 py-1.5">
                        <p className="font-medium">{row.description}</p>
                        <div className="flex flex-wrap items-center gap-1.5 text-[11px] text-black/60">
                            <span>{row.sku_number || 'No SKU'}</span>
                            {row.has_unverified_data && (
                                <span className="text-orange-600 text-xs">
                                    Contains unverified data
                                </span>
                            )}
                        </div>
                        <div className="mt-1 text-[10px] text-black/65">
                            {row.orders.map((order, index) => (
                                <span key={order.id}>
                                    {index > 0 && ', '}
                                    <Link
                                        className="font-semibold underline"
                                        href={`/admin/uploads/${order.upload_id}`}
                                    >
                                        {order.po_number || `POSN-${order.serial_number}`}
                                    </Link>
                                    {' - '}
                                    {formatQuantity(order.quantity)} {order.unit ?? row.unit ?? ''}
                                </span>
                            ))}
                        </div>
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatQuantity(row.target_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5 text-right font-bold tabular-nums">
                        {formatQuantity(row.ordered_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatQuantity(row.arrived_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatQuantity(row.arrival_remaining_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {row.arrivals.length === 0 ? (
                            '-'
                        ) : (
                            <div className="space-y-0.5">
                                {row.arrivals.map((arrival) => (
                                    <p key={arrival.id}>
                                        <span className="font-medium">
                                            {arrival.po_number || `POSN-${arrival.serial_number}`}
                                        </span>{' '}
                                        ·{' '}
                                        {arrival.waiting_days === null
                                            ? 'Not available'
                                            : formatWaitingDays(arrival.waiting_days)}
                                    </p>
                                ))}
                            </div>
                        )}
                    </td>
                    <td className="px-2 py-1.5">{statusLabel(row.status)}</td>
                </tr>
            ))}
        </>
    );
}

export default function OrderedItemsReport({
    rows,
    filters,
    summary,
}: {
    rows: Row[];
    filters: Filters;
    summary: Summary;
}) {
    return (
        <>
            <Head title="Items Ordered Report" />
            <ReportLayout
                title="Items Ordered"
                period={formatReportPeriod(filters.month, filters.week)}
                filterBar={
                    <MonthWeekFilter
                        month={filters.month}
                        week={filters.week}
                        basePath="/admin/purchase-orders/reports/ordered-items"
                    />
                }
            >
                <div className="grid gap-2 sm:grid-cols-3">
                    <ReportStatCard label="Items ordered" value={summary.item_count} />
                    <ReportStatCard label="Met target" value={summary.fulfilled_count} />
                    <ReportStatCard label="With arrivals" value={summary.arrived_count} />
                </div>

                <ReportSection title="Ordered Items">
                    {rows.length === 0 ? (
                        <div className="border border-black p-6 text-center font-serif text-black/60 text-sm italic">
                            No scheduled items were matched to uploaded POs for this period.
                        </div>
                    ) : (
                        <div className="border border-black">
                            <table className="w-full border-collapse text-left font-sans text-xs">
                                <thead className="border-black border-b-2 bg-gray-50/50">
                                    <tr>
                                        <th className="px-2 py-1.5 font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Item
                                        </th>
                                        <th className="px-2 py-1.5 text-right font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Target
                                        </th>
                                        <th className="px-2 py-1.5 text-right font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Ordered
                                        </th>
                                        <th className="px-2 py-1.5 text-right font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Arrived
                                        </th>
                                        <th className="px-2 py-1.5 text-right font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Awaiting
                                        </th>
                                        <th className="px-2 py-1.5 text-right font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Waiting
                                        </th>
                                        <th className="px-2 py-1.5 font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-black/10">
                                    {groupRowsByWeek(rows).map((group) => (
                                        <GroupedWeekRows
                                            key={group.weekKey}
                                            weekLabel={group.weekLabel}
                                            rows={group.rows}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </ReportSection>
            </ReportLayout>
        </>
    );
}

function statusLabel(status: Row['status']) {
    return {
        fulfilled: 'Fulfilled',
        over_target: 'Over target',
        short: 'Under target',
    }[status];
}

function _arrivalStatusLabel(status: string) {
    return (
        {
            matched: 'Matched',
            over: 'Over',
            short: 'Short',
            unmatched: 'Unmatched',
        }[status] ?? status
    );
}

function formatQuantity(value: number) {
    return value.toLocaleString(undefined, {
        maximumFractionDigits: 3,
    });
}

function formatWaitingDays(value: number) {
    const rounded = Number.isInteger(value) ? value : Number(value.toFixed(1));

    return `${rounded.toLocaleString(undefined, { maximumFractionDigits: 1 })} ${
        rounded === 1 ? 'day' : 'days'
    }`;
}

OrderedItemsReport.layout = {
    breadcrumbs: [
        { title: 'Purchase orders', href: '/admin/purchase-orders' },
        { title: 'Reports', href: '/admin/purchase-orders/reports' },
        { title: 'Items ordered', href: '/admin/purchase-orders/reports/ordered-items' },
    ],
};
