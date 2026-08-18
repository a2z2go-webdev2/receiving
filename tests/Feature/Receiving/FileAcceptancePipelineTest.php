<?php

use App\Enums\CompressionStatus;
use App\Enums\ValidationStatus;
use App\Enums\VirusScanStatus;
use App\Features\Receiving\Actions\InitiateReceivingUpload;
use App\Features\Receiving\Contracts\FileScanner;
use App\Features\Receiving\Data\FileScanResult;
use App\Features\Receiving\Exceptions\MalwareScanDeferred;
use App\Features\Receiving\Jobs\ProcessUploadedFile;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\FileAcceptancePipeline;
use App\Models\ActivityLog;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(UploadTypeSeeder::class);
    Storage::fake('r2');
    config()->set('receiving.disk', 'r2');
});

it('promotes only a valid clean safely re-encoded image and removes staging', function (): void {
    $image = HttpUploadedFile::fake()->image('invoice.jpg', 100, 100);
    $bytes = file_get_contents($image->getPathname());
    expect($bytes)->toBeString();
    $file = stagedFile('invoice.jpg', 'image/jpeg', $bytes);

    app(FileAcceptancePipeline::class)->process($file);
    $file->refresh();

    expect($file->validation_status)->toBe(ValidationStatus::Valid)
        ->and($file->virus_scan_status)->toBe(VirusScanStatus::Clean)
        ->and($file->compression_status)->toBe(CompressionStatus::Compressed)
        ->and($file->r2_object_key)->not->toBeNull()
        ->and(Storage::disk('r2')->exists((string) $file->r2_object_key))->toBeTrue()
        ->and(Storage::disk('r2')->exists($file->r2_staging_object_key))->toBeFalse();

    expect(ActivityLog::query()->where('receiving_upload_id', $file->receiving_upload_id)->pluck('action'))
        ->toContain('virus_scan_clean', 'file_compression_started', 'file_stored');
});

it('rejects extension and magic-byte spoofing before scanning or promotion', function (): void {
    $file = stagedFile('invoice.jpg', 'image/jpeg', "%PDF-1.7\nspoofed");

    expect(fn () => app(FileAcceptancePipeline::class)->process($file))->toThrow(RuntimeException::class);
    $file->refresh();

    expect($file->validation_status)->toBe(ValidationStatus::Invalid)
        ->and($file->r2_object_key)->toBeNull()
        ->and(Storage::disk('r2')->exists($file->r2_staging_object_key))->toBeFalse();
});

it('quarantines a malware-positive file and never creates a final object', function (): void {
    app()->bind(FileScanner::class, fn () => new class implements FileScanner
    {
        public function scan(string $absolutePath): FileScanResult
        {
            return new FileScanResult(VirusScanStatus::Infected, 'EICAR-Test-Signature FOUND');
        }
    });
    $image = HttpUploadedFile::fake()->image('receipt.png', 20, 20);
    $bytes = file_get_contents($image->getPathname());
    expect($bytes)->toBeString();
    $file = stagedFile('receipt.png', 'image/png', $bytes);

    expect(fn () => app(FileAcceptancePipeline::class)->process($file))->toThrow(RuntimeException::class);
    $file->refresh();

    expect($file->virus_scan_status)->toBe(VirusScanStatus::Infected)
        ->and($file->r2_object_key)->toBeNull();

    expect(ActivityLog::query()->where('receiving_upload_id', $file->receiving_upload_id)->pluck('action'))
        ->toContain('virus_scan_failed')
        ->not->toContain('file_stored');
});

it('retains staging and releases a file job when scanner capacity is temporarily unavailable', function (): void {
    app()->bind(FileScanner::class, fn () => new class implements FileScanner
    {
        public function scan(string $absolutePath): FileScanResult
        {
            throw new MalwareScanDeferred(
                'Malware scanning was rate limited and will retry automatically.',
                7,
            );
        }
    });
    $image = HttpUploadedFile::fake()->image('deferred.png', 20, 20);
    $bytes = file_get_contents($image->getPathname());
    expect($bytes)->toBeString();
    $file = stagedFile('deferred.png', 'image/png', $bytes);
    $job = (new ProcessUploadedFile($file->getKey()))->withFakeQueueInteractions();

    $job->handle(app(FileAcceptancePipeline::class), app(ActivityLogger::class));
    $job->assertReleased(7);
    $file->refresh();

    expect($file->virus_scan_status)->toBe(VirusScanStatus::Failed)
        ->and($file->r2_object_key)->toBeNull()
        ->and(Storage::disk('r2')->exists($file->r2_staging_object_key))->toBeTrue();

    expect(ActivityLog::query()->where('receiving_upload_id', $file->receiving_upload_id)->pluck('action'))
        ->toContain('virus_scan_failed', 'file_processing_deferred')
        ->not->toContain('file_stored');
});

it('records a distinct storage failure when accepted bytes cannot be persisted', function (): void {
    $image = HttpUploadedFile::fake()->image('storage-test.jpg', 20, 20);
    $bytes = file_get_contents($image->getPathname());
    expect($bytes)->toBeString();
    $file = stagedFile('storage-test.jpg', 'image/jpeg', $bytes);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('readStream')->once()->andReturnUsing(function () use ($bytes) {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    });
    $disk->shouldReceive('writeStream')->once()->andThrow(new RuntimeException('Object storage unavailable.'));
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

    expect(fn () => app(FileAcceptancePipeline::class)->process($file))->toThrow(RuntimeException::class);

    expect(ActivityLog::query()->where('receiving_upload_id', $file->receiving_upload_id)->pluck('action'))
        ->toContain('file_storage_failed')
        ->not->toContain('file_stored');
});

function stagedFile(string $name, string $mime, string $bytes): UploadedFile
{
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => $name,
        'size' => strlen($bytes),
        'content_type' => $mime,
        'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
    ]]);
    $file = $upload->files->firstOrFail();
    Storage::disk('r2')->put($file->r2_staging_object_key, $bytes);

    return $file;
}
