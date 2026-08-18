import { usePoll } from '@inertiajs/react';

export const LIVE_REFRESH_INTERVAL_MS = 5_000;

export function useLivePageData(only: string[], interval = LIVE_REFRESH_INTERVAL_MS): void {
    usePoll(
        interval,
        () => ({
            only,
            headers: { 'X-Live-Refresh': '1' },
        }),
        {
            keepAlive: false,
            mode: 'rest',
        },
    );
}
