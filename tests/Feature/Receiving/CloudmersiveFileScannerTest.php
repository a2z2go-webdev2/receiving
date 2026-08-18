<?php

use App\Enums\VirusScanStatus;
use App\Features\Receiving\Exceptions\MalwareScanDeferred;
use App\Features\Receiving\Services\CloudmersiveFileScanner;
use App\Features\Receiving\Services\ReceivingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set([
        'cache.default' => 'array',
        'services.cloudmersive.key' => 'test-cloudmersive-key',
        'services.cloudmersive.base_url' => 'https://api.cloudmersive.com',
        'services.cloudmersive.connect_timeout_seconds' => 1,
        'services.cloudmersive.timeout_seconds' => 5,
        'receiving.scanner.driver' => 'cloudmersive',
        'receiving.scanner.cloudmersive.monthly_call_limit' => 800,
        'receiving.scanner.cloudmersive.minimum_interval_milliseconds' => 1100,
        'receiving.scanner.cloudmersive.max_file_kilobytes' => 3584,
        'receiving.scanner.cloudmersive.lock_wait_seconds' => 1,
        'receiving.scanner.cloudmersive.rate_limit_retry_seconds' => 60,
    ]);
    Cache::flush();
    CarbonImmutable::setTestNow('2026-07-13 12:00:00');
    Sleep::fake(true, true);

    $this->scanPath = tempnam(sys_get_temp_dir(), 'cloudmersive-test-');
    expect($this->scanPath)->toBeString();
    file_put_contents($this->scanPath, "%PDF-1.7\ntest document");
});

afterEach(function (): void {
    if (is_string($this->scanPath) && is_file($this->scanPath)) {
        unlink($this->scanPath);
    }

    Sleep::fake(false);
    CarbonImmutable::setTestNow();
});

it('sends a file with a secret header and accepts only a clean response', function (): void {
    Http::fake([
        'api.cloudmersive.com/*' => Http::response([
            'CleanResult' => true,
            'FoundViruses' => null,
        ]),
    ]);

    $result = app(CloudmersiveFileScanner::class)->scan($this->scanPath);

    expect($result->status)->toBe(VirusScanStatus::Clean)
        ->and(DB::table('cloudmersive_scan_usages')->value('request_count'))->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudmersive.com/virus/scan/file'
        && $request->hasHeader('Apikey', 'test-cloudmersive-key'));
});

it('fails closed when Cloudmersive reports malware', function (): void {
    Http::fake([
        '*' => Http::response([
            'CleanResult' => false,
            'FoundViruses' => [[
                'FileName' => 'receiving-upload.bin',
                'VirusName' => 'EICAR-Test-Signature',
            ]],
        ]),
    ]);

    $result = app(CloudmersiveFileScanner::class)->scan($this->scanPath);

    expect($result->status)->toBe(VirusScanStatus::Infected)
        ->and($result->message)->not->toContain('EICAR-Test-Signature');
});

it('rejects malformed provider responses instead of treating them as clean', function (): void {
    Http::fake(['*' => Http::response(['CleanResult' => 'true'])]);

    expect(fn () => app(CloudmersiveFileScanner::class)->scan($this->scanPath))
        ->toThrow(RuntimeException::class, 'Malware scanner returned an invalid response.');
});

it('defers provider rate limits without exposing the API key', function (): void {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '7'])]);

    try {
        app(CloudmersiveFileScanner::class)->scan($this->scanPath);
        $this->fail('Expected the scan to be deferred.');
    } catch (MalwareScanDeferred $error) {
        expect($error->retryAfterSeconds)->toBe(7)
            ->and($error->getMessage())->not->toContain('test-cloudmersive-key');
    }
});

it('stops before a call beyond the configured monthly allowance and retries after the reset', function (): void {
    config()->set('receiving.scanner.cloudmersive.monthly_call_limit', 1);
    Http::fake(['*' => Http::response(['CleanResult' => true, 'FoundViruses' => []])]);
    $scanner = app(CloudmersiveFileScanner::class);

    $scanner->scan($this->scanPath);

    try {
        $scanner->scan($this->scanPath);
        $this->fail('Expected the monthly scan allowance to stop the request.');
    } catch (MalwareScanDeferred $error) {
        expect($error->retryAfterSeconds)->toBeGreaterThan(60);
    }

    Http::assertSentCount(1);
    expect(DB::table('cloudmersive_scan_usages')->value('request_count'))->toBe(1);
});

it('spaces consecutive file scans beyond the one-call-per-second limit', function (): void {
    Http::fake(['*' => Http::response(['CleanResult' => true, 'FoundViruses' => []])]);
    $scanner = app(CloudmersiveFileScanner::class);

    $scanner->scan($this->scanPath);
    $scanner->scan($this->scanPath);

    Sleep::assertSlept(
        fn ($duration): bool => (int) $duration->totalMicroseconds === 1_100_000,
    );
    Http::assertSentCount(2);
});

it('defers a parallel file while another scan owns the shared lock', function (): void {
    config()->set('receiving.scanner.cloudmersive.lock_wait_seconds', 0);
    Http::fake();
    $lock = Cache::lock('receiving:cloudmersive:scan-lock', 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(CloudmersiveFileScanner::class)->scan($this->scanPath))
            ->toThrow(MalwareScanDeferred::class, 'Malware scanning is busy');
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
    expect(DB::table('cloudmersive_scan_usages')->count())->toBe(0);
});

it('rejects files beyond the configured free-tier size before spending a call', function (): void {
    config()->set('receiving.scanner.cloudmersive.max_file_kilobytes', 1);
    file_put_contents($this->scanPath, str_repeat('x', 1025));
    Http::fake();

    expect(fn () => app(CloudmersiveFileScanner::class)->scan($this->scanPath))
        ->toThrow(RuntimeException::class, 'The file exceeds the configured malware scanner size limit.');

    Http::assertNothingSent();
    expect(DB::table('cloudmersive_scan_usages')->count())->toBe(0);
});

it('caps the upload page file size at the active Cloudmersive plan limit', function (): void {
    config()->set([
        'receiving.uploads.max_file_kilobytes' => 15360,
        'receiving.scanner.cloudmersive.max_file_kilobytes' => 3584,
    ]);

    expect(app(ReceivingSettings::class)->maxFileSizeKilobytes())->toBe(3584);
});
