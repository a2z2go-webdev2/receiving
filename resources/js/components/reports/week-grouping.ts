export type WeekGroup<T> = {
    weekKey: string;
    weekLabel: string;
    rows: T[];
};

/**
 * Group rows under their schedule label ("Monthly target"),
 * preserving the original sort order.
 */
export function groupRowsByWeek<
    T extends { expected_week?: number | null; schedule_label: string },
>(rows: T[]): WeekGroup<T>[] {
    if (rows.length === 0) return [];

    return [
        {
            weekKey: 'monthly-target',
            weekLabel: 'Monthly Target',
            rows,
        },
    ];
}

const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/**
 * Build a human-readable report period string from the month filter.
 */
export function formatReportPeriod(
    month: string | null | undefined,
    _week?: number | null | undefined,
): string {
    const monthLabel = formatMonthLabel(month);

    if (monthLabel) {
        return monthLabel;
    }

    return 'All time';
}

function formatMonthLabel(month: string | null | undefined): string | null {
    if (!month) return null;
    const match = /^(\d{4})-(\d{2})$/.exec(month);
    if (!match) return month;
    const year = Number(match[1]);
    const monthIndex = Number(match[2]) - 1;
    if (monthIndex < 0 || monthIndex > 11) return month;
    return `${MONTH_NAMES[monthIndex]} ${year}`;
}

/**
 * Build a human-readable report period string from a date range.
 */
export function formatDateRangePeriod(
    from: string | null | undefined,
    to: string | null | undefined,
): string | undefined {
    if (!from && !to) return undefined;
    if (from && !to) return `From ${formatDateString(from)}`;
    if (!from && to) return `Until ${formatDateString(to)}`;
    if (from === to) return formatDateString(from as string);

    const fromMatch = /^(\d{4})-(\d{2})-01$/.exec(from as string);
    if (fromMatch) {
        const year = Number(fromMatch[1]);
        const month = Number(fromMatch[2]);
        const daysInMonth = new Date(year, month, 0).getDate();
        if (to === `${fromMatch[1]}-${fromMatch[2]}-${daysInMonth}`) {
            return formatMonthLabel(`${fromMatch[1]}-${fromMatch[2]}`) as string;
        }
    }

    return `${formatDateString(from as string)} \u2014 ${formatDateString(to as string)}`;
}

function formatDateString(date: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
    if (!match) return date;
    const year = Number(match[1]);
    const monthIndex = Number(match[2]) - 1;
    const day = Number(match[3]);
    if (monthIndex < 0 || monthIndex > 11) return date;
    return `${MONTH_NAMES[monthIndex]} ${day}, ${year}`;
}
