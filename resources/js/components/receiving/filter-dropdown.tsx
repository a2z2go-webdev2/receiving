import { SlidersHorizontal } from 'lucide-react';
import { type ReactNode, useEffect, useId, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function FilterDropdown({
    open,
    onOpenChange,
    activeCount = 0,
    className,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    activeCount?: number;
    className?: string;
    children: ReactNode;
}) {
    const contentId = useId();
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        function closeOnOutsidePointer(event: PointerEvent) {
            const target = event.target;
            if (!(target instanceof Element)) return;
            if (containerRef.current?.contains(target)) return;
            if (
                target.closest('[data-slot="select-content"], [data-radix-popper-content-wrapper]')
            ) {
                return;
            }

            onOpenChange(false);
        }

        function closeOnEscape(event: KeyboardEvent) {
            if (event.key === 'Escape') onOpenChange(false);
        }

        document.addEventListener('pointerdown', closeOnOutsidePointer);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeOnOutsidePointer);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open, onOpenChange]);

    return (
        <div ref={containerRef} className="relative">
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-7 gap-1 whitespace-nowrap px-2.5 text-xs"
                aria-expanded={open}
                aria-controls={contentId}
                aria-haspopup="dialog"
                onClick={() => onOpenChange(!open)}
            >
                <SlidersHorizontal className="size-3" /> Filters
                {activeCount > 0 && (
                    <span className="ml-0.5 rounded-full bg-primary px-1 text-[9px] text-primary-foreground leading-none">
                        {activeCount}
                    </span>
                )}
            </Button>
            {open && (
                <div
                    id={contentId}
                    role="dialog"
                    aria-label="More filters"
                    className={cn(
                        'absolute top-full right-0 z-50 mt-2 w-[min(24rem,calc(100vw-2rem))] rounded-lg border bg-popover p-4 text-popover-foreground shadow-lg',
                        className,
                    )}
                >
                    {children}
                </div>
            )}
        </div>
    );
}
