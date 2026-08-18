import { Head } from '@inertiajs/react';
import { ReportLayout, ReportSection, ReportStatCard } from '@/components/reports/report-layout';
import { groupRowsByWeek } from '@/components/reports/week-grouping';

type Row = {
    schedule_id: number;
    sku_number: string | null;
    description: string;
    target_quantity: number;
    unit: string | null;
    expected_week: number | null;
    schedule_label: string;
    notes: string | null;
};

type Summary = {
    item_count: number;
    monthly_count: number;
};

function GroupedWeekRows({ weekLabel, rows }: { weekLabel: string; rows: Row[] }) {
    return (
        <>
            <tr className="report-week-group bg-gray-100/70">
                <th
                    colSpan={3}
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
                        <p className="text-[11px] text-black/60">{row.sku_number || 'No SKU'}</p>
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatQuantity(row.target_quantity)} {row.unit ?? ''}
                    </td>
                    <td className="px-2 py-1.5 text-black/70">{row.notes || '-'}</td>
                </tr>
            ))}
        </>
    );
}

export default function RecurringItemsReport({ rows, summary }: { rows: Row[]; summary: Summary }) {
    return (
        <>
            <Head title="Recurring PO Items Report" />
            <ReportLayout title="Recurring PO Items">
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <ReportStatCard label="Total Monthly Target Items" value={summary.item_count} />
                </div>

                <ReportSection title="Monthly Item Schedule">
                    {rows.length === 0 ? (
                        <div className="border border-black p-6 text-center font-serif text-black/60 text-sm italic">
                            No active monthly PO item records are configured.
                        </div>
                    ) : (
                        <div className="overflow-x-auto border border-black">
                            <table className="w-full border-collapse text-left font-sans text-xs">
                                <thead className="border-black border-b-2 bg-gray-50/50">
                                    <tr>
                                        <th className="px-2 py-1.5 font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Item
                                        </th>
                                        <th className="px-2 py-1.5 text-right font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Monthly target
                                        </th>
                                        <th className="px-2 py-1.5 font-bold text-[10px] text-black/70 uppercase tracking-wider">
                                            Notes
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

RecurringItemsReport.layout = {
    breadcrumbs: [
        { title: 'Purchase orders', href: '/admin/purchase-orders' },
        { title: 'Reports', href: '/admin/purchase-orders/reports' },
        { title: 'Recurring items', href: '/admin/purchase-orders/reports/recurring-items' },
    ],
};
