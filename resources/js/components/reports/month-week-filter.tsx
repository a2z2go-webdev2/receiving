import { router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';

export function MonthWeekFilter({
    month,
    basePath,
}: {
    month: string;
    week?: number | null;
    basePath: string;
}) {
    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const params = new URLSearchParams();
        const monthValue = formData.get('month') as string;
        if (monthValue) params.set('month', monthValue);
        const query = params.toString();
        router.get(query ? `${basePath}?${query}` : basePath);
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="report-no-print mb-3 flex flex-wrap items-end gap-2 border border-black/20 bg-gray-50/50 p-2.5"
        >
            <div className="space-y-0.5">
                <label
                    htmlFor="report-month"
                    className="font-bold text-[10px] text-black/70 uppercase tracking-widest"
                >
                    Month
                </label>
                <input
                    id="report-month"
                    type="month"
                    name="month"
                    defaultValue={month}
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
            {month && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 rounded-none px-2 font-sans text-[11px] text-black hover:bg-black/5"
                    onClick={() => {
                        router.get(basePath);
                    }}
                >
                    Current month
                </Button>
            )}
        </form>
    );
}
