import assert from 'node:assert/strict';
import test from 'node:test';

import { waitForVite } from '../../scripts/wait-for-vite.mjs';

test('waits for the real React application entry by default', async () => {
    let attempts = 0;
    let requestedUrl;

    await waitForVite({
        fetchImpl: async (url) => {
            attempts += 1;
            requestedUrl = url;

            return { ok: true };
        },
        sleep: async () => {},
    });

    assert.equal(attempts, 1);
    assert.equal(
        requestedUrl,
        'http://localhost:5173/resources/js/app.tsx',
    );
});

test('retries transient connection failures until Vite becomes available', async () => {
    let attempts = 0;
    let elapsed = 0;

    await waitForVite({
        fetchImpl: async () => {
            attempts += 1;

            if (attempts < 3) {
                throw new Error('connection refused');
            }

            return { ok: true };
        },
        now: () => elapsed,
        retryDelayMs: 25,
        sleep: async (delay) => {
            elapsed += delay;
        },
        timeoutMs: 100,
    });

    assert.equal(attempts, 3);
    assert.equal(elapsed, 50);
});

test('rejects non-success responses and stops at the configured deadline', async () => {
    let attempts = 0;
    let elapsed = 0;

    await assert.rejects(
        waitForVite({
            fetchImpl: async () => {
                attempts += 1;

                return { ok: false, status: 503 };
            },
            now: () => elapsed,
            retryDelayMs: 25,
            sleep: async (delay) => {
                elapsed += delay;
            },
            timeoutMs: 50,
            url: 'http://localhost:5173/@vite/client',
        }),
        /Vite did not become ready within 50ms/,
    );

    assert.equal(attempts, 3);
});
