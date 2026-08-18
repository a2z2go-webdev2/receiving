import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

export function ReportLayout({
    title,
    subtitle,
    period,
    filterBar,
    children,
    backHref = '/admin/purchase-orders/reports',
    backLabel = 'Back to reports',
}: {
    title: string;
    subtitle?: string;
    period?: string;
    filterBar?: ReactNode;
    children: ReactNode;
    backHref?: string;
    backLabel?: string;
}) {
    const generatedAt = new Date().toLocaleString();

    return (
        <div className="-m-4 min-h-[calc(100vh-2rem)] bg-slate-100 p-4 sm:-m-6 sm:min-h-[calc(100vh-3rem)] sm:p-8 lg:-m-8 lg:min-h-[calc(100vh-4rem)] lg:p-12 print:m-0 print:min-h-0 print:bg-transparent print:p-0">
            {/* Top Bar - Web App UI style, but hidden in print */}
            <div className="report-no-print mx-auto mb-4 flex max-w-[850px] items-center justify-between">
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="gap-1.5 text-slate-600 text-xs hover:text-slate-900"
                >
                    <Link href={backHref}>
                        <ArrowLeft className="size-3.5" />
                        {backLabel}
                    </Link>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    className="gap-1.5 border-slate-300 bg-white text-slate-700 text-xs shadow-sm hover:bg-slate-50"
                    onClick={() => window.print()}
                >
                    <Printer className="size-3.5" />
                    Print / Save PDF
                </Button>
            </div>

            {filterBar && (
                <div className="report-no-print mx-auto mb-4 max-w-[850px]">{filterBar}</div>
            )}

            {/* Document Container */}
            <div className="report-container mx-auto max-w-[850px] bg-white p-6 font-serif text-black shadow-xl ring-1 ring-black/5 md:p-10 print:p-0 print:shadow-none print:ring-0">
                {/* Report Header */}
                <header className="mb-6 border-black border-b-2 pb-3">
                    <h1 className="font-bold text-2xl text-black uppercase tracking-widest">
                        {title}
                    </h1>
                    {subtitle && <p className="mt-1 text-black/80 text-sm">{subtitle}</p>}
                    {period && (
                        <p className="mt-2 inline-block border border-black bg-gray-50 px-2.5 py-1 font-bold text-black text-xs uppercase tracking-widest print:border-black">
                            Report Period: {period}
                        </p>
                    )}
                    <p className="mt-2 text-[10px] text-black/60 uppercase tracking-widest">
                        Date Generated: {generatedAt}
                    </p>
                </header>

                {/* Report Content */}
                <div className="space-y-6">{children}</div>

                {/* Print footer */}
                <footer className="mt-8 border-black/50 border-t pt-3 text-center text-black/50 text-xs uppercase tracking-widest print:mt-6">
                    <p>End of Report • Generated on {generatedAt}</p>
                </footer>
            </div>
        </div>
    );
}

export function ReportSection({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-2">
            <div>
                <h2 className="mb-1 border-black/20 border-b pb-0.5 font-bold text-sm uppercase tracking-widest">
                    {title}
                </h2>
                {description && (
                    <p className="mt-0.5 mb-1 text-[11px] text-black/70 italic">{description}</p>
                )}
            </div>
            {children}
        </section>
    );
}

export function ReportStatCard({
    label,
    value,
    detail,
}: {
    label: string;
    value: string | number;
    detail?: string;
}) {
    return (
        <div className="border border-black p-2.5">
            <p className="font-bold text-[10px] text-black/60 uppercase tracking-widest">{label}</p>
            <p className="mt-0.5 font-bold font-sans text-lg tracking-tight">{value}</p>
            {detail && <p className="mt-0.5 text-[11px] text-black/70 italic">{detail}</p>}
        </div>
    );
}

export function DateRangeFilter({
    from,
    to,
    basePath,
}: {
    from: string;
    to: string;
    basePath: string;
}) {
    function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const params = new URLSearchParams();
        const fromVal = formData.get('from') as string;
        const toVal = formData.get('to') as string;
        if (fromVal) params.set('from', fromVal);
        if (toVal) params.set('to', toVal);
        const qs = params.toString();
        router.get(qs ? `${basePath}?${qs}` : basePath);
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="report-no-print mb-3 flex flex-wrap items-end gap-2 border border-black/20 bg-gray-50/50 p-2.5"
        >
            <div className="space-y-0.5">
                <label
                    htmlFor="report-from"
                    className="font-bold text-[10px] text-black/70 uppercase tracking-widest"
                >
                    Date From
                </label>
                <input
                    id="report-from"
                    type="date"
                    name="from"
                    defaultValue={from}
                    className="block h-7 border border-black/30 bg-white px-2 font-sans text-[11px] outline-none focus:border-black focus:ring-0"
                />
            </div>
            <div className="space-y-0.5">
                <label
                    htmlFor="report-to"
                    className="font-bold text-[10px] text-black/70 uppercase tracking-widest"
                >
                    Date To
                </label>
                <input
                    id="report-to"
                    type="date"
                    name="to"
                    defaultValue={to}
                    className="block h-7 border border-black/30 bg-white px-2 font-sans text-[11px] outline-none focus:border-black focus:ring-0"
                />
            </div>
            <Button
                type="submit"
                size="sm"
                variant="outline"
                className="h-7 rounded-none border-black px-2 font-sans text-[11px] text-black hover:bg-black hover:text-white"
            >
                Apply filter
            </Button>
            {(from || to) && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 rounded-none px-2 font-sans text-[11px] text-black hover:bg-black/5"
                    onClick={() => {
                        router.get(basePath);
                    }}
                >
                    Clear
                </Button>
            )}
        </form>
    );
}
