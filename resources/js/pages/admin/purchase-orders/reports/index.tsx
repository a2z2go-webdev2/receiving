import type { PageProps } from '@inertiajs/core';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck, ClipboardX, Repeat, Timer } from 'lucide-react';
import { PageShell } from '@/components/receiving/page-shell';
import { Button } from '@/components/ui/button';
import { Permission } from '@/types/enums/permission';

const reports = [
    {
        title: 'Items Ordered',
        description:
            'Scheduled items found in uploaded POs, with ordered quantity compared to target quantity.',
        href: '/admin/purchase-orders/reports/ordered-items',
        icon: ClipboardCheck,
    },
    {
        title: 'Items Not Ordered',
        description:
            'Monthly scheduled items that are missing or still short for the selected month.',
        href: '/admin/purchase-orders/reports/missing-items',
        icon: ClipboardX,
    },
    {
        title: 'Recurring PO Items',
        description: 'Monthly item records that need to be purchased every month.',
        href: '/admin/purchase-orders/reports/recurring-items',
        icon: Repeat,
    },
    {
        title: 'Warehouse Dwell Time',
        description:
            'Quantity-weighted days from warehouse placement to customer delivery, with holding time and unknown-date coverage.',
        href: '/admin/purchase-orders/reports/warehouse-dwell',
        icon: Timer,
        permission: Permission.ViewWarehouseReports,
    },
] as const;

export default function ReportsIndex() {
    const { auth } = usePage<PageProps>().props;
    const permissions = auth.user?.permissions ?? [];
    const visibleReports = reports.filter(
        (report) => !('permission' in report) || permissions.includes(report.permission),
    );

    return (
        <>
            <Head title="Purchase Order Reports" />
            <PageShell
                title="Purchase order reports"
                description="Generate and view printable reports for purchase order data."
                actions={
                    <Button asChild variant="outline" size="sm" className="gap-1.5 text-xs">
                        <Link href="/admin/purchase-orders">
                            <ArrowLeft className="size-3.5" />
                            Back to purchase orders
                        </Link>
                    </Button>
                }
            >
                <div className="mx-auto w-full max-w-5xl">
                    <h2 className="mb-2 border-black/30 border-b pb-1 font-bold text-black/70 text-xs uppercase tracking-widest">
                        Available Reports
                    </h2>
                    <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                        {visibleReports.map((report) => {
                            const Icon = report.icon;
                            return (
                                <Link
                                    key={report.href}
                                    href={report.href}
                                    className="group flex h-full flex-col gap-1.5 border border-black/30 bg-white p-3 transition-colors hover:border-black hover:bg-gray-50"
                                >
                                    <div className="flex items-center gap-2">
                                        <Icon className="size-3.5 shrink-0 text-black/60 transition-colors group-hover:text-black" />
                                        <h3 className="font-bold font-serif text-black text-sm uppercase tracking-wide group-hover:underline">
                                            {report.title}
                                        </h3>
                                    </div>
                                    <p className="text-[11px] text-black/70 leading-snug">
                                        {report.description}
                                    </p>
                                    <span className="mt-auto font-bold text-[10px] text-black/50 uppercase tracking-widest transition-colors group-hover:text-black">
                                        View Report →
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                </div>
            </PageShell>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [
        { title: 'Purchase orders', href: '/admin/purchase-orders' },
        { title: 'Reports', href: '/admin/purchase-orders/reports' },
    ],
};
