<?php

use App\Features\Receiving\Services\ProductionReadinessCheck;

beforeEach(function (): void {
    $this->app['env'] = 'production';
    config()->set([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app.url' => 'https://receiving.example.com',
        'session.driver' => 'database',
        'session.encrypt' => true,
        'session.secure' => true,
        'session.same_site' => 'lax',
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 360,
        'queue.failed.driver' => 'database-uuids',
        'cache.default' => 'database',
        'receiving.queue.workload_timeout_seconds' => 300,
        'receiving.disk' => 'r2',
        'filesystems.disks.r2.key' => 'r2-key',
        'filesystems.disks.r2.secret' => 'r2-secret',
        'filesystems.disks.r2.endpoint' => 'https://example.r2.cloudflarestorage.com',
        'mail.default' => 'smtp',
        'services.gemini.key' => 'gemini-key',
        'services.gemini.model' => 'gemini-model',
        'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'receiving.scanner.driver' => 'cloudmersive',
        'services.cloudmersive.key' => 'cloudmersive-key',
        'services.cloudmersive.base_url' => 'https://api.cloudmersive.com',
        'receiving.scanner.cloudmersive.monthly_call_limit' => 800,
        'receiving.scanner.cloudmersive.minimum_interval_milliseconds' => 1100,
        'receiving.scanner.cloudmersive.max_file_kilobytes' => 3584,
    ]);
});

it('passes the deterministic production configuration gate', function (): void {
    $this->artisan('receiving:check-production')
        ->expectsOutputToContain('Production configuration checks passed')
        ->assertSuccessful();
});

it('blocks a queue lease that can expire while a worker is still running', function (): void {
    config()->set('queue.connections.database.retry_after', 90);

    $results = collect(app(ProductionReadinessCheck::class)->evaluate());
    $lease = $results->firstWhere('name', 'Queue retry lease');

    expect($lease)->not->toBeNull()
        ->and($lease['passed'])->toBeFalse()
        ->and($lease['message'])->toContain('must exceed');

    $this->artisan('receiving:check-production')->assertFailed();
});

it('blocks local storage and non production transports', function (): void {
    config()->set([
        'receiving.disk' => 'local',
        'mail.default' => 'log',
        'receiving.scanner.driver' => 'testing',
    ]);

    $failures = collect(app(ProductionReadinessCheck::class)->evaluate())
        ->reject(fn (array $result): bool => $result['passed'])
        ->pluck('name');

    expect($failures)->toContain(
        'Private object storage',
        'Production mail transport',
        'Fail-closed malware scanner',
    );
});

it('blocks process-local unique-job locks and an unsafe failover queue', function (): void {
    config()->set([
        'cache.default' => 'array',
        'queue.default' => 'failover',
        'queue.connections.failover.driver' => 'failover',
        'queue.connections.failover.connections' => ['database', 'deferred'],
    ]);

    $failures = collect(app(ProductionReadinessCheck::class)->evaluate())
        ->reject(fn (array $result): bool => $result['passed'])
        ->pluck('name');

    expect($failures)->toContain('Asynchronous queue', 'Shared cache locks');
});

it('blocks a disabled malware scanner in production', function (): void {
    config()->set([
        'receiving.scanner.driver' => 'none',
    ]);

    $this->artisan('receiving:check-production')->assertFailed();
});

it('blocks Cloudmersive settings that exceed free tier limits', function (): void {
    config()->set([
        'receiving.scanner.cloudmersive.monthly_call_limit' => 801,
        'receiving.scanner.cloudmersive.minimum_interval_milliseconds' => 999,
        'receiving.scanner.cloudmersive.max_file_kilobytes' => 3585,
    ]);

    $results = collect(app(ProductionReadinessCheck::class)->evaluate());
    $guardrails = $results->firstWhere('name', 'Cloudmersive free-tier guardrails');

    expect($guardrails)->not->toBeNull()
        ->and($guardrails['passed'])->toBeFalse();
});

it('keeps ClamAV available as an explicit rollback driver', function (): void {
    config()->set([
        'receiving.scanner.driver' => 'clamav',
        'receiving.scanner.host' => 'clamav.internal',
        'receiving.scanner.port' => 3310,
    ]);

    $this->artisan('receiving:check-production')
        ->expectsOutputToContain('Production configuration checks passed')
        ->assertSuccessful();
});
