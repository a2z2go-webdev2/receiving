import { Link } from '@inertiajs/react';
import { CheckCircle2, PackageCheck, Truck } from 'lucide-react';
import { cn } from '@/lib/utils';

const steps = [
    {
        key: 'arrivals',
        number: 1,
        label: 'Confirm arrivals',
        href: '/warehouse/arrivals',
        icon: CheckCircle2,
    },
    {
        key: 'inventory',
        number: 2,
        label: 'Inventory',
        href: '/warehouse/inventory',
        icon: PackageCheck,
    },
    {
        key: 'deliveries',
        number: 3,
        label: 'Customer deliveries',
        href: '/warehouse/deliveries',
        icon: Truck,
    },
] as const;

export function WarehouseProcessNav({ current }: { current: (typeof steps)[number]['key'] }) {
    return (
        <nav
            aria-label="Warehouse process"
            className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-sm sm:flex-row"
        >
            {steps.map((step, index) => {
                const Icon = step.icon;
                const active = current === step.key;
                return (
                    <Link
                        key={step.key}
                        href={step.href}
                        aria-current={active ? 'step' : undefined}
                        className={cn(
                            'group relative flex flex-1 items-center gap-3 px-4 py-3 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset sm:px-5',
                            active
                                ? 'bg-primary/[0.03] text-primary'
                                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                            index !== 0 && 'border-border border-t sm:border-t-0 sm:border-l',
                        )}
                    >
                        {active && (
                            <div className="absolute inset-y-0 left-0 w-1 bg-primary sm:w-0.5" />
                        )}
                        <span
                            className={cn(
                                'flex size-7 shrink-0 items-center justify-center rounded-full font-semibold text-xs ring-1 ring-inset transition-colors',
                                active
                                    ? 'bg-primary/10 text-primary ring-primary/20'
                                    : 'bg-muted text-muted-foreground ring-border group-hover:bg-muted/80 group-hover:text-foreground',
                            )}
                        >
                            {step.number}
                        </span>
                        <span className="flex items-center gap-2">
                            <Icon className="size-4 shrink-0" />
                            <span className="font-medium text-sm">{step.label}</span>
                        </span>
                    </Link>
                );
            })}
        </nav>
    );
}
