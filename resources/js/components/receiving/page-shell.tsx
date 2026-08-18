import { usePage } from '@inertiajs/react';
import { type ReactNode, useContext, useLayoutEffect } from 'react';
import { ReceivingLayoutContext } from '@/layouts/receiving-layout';
import { cn } from '@/lib/utils';

export const compactContentClassName = [
    '[&_[data-slot=card]]:gap-3 [&_[data-slot=card]]:rounded-lg [&_[data-slot=card]]:py-3',
    '[&_[data-slot=card-content]]:px-4 [&_[data-slot=card-header]]:px-4',
    '[&_[data-slot=card-title]]:text-sm [&_[data-slot=card-description]]:text-xs',
    '[&_.grid]:gap-3',
    '[&_section]:space-y-2',
    '[&_section_h2]:text-base',
    '[&_table]:text-xs',
    '[&_td]:px-3 [&_td]:py-1.5',
    '[&_th]:px-3 [&_th]:py-1.5 [&_th]:font-medium',
    '[&_label]:text-xs',
    '[&_input:not([type=checkbox]):not([type=radio])]:h-8 [&_input]:text-xs',
    '[&_select]:h-8 [&_select]:text-xs',
    '[&_textarea]:text-xs',
    '[&_table_button]:h-7 [&_table_button]:px-2 [&_table_button]:text-xs',
].join(' ');

export const adminCompactContentClassName = [
    '[&_[data-slot=card]]:gap-1.5 [&_[data-slot=card]]:rounded-md [&_[data-slot=card]]:py-2',
    '[&_[data-slot=card-content]]:px-2.5 [&_[data-slot=card-header]]:px-2.5',
    '[&_[data-slot=card-title]]:text-xs [&_[data-slot=card-description]]:text-[10px]',
    '[&_.grid]:gap-2',
    '[&_section]:space-y-1',
    '[&_section_h2]:text-xs',
    '[&_table]:text-[11px]',
    '[&_td]:px-2 [&_td]:py-0.5',
    '[&_th]:px-2 [&_th]:py-0.5 [&_th]:font-medium',
    '[&_label]:text-[10px]',
    '[&_input:not([type=checkbox]):not([type=radio])]:h-7 [&_input]:text-[10px]',
    '[&_select]:h-7 [&_select]:text-[10px]',
    '[&_textarea]:text-[10px]',
    '[&_table_button]:h-6 [&_table_button]:px-1 [&_table_button]:text-[10px]',
].join(' ');

export function PageShell({
    title,
    description,
    actions,
    hideHeader = false,
    children,
}: {
    title: string;
    description: string;
    actions?: ReactNode;
    hideHeader?: boolean;
    children: ReactNode;
}) {
    const component = usePage().component;
    const isWarehouse = component.startsWith('warehouse/');
    const isCompactLayout = component.startsWith('admin/') || isWarehouse;
    const isAdminCompact = component.startsWith('admin/');

    const { setHeaderContent } = useContext(ReceivingLayoutContext);

    const headerContentNode = (
        <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div className="min-w-0 max-w-3xl">
                <h1
                    className={cn(
                        'truncate font-semibold text-lg tracking-tight sm:whitespace-normal sm:text-xl',
                        !isCompactLayout && 'md:text-2xl',
                    )}
                >
                    {title}
                </h1>
                <p
                    className={cn(
                        'mt-0.5 truncate text-muted-foreground sm:whitespace-normal',
                        isCompactLayout ? 'text-[11px]' : 'text-xs md:text-sm',
                    )}
                >
                    {description}
                </p>
            </div>
            {actions && (
                <div className="flex shrink-0 items-center overflow-x-auto pb-1 sm:pb-0">
                    {actions}
                </div>
            )}
        </div>
    );

    useLayoutEffect(() => {
        if (isWarehouse && !hideHeader) {
            setHeaderContent(headerContentNode);
            return () => setHeaderContent(null);
        }
    }, [isWarehouse, hideHeader, setHeaderContent, headerContentNode]);

    return (
        <main
            className={cn(
                'mx-auto flex w-full flex-1 flex-col',
                isCompactLayout
                    ? 'max-w-6xl gap-3 px-4 py-3 sm:px-6 md:py-4'
                    : 'max-w-6xl gap-3 px-4 py-3 sm:px-6 md:py-4',
            )}
        >
            {!hideHeader && !isWarehouse && (
                <header
                    className={cn(
                        'flex flex-col gap-1 border-b sm:flex-row sm:items-end sm:justify-between',
                        isCompactLayout ? 'pb-1' : 'pb-3',
                    )}
                >
                    {headerContentNode}
                </header>
            )}
            <div
                className={cn(
                    'flex flex-col gap-2',
                    isAdminCompact ? adminCompactContentClassName : compactContentClassName,
                )}
            >
                {children}
            </div>
        </main>
    );
}
