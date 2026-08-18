<?php

namespace App\Features\Receiving\Services;

class ProductionReadinessCheck
{
    /** @return array<int, array{name: string, passed: bool, message: string}> */
    public function evaluate(): array
    {
        $queueConnection = (string) config('queue.default');
        $retryAfter = config("queue.connections.{$queueConnection}.retry_after");
        $workerTimeout = (int) config('receiving.queue.workload_timeout_seconds', 300);

        return [
            $this->check('Production environment', app()->environment('production'), 'APP_ENV must be production.'),
            $this->check('Debug disabled', config('app.debug') === false, 'APP_DEBUG must be false.'),
            $this->check('Application key', filled(config('app.key')), 'APP_KEY must be configured.'),
            $this->check(
                'HTTPS application URL',
                str_starts_with(mb_strtolower((string) config('app.url')), 'https://'),
                'APP_URL must use HTTPS.',
            ),
            $this->check(
                'Durable encrypted session',
                in_array(config('session.driver'), ['database', 'redis'], true) && config('session.encrypt') === true,
                'Use database or Redis sessions with SESSION_ENCRYPT=true.',
            ),
            $this->check(
                'Secure session cookie',
                config('session.secure') === true && in_array(config('session.same_site'), ['lax', 'strict'], true),
                'Set SESSION_SECURE_COOKIE=true and SameSite=lax or strict.',
            ),
            $this->check(
                'Asynchronous queue',
                $this->usesDurableQueue($queueConnection),
                'QUEUE_CONNECTION must use a durable asynchronous backend.',
            ),
            $this->check(
                'Shared cache locks',
                in_array(config('cache.default'), ['database', 'redis'], true),
                'CACHE_STORE must be database or Redis so unique jobs coordinate across instances.',
            ),
            $this->check(
                'Failed-job persistence',
                config('queue.failed.driver') !== 'null',
                'QUEUE_FAILED_DRIVER must persist failed jobs.',
            ),
            $this->check(
                'Queue retry lease',
                ! is_numeric($retryAfter) || (int) $retryAfter > $workerTimeout,
                "The {$queueConnection} retry_after value must exceed the {$workerTimeout}-second workload timeout.",
            ),
            $this->check(
                'Private object storage',
                config('receiving.disk') === 'r2'
                    && filled(config('filesystems.disks.r2.key'))
                    && filled(config('filesystems.disks.r2.secret'))
                    && filled(config('filesystems.disks.r2.endpoint')),
                'Use the configured private R2 disk and credentials.',
            ),
            $this->check(
                'Production mail transport',
                ! in_array(config('mail.default'), ['array', 'log'], true),
                'MAIL_MAILER must use a production transport.',
            ),
            $this->check(
                'Gemini configuration',
                filled(config('services.gemini.key'))
                    && filled(config('services.gemini.model'))
                    && str_starts_with((string) config('services.gemini.base_url'), 'https://'),
                'Configure the Gemini key, model, and HTTPS base URL.',
            ),
            $this->check(
                'Fail-closed malware scanner',
                (
                    config('receiving.scanner.driver') === 'cloudmersive'
                    && filled(config('services.cloudmersive.key'))
                    && str_starts_with((string) config('services.cloudmersive.base_url'), 'https://')
                ) || (
                    config('receiving.scanner.driver') === 'clamav'
                    && filled(config('receiving.scanner.host'))
                    && (int) config('receiving.scanner.port') > 0
                ),
                'Production must use configured Cloudmersive credentials. ClamAV is accepted only as the temporary rollback driver.',
            ),
            $this->check(
                'Cloudmersive free-tier guardrails',
                config('receiving.scanner.driver') !== 'cloudmersive' || (
                    (int) config('receiving.scanner.cloudmersive.monthly_call_limit') >= 1
                    && (int) config('receiving.scanner.cloudmersive.monthly_call_limit') <= 800
                    && (int) config('receiving.scanner.cloudmersive.minimum_interval_milliseconds') >= 1000
                    && (int) config('receiving.scanner.cloudmersive.max_file_kilobytes') >= 1
                    && (int) config('receiving.scanner.cloudmersive.max_file_kilobytes') <= 3584
                ),
                'Keep the configured allowance at 800 calls/month or less, requests at least one second apart, and files at 3.5 MB or less for the free tier.',
            ),
        ];
    }

    /** @return array{name: string, passed: bool, message: string} */
    private function check(string $name, bool $passed, string $failureMessage): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'message' => $passed ? 'Ready' : $failureMessage,
        ];
    }

    private function usesDurableQueue(string $connection): bool
    {
        if (in_array($connection, ['sync', 'null', 'deferred', 'background'], true)) {
            return false;
        }

        if (config("queue.connections.{$connection}.driver") !== 'failover') {
            return true;
        }

        $connections = config("queue.connections.{$connection}.connections", []);

        return is_array($connections)
            && $connections !== []
            && collect($connections)->every(fn (mixed $candidate): bool => is_string($candidate)
                && $candidate !== $connection
                && $this->usesDurableQueue($candidate));
    }
}
