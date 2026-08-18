import { Head, Link } from '@inertiajs/react';
import { ArrowRight, ClipboardCheck, Info, PackageCheck, Truck } from 'lucide-react';
import { PageShell } from '@/components/receiving/page-shell';
import { Button } from '@/components/ui/button';

type Summary = {
    pending_arrivals: number;
    inventory_items: number;
    stock_lots: number;
    draft_deliveries: number;
    dispatched_deliveries: number;
};

export default function WarehouseDashboard({ summary }: { summary: Summary }) {
    const steps = [
        {
            number: 1,
            stepLabel: 'STEP 1',
            title: 'Confirm arrivals',
            description:
                'Review linked PO/invoice deliveries and confirm physical quantity placed into warehouse stock.',
            href: '/warehouse/arrivals',
            action: summary.pending_arrivals > 0 ? 'Review arrivals' : 'Open arrivals',
            metric: `${summary.pending_arrivals.toLocaleString()} waiting`,
            icon: ClipboardCheck,
            urgent: summary.pending_arrivals > 0,
            iconStyle:
                'bg-teal-50 text-teal-600 border-teal-200 dark:bg-teal-950/40 dark:text-teal-400 dark:border-teal-800',
        },
        {
            number: 2,
            stepLabel: 'STEP 2',
            title: 'Review inventory',
            description:
                'Check received, allocated, and available stock quantities across all stored batches.',
            href: '/warehouse/inventory',
            action: 'Open inventory',
            metric: `${summary.inventory_items.toLocaleString()} items / ${summary.stock_lots.toLocaleString()} batches`,
            icon: PackageCheck,
            urgent: false,
            iconStyle:
                'bg-indigo-50 text-indigo-600 border-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-400 dark:border-indigo-800',
        },
        {
            number: 3,
            stepLabel: 'STEP 3',
            title: 'Process deliveries',
            description:
                'Create customer deliveries, allocate stock by FIFO at dispatch, and confirm customer receipt.',
            href: '/warehouse/deliveries',
            action: 'Open deliveries',
            metric: `${summary.draft_deliveries.toLocaleString()} drafts / ${summary.dispatched_deliveries.toLocaleString()} out`,
            icon: Truck,
            urgent: summary.draft_deliveries + summary.dispatched_deliveries > 0,
            iconStyle:
                'bg-blue-50 text-blue-600 border-blue-200 dark:bg-blue-950/40 dark:text-blue-400 dark:border-blue-800',
        },
    ];

    return (
        <>
            <Head title="Warehouse Process" />
            <PageShell
                title="Warehouse process"
                description="Follow the three steps in order so every delivery has an auditable warehouse-arrival and FIFO history."
            >
                {/* Vertical Timeline Process Flow */}
                <div className="relative mt-6 ml-3 space-y-4 border-primary/20 border-l-2 pl-6 dark:border-primary/30">
                    {steps.map((step) => {
                        const Icon = step.icon;

                        return (
                            <div key={step.number} className="group relative">
                                {/* Timeline Node Badge on the left vertical line */}
                                <div
                                    className={`absolute top-3.5 -left-[37px] flex size-7 items-center justify-center rounded-full border-2 font-bold text-xs shadow-xs transition-transform duration-200 group-hover:scale-110 ${
                                        step.urgent
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-muted-foreground/30 bg-background text-muted-foreground'
                                    }`}
                                >
                                    {step.number}
                                </div>

                                {/* Row Card */}
                                <div
                                    className={`flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs transition-all duration-200 hover:border-primary/40 hover:shadow-md md:flex-row md:items-center md:justify-between ${
                                        step.urgent ? 'border-primary/30 bg-card' : ''
                                    }`}
                                >
                                    {/* Left: Icon, Title, Description */}
                                    <div className="flex flex-1 items-start gap-3.5">
                                        <div
                                            className={`mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg border ${step.iconStyle}`}
                                        >
                                            <Icon className="size-4.5" />
                                        </div>
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-bold font-mono text-[10px] text-muted-foreground/70 uppercase tracking-wider">
                                                    {step.stepLabel}
                                                </span>
                                                <h3 className="font-semibold text-foreground text-sm transition-colors group-hover:text-primary">
                                                    {step.title}
                                                </h3>
                                            </div>
                                            <p className="max-w-2xl text-muted-foreground text-xs leading-relaxed">
                                                {step.description}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Right: Status Pill & Action Button */}
                                    <div className="flex shrink-0 items-center justify-between gap-4 border-border/50 border-t pt-3 md:border-t-0 md:pt-0">
                                        <span
                                            className={`inline-flex items-center rounded-full px-2.5 py-1 font-medium text-xs ring-1 ring-inset ${
                                                step.urgent
                                                    ? 'bg-amber-50 font-semibold text-amber-700 ring-amber-600/20 dark:bg-amber-950/50 dark:text-amber-400 dark:ring-amber-500/30'
                                                    : 'bg-muted/80 text-muted-foreground ring-border/80'
                                            }`}
                                        >
                                            {step.metric}
                                        </span>

                                        <Button
                                            asChild
                                            size="sm"
                                            className="h-8.5 font-medium text-xs"
                                            variant={step.urgent ? 'default' : 'outline'}
                                        >
                                            <Link
                                                href={step.href}
                                                className="flex items-center gap-1.5"
                                            >
                                                {step.action}
                                                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {/* Bottom Info Banner */}
                <div className="mt-6 rounded-xl border border-blue-100/60 bg-gradient-to-r from-blue-50/70 via-indigo-50/50 to-blue-50/30 p-3.5 text-blue-900 shadow-2xs dark:border-blue-900/50 dark:from-blue-950/30 dark:to-indigo-950/30 dark:text-blue-200">
                    <div className="flex items-center gap-2.5 text-xs">
                        <div className="shrink-0 rounded-full bg-blue-100 p-1 text-blue-600 dark:bg-blue-900/50 dark:text-blue-400">
                            <Info className="size-3.5" />
                        </div>
                        <div>
                            <span className="font-semibold text-blue-950 dark:text-blue-100">
                                Why order matters:
                            </span>{' '}
                            <span className="text-blue-800/90 dark:text-blue-300/90">
                                Confirming an arrival creates stock. Inventory shows available
                                stock. Dispatch reserves oldest eligible stock (FIFO) before
                                delivery completion.
                            </span>
                        </div>
                    </div>
                </div>
            </PageShell>
        </>
    );
}
