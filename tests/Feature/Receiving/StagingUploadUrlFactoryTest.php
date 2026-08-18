<?php

use App\Features\Receiving\Services\StagingUploadUrlFactory;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('creates an object-scoped direct R2 PUT URL with browser-safe headers', function (): void {
    config()->set('receiving.proxy_uploads', false);
    config()->set('receiving.disk', 'r2');
    config()->set('filesystems.disks.r2.key', 'test-access-key');
    config()->set('filesystems.disks.r2.secret', 'test-secret-key');
    config()->set('filesystems.disks.r2.region', 'auto');
    config()->set('filesystems.disks.r2.bucket', 'receiving-documents');
    config()->set('filesystems.disks.r2.endpoint', 'https://example-account.r2.cloudflarestorage.com');
    config()->set('filesystems.disks.r2.use_path_style_endpoint', true);
    Storage::purge('r2');

    $file = new UploadedFile;
    $file->forceFill([
        'id' => 42,
        'r2_staging_object_key' => 'staging/pingcon/SN-7/42-invoice.pdf',
        'declared_content_type' => 'application/pdf',
    ]);

    $target = app(StagingUploadUrlFactory::class)->for($file);
    $url = parse_url($target['url']);

    expect($url)->toBeArray()
        ->and($url['host'] ?? null)->toBe('example-account.r2.cloudflarestorage.com')
        ->and(urldecode($url['path'] ?? ''))->toBe('/receiving-documents/staging/pingcon/SN-7/42-invoice.pdf')
        ->and($url['query'] ?? '')->toContain('X-Amz-Algorithm=AWS4-HMAC-SHA256')
        ->and($target['headers'])->toHaveKey('Content-Type')
        ->and($target['headers']['Content-Type'])->toBe('application/pdf')
        ->and(array_filter($target['headers'], fn (mixed $value): bool => ! is_string($value)))->toBeEmpty()
        ->and($target['fallback'])->toBeArray()
        ->and($target['fallback']['url'])->toStartWith('/receiving/staging/42?')
        ->and($target['fallback']['headers'])->toBe(['Content-Type' => 'application/pdf']);
});

it('keeps the authenticated application proxy as an explicit fallback', function (): void {
    config()->set('receiving.proxy_uploads', true);

    $file = new UploadedFile;
    $file->forceFill([
        'id' => 42,
        'r2_staging_object_key' => 'staging/pingcon/SN-7/42-invoice.pdf',
        'declared_content_type' => 'application/pdf',
    ]);

    $target = app(StagingUploadUrlFactory::class)->for($file);

    expect($target['url'])->toStartWith('/receiving/staging/42?')
        ->and($target['headers'])->toBe(['Content-Type' => 'application/pdf'])
        ->and($target['fallback'])->toBeNull();
});
