<?php

namespace App\Features\Receiving\Services;

use App\Features\Receiving\Exceptions\MalwareScanDeferred;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;

class CloudmersiveScanGuard
{
    private const LOCK_KEY = 'receiving:cloudmersive:scan-lock';

    private const NEXT_REQUEST_AT_KEY = 'receiving:cloudmersive:next-request-at';

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $scan
     * @return TResult
     */
    public function run(Closure $scan): mixed
    {
        $timeout = max(1, (int) config('services.cloudmersive.timeout_seconds', 30));
        $lockTtl = max(60, $timeout + 15);
        $lockWait = max(0, (int) config('receiving.scanner.cloudmersive.lock_wait_seconds', 30));

        try {
            $lock = Cache::lock(self::LOCK_KEY, $lockTtl);

            return $lock->block($lockWait, function () use ($scan): mixed {
                $this->reserveMonthlyCall();
                $this->waitForRequestWindow();

                try {
                    return $scan();
                } catch (MalwareScanDeferred $error) {
                    $this->deferRequestsFor($error->retryAfterSeconds);

                    throw $error;
                }
            });
        } catch (LockTimeoutException) {
            throw new MalwareScanDeferred(
                'Malware scanning is busy and will retry automatically.',
                max(1, (int) config('receiving.scanner.cloudmersive.busy_retry_seconds', 5)),
            );
        }
    }

    private function reserveMonthlyCall(): void
    {
        $limit = max(1, (int) config('receiving.scanner.cloudmersive.monthly_call_limit', 800));
        $quotaNow = CarbonImmutable::now('UTC');
        $periodStart = $quotaNow->startOfMonth()->toDateString();

        DB::transaction(function () use ($limit, $periodStart, $quotaNow): void {
            DB::table('cloudmersive_scan_usages')->insertOrIgnore([
                'period_start' => $periodStart,
                'request_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usage = DB::table('cloudmersive_scan_usages')
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->first();

            if (! is_object($usage)) {
                throw new RuntimeException('Unable to reserve the malware scanner usage allowance.');
            }

            if ((int) $usage->request_count >= $limit) {
                $resetAt = $quotaNow->startOfMonth()->addMonth();
                $retryAfter = max(60, (int) $quotaNow->diffInSeconds($resetAt));

                throw new MalwareScanDeferred(
                    'Malware scanning is paused because the configured monthly allowance has been reached. Processing will retry automatically after the monthly reset.',
                    $retryAfter,
                );
            }

            DB::table('cloudmersive_scan_usages')
                ->where('period_start', $periodStart)
                ->update([
                    'request_count' => (int) $usage->request_count + 1,
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    private function waitForRequestWindow(): void
    {
        $minimumInterval = max(
            1000,
            (int) config('receiving.scanner.cloudmersive.minimum_interval_milliseconds', 1100),
        );
        $now = now()->getTimestampMs();
        $nextRequestAt = (int) Cache::get(self::NEXT_REQUEST_AT_KEY, 0);
        $waitMilliseconds = min($minimumInterval, max(0, $nextRequestAt - $now));

        if ($waitMilliseconds > 0) {
            Sleep::usleep($waitMilliseconds * 1000);
        }

        $stored = Cache::put(
            self::NEXT_REQUEST_AT_KEY,
            now()->getTimestampMs() + $minimumInterval,
            now()->addMinutes(10),
        );

        if (! $stored) {
            throw new MalwareScanDeferred(
                'Malware scanner coordination is temporarily unavailable and will retry automatically.',
                max(1, (int) config('receiving.scanner.cloudmersive.busy_retry_seconds', 5)),
            );
        }
    }

    private function deferRequestsFor(int $seconds): void
    {
        $seconds = max(1, $seconds);
        Cache::put(
            self::NEXT_REQUEST_AT_KEY,
            now()->addSeconds($seconds)->getTimestampMs(),
            now()->addSeconds($seconds + 60),
        );
    }
}
