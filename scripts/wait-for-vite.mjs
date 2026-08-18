import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const DEFAULT_URL = 'http://localhost:5173/resources/js/app.tsx';
const DEFAULT_TIMEOUT_MS = 120_000;
const DEFAULT_REQUEST_TIMEOUT_MS = 1_000;
const DEFAULT_RETRY_DELAY_MS = 250;

const delay = (milliseconds) =>
    new Promise((resolveDelay) => setTimeout(resolveDelay, milliseconds));

export async function waitForVite({
    fetchImpl = globalThis.fetch,
    now = Date.now,
    requestTimeoutMs = DEFAULT_REQUEST_TIMEOUT_MS,
    retryDelayMs = DEFAULT_RETRY_DELAY_MS,
    sleep = delay,
    timeoutMs = DEFAULT_TIMEOUT_MS,
    url = DEFAULT_URL,
} = {}) {
    const deadline = now() + timeoutMs;

    while (now() <= deadline) {
        try {
            const response = await fetchImpl(url, {
                cache: 'no-store',
                signal: AbortSignal.timeout(requestTimeoutMs),
            });

            if (response.ok) {
                return;
            }
        } catch {
            // Vite is still starting. Retry until the bounded deadline below.
        }

        const remainingMs = deadline - now();

        if (remainingMs <= 0) {
            break;
        }

        await sleep(Math.min(retryDelayMs, remainingMs));
    }

    throw new Error(`Vite did not become ready within ${timeoutMs}ms (${url}).`);
}

function positiveInteger(value, fallback) {
    const parsed = Number.parseInt(value ?? '', 10);

    return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : fallback;
}

const isMainModule =
    process.argv[1] !== undefined &&
    import.meta.url === pathToFileURL(resolve(process.argv[1])).href;

if (isMainModule) {
    const url = process.env.VITE_DEV_SERVER_URL || DEFAULT_URL;
    const timeoutMs = positiveInteger(
        process.env.VITE_READY_TIMEOUT_MS,
        DEFAULT_TIMEOUT_MS,
    );

    try {
        await waitForVite({ timeoutMs, url });
        console.log(`Vite is ready at ${url}`);
    } catch (error) {
        console.error(error instanceof Error ? error.message : error);
        process.exitCode = 1;
    }
}
