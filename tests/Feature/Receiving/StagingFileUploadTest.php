<?php

use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Actions\InitiateReceivingUpload;
use App\Features\Receiving\Services\StagingUploadUrlFactory;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(UploadTypeSeeder::class);
    Storage::fake('r2');
    config()->set('receiving.disk', 'r2');
    config()->set('receiving.proxy_uploads', true);
});

it('streams an authorized same-origin fallback upload to private staging', function (): void {
    [$owner, $type, $file] = proxyUploadFixture();
    $bytes = '%PDF-1.7';
    $target = app(StagingUploadUrlFactory::class)->for($file);

    $this->actingAs($owner)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->call('PUT', $target['url'], [], [], [], [
            'CONTENT_TYPE' => 'application/pdf',
            'CONTENT_LENGTH' => (string) strlen($bytes),
        ], $bytes)
        ->assertNoContent();

    expect(Storage::disk('r2')->get($file->r2_staging_object_key))->toBe($bytes);
});

it('rejects a signed fallback URL used by another authorized uploader', function (): void {
    [$owner, $type, $file] = proxyUploadFixture();
    $other = User::factory()->create();
    $other->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $target = app(StagingUploadUrlFactory::class)->for($file);

    $this->actingAs($other)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->call('PUT', $target['url'], [], [], [], [
            'CONTENT_TYPE' => 'application/pdf',
            'CONTENT_LENGTH' => '8',
        ], '%PDF-1.7')
        ->assertForbidden();

    Storage::disk('r2')->assertMissing($file->r2_staging_object_key);
});

it('rejects fallback replay after the transaction leaves staging', function (): void {
    [$owner, $type, $file] = proxyUploadFixture();
    $file->upload->forceFill(['processing_status' => UploadProcessingStatus::Queued])->save();
    $target = app(StagingUploadUrlFactory::class)->for($file);

    $this->actingAs($owner)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->call('PUT', $target['url'], [], [], [], [
            'CONTENT_TYPE' => 'application/pdf',
            'CONTENT_LENGTH' => '8',
        ], '%PDF-1.7')
        ->assertConflict();

    Storage::disk('r2')->assertMissing($file->r2_staging_object_key);
});

/** @return array{User, UploadType, UploadedFile} */
function proxyUploadFixture(): array
{
    $owner = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $owner->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = app(InitiateReceivingUpload::class)->handle($owner, $type, [[
        'name' => 'invoice.pdf',
        'size' => 8,
        'content_type' => 'application/pdf',
        'extension' => 'pdf',
    ]]);

    return [$owner, $type, $upload->files->firstOrFail()];
}
