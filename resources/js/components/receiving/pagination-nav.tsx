import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function PaginationNav({
    currentPage,
    lastPage,
    previousUrl,
    nextUrl,
    label,
}: {
    currentPage: number;
    lastPage: number;
    previousUrl: string | null;
    nextUrl: string | null;
    label: string;
}) {
    if (lastPage <= 1) return null;

    return (
        <nav
            aria-label={label}
            className="ml-auto flex w-fit max-w-full items-center gap-1 rounded-full border bg-card p-1 shadow-xs"
        >
            <Button
                asChild={previousUrl !== null}
                variant="ghost"
                size="icon"
                className="size-11 rounded-full"
                disabled={previousUrl === null}
            >
                {previousUrl ? (
                    <Link href={previousUrl} preserveScroll aria-label="Previous page">
                        <ChevronLeft />
                    </Link>
                ) : (
                    <span>
                        <ChevronLeft />
                        <span className="sr-only">Previous page</span>
                    </span>
                )}
            </Button>
            <p className="min-w-20 px-2 text-center font-medium text-sm tabular-nums">
                <span className="sr-only">Page </span>
                {currentPage}
                <span className="px-1 text-muted-foreground">/</span>
                {lastPage}
            </p>
            <Button
                asChild={nextUrl !== null}
                variant="ghost"
                size="icon"
                className="size-11 rounded-full"
                disabled={nextUrl === null}
            >
                {nextUrl ? (
                    <Link href={nextUrl} preserveScroll aria-label="Next page">
                        <ChevronRight />
                    </Link>
                ) : (
                    <span>
                        <ChevronRight />
                        <span className="sr-only">Next page</span>
                    </span>
                )}
            </Button>
        </nav>
    );
}
