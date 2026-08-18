<?php

use App\Enums\AiStatus;
use App\Enums\CompressionStatus;
use App\Enums\ValidationStatus;
use App\Enums\VirusScanStatus;
use App\Mail\ReceivingUploadReceived;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(UploadTypeSeeder::class);
    Storage::fake('r2');
    config()->set('receiving.disk', 'r2');
});

it('attaches image uploads as converted PDF files while keeping PDF uploads untouched', function (): void {
    [$upload, $jpgFile, $pdfFile] = attachmentUpload();

    $attachments = (new ReceivingUploadReceived($upload, 'https://example.test/transaction', attachFiles: true))->attachments();

    expect($attachments)->toHaveCount(2);

    $jpgAttachment = collect($attachments)->first(fn ($attachment): bool => $attachment->as === 'invoice.pdf');
    $pdfAttachment = collect($attachments)->first(fn ($attachment): bool => $attachment->as === 'contract.pdf');

    expect($jpgAttachment)->not->toBeNull()
        ->and($jpgAttachment->mime)->toBe('application/pdf');

    $jpgBytes = $jpgAttachment->attachWith(fn () => null, fn ($data) => $data());
    expect($jpgBytes)->toStartWith('%PDF-')
        ->and($jpgBytes)->toContain('%%EOF');

    expect($pdfAttachment)->not->toBeNull()
        ->and($pdfAttachment->as)->toBe('contract.pdf')
        ->and($pdfAttachment->mime)->toBe('application/pdf');

    $storedPdfBytes = Storage::disk('r2')->get($pdfFile->r2_object_key);
    $pdfBytes = $pdfAttachment->attachWith(fn () => null, fn ($data) => $data());
    expect($pdfBytes)->toBe($storedPdfBytes);
});

it('does not attach files when attachment flag is disabled', function (): void {
    [$upload] = attachmentUpload();

    $attachments = (new ReceivingUploadReceived($upload, 'https://example.test/transaction'))->attachments();

    expect($attachments)->toBe([]);
});

/**
 * @return array{0: ReceivingUpload, 1: UploadedFile, 2: UploadedFile}
 */
function attachmentUpload(): array
{
    $type = UploadType::query()->firstOrFail();
    $user = User::factory()->create();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $user->getKey(),
        'uploader_email' => $user->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 2,
    ]);

    $jpg = HttpUploadedFile::fake()->image('invoice.jpg', 200, 120);
    $jpgKey = 'receiving/test/invoice.jpg';
    Storage::disk('r2')->put($jpgKey, file_get_contents($jpg->getPathname()));

    $jpgFile = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'invoice.jpg',
        'sanitized_file_name' => 'invoice.jpg',
        'stored_file_name' => 'invoice.jpg',
        'file_extension' => 'jpg',
        'r2_bucket' => 'test',
        'r2_object_key' => $jpgKey,
        'r2_staging_object_key' => 'staging/invoice.jpg',
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'image/jpeg',
        'content_type' => 'image/jpeg',
        'validation_status' => ValidationStatus::Valid,
        'virus_scan_status' => VirusScanStatus::Clean,
        'compression_status' => CompressionStatus::Skipped,
        'ai_status' => AiStatus::Pending,
    ]);

    $pdfKey = 'receiving/test/contract.pdf';
    Storage::disk('r2')->put($pdfKey, "%PDF-1.4\n%fake pdf bytes\n%%EOF\n");

    $pdfFile = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'contract.pdf',
        'sanitized_file_name' => 'contract.pdf',
        'stored_file_name' => 'contract.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => $pdfKey,
        'r2_staging_object_key' => 'staging/contract.pdf',
        'original_file_size' => 50,
        'final_file_size' => 50,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'validation_status' => ValidationStatus::Valid,
        'virus_scan_status' => VirusScanStatus::Clean,
        'compression_status' => CompressionStatus::Skipped,
        'ai_status' => AiStatus::Pending,
    ]);

    return [$upload->refresh(), $jpgFile, $pdfFile];
}
