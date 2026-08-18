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
    quantity: number;
    unit: string | null;
    data_source: 'verified' | 'unverified';
};

type Row = {
    schedule_id: number;
    sku_number: string | null;
    description: string;
    target_quantity: number;
    ordered_quantity: number;
    missing_quantity: number;
    unit: string | null;
    expected_week: number | null;
    schedule_label: string;
    status: 'not_ordered' | 'short';
    orders: Order[];
    has_unverified_data: boolean;
};

type Filters = {
    month: string;
    week: number | null;
};

type Summary = {
    item_count: number;
    not_ordered_count: number;
    short_count: number;
};

function GroupedWeekRows({ weekLabel, rows }: { weekLabel: string; rows: Row[] }) {
    const colCount = 5;
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
                <tr key={row.schedule_id}>
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
                        {row.orders.length > 0 && (
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
                                        {formatQuantity(order.quantity)}{' '}
                                        {order.unit ?? row.unit ?? ''}
                                    </span>
                                ))}
                            </div>
                        )}
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatQuantity(row.target_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatQuantity(row.ordered_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5 text-right font-bold tabular-nums">
                        {formatQuantity(row.missing_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5">
                        {row.status === 'not_ordered' ? 'No order placed' : 'Under target'}
                    </td>
                </tr>
            ))}
        </>
    );
}

export default function MissingItemsReport({
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
            <Head title="Items Not Ordered Report" />
            <ReportLayout
                title="Items Not Ordered"
                period={formatReportPeriod(filters.month, filters.week)}
                filterBar={
                    <MonthWeekFilter
                        month={filters.month}
                        week={filters.week}
                        basePath="/admin/purchase-orders/reports/missing-items"
                    />
                }
            >
                <div className="grid gap-2 sm:grid-cols-3">
                    <ReportStatCard label="Missing or short" value={summary.item_count} />
                    <ReportStatCard label="Not ordered" value={summary.not_ordered_count} />
                    <ReportStatCard label="Short quantity" value={summary.short_count} />
                </div>

                <ReportSection title="Missing Items">
                    {rows.length === 0 ? (
                        <div className="border border-black p-6 text-center font-serif text-black/60 text-sm italic">
                            All scheduled items met their target for this period.
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
                                            Missing
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

function formatQuantity(value: number) {
    return value.toLocaleString(undefined, {
        maximumFractionDigits: 3,
    });
}

MissingItemsReport.layout = {
    breadcrumbs: [
        { title: 'Purchase orders', href: '/admin/purchase-orders' },
        { title: 'Reports', href: '/admin/purchase-orders/reports' },
        { title: 'Items not ordered', href: '/admin/purchase-orders/reports/missing-items' },
    ],
};
