<?php

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\ReviewStatus;
use App\Models\AiExtraction;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]);
    Storage::fake('receiving');
    config()->set('receiving.disk', 'receiving');
});

function createTestUpload(User $uploader, UploadType $type, array $attributes = []): ReceivingUpload
{
    $upload = ReceivingUpload::query()->create(array_merge([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'ai_status' => AiStatus::Extracted,
        'review_email_status' => EmailStatus::Sent,
        'review_status' => ReviewStatus::Pending,
        'processing_status' => 'completed',
    ], $attributes));

    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'receipt.pdf',
        'sanitized_file_name' => 'receipt.pdf',
        'stored_file_name' => 'receipt.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => "receiving/{$upload->getKey()}/receipt.pdf",
        'r2_staging_object_key' => "staging/{$upload->getKey()}/receipt.pdf",
        'original_file_size' => 1024,
        'final_file_size' => 1024,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);

    Storage::disk('receiving')->put("receiving/{$upload->getKey()}/receipt.pdf", 'dummy content');

    AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Delivery Receipt',
        'raw_extracted_json' => ['document_type' => 'Delivery Receipt'],
        'corrected_json' => null,
        'ai_status' => AiStatus::Extracted,
    ]);

    return $upload;
}

test('guests are redirected to login when visiting my uploads', function (): void {
    $this->get(route('uploader.uploads'))
        ->assertRedirect(route('login'));
});

test('users without uploader access permission receive 403', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('uploader.uploads'))
        ->assertForbidden();
});

test('uploader can view my uploads index page', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $this->actingAs($uploader)
        ->get(route('uploader.uploads'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('uploader/uploads/index')
            ->has('uploads')
            ->has('filters')
            ->has('uploadTypes')
        );
});

test('my uploads only lists the authenticated uploaders own uploads', function (): void {
    $uploaderA = User::factory()->create();
    $uploaderA->assignRole('uploader');

    $uploaderB = User::factory()->create();
    $uploaderB->assignRole('uploader');

    $type = UploadType::query()->firstOrFail();

    $uploadA = createTestUpload($uploaderA, $type);
    $uploadB = createTestUpload($uploaderB, $type);

    $this->actingAs($uploaderA)
        ->get(route('uploader.uploads'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('uploader/uploads/index')
            ->where('uploads.data.0.id', $uploadA->getKey())
            ->has('uploads.data', 1)
        );
});

test('my uploads displays all upload sources user has ever uploaded to even if access is removed or inactive', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $typeInactive = UploadType::query()->firstOrFail();
    $typeInactive->update(['is_active' => false]);

    // Uploader is NOT assigned to $typeInactive, and $typeInactive is inactive
    $upload = createTestUpload($uploader, $typeInactive);

    $this->actingAs($uploader)
        ->get(route('uploader.uploads'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('uploader/uploads/index')
            ->where('uploads.data.0.id', $upload->getKey())
            ->has('uploadTypes', fn (Assert $types) => $types
                ->where('0.id', $typeInactive->getKey())
            )
        );
});

test('uploader can view details of their own upload', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $type = UploadType::query()->firstOrFail();
    $upload = createTestUpload($uploader, $type);

    $this->actingAs($uploader)
        ->get(route('uploader.uploads.show', $upload))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('uploader/uploads/show')
            ->where('upload.id', $upload->getKey())
        );
});

test('uploader cannot view details of another users upload', function (): void {
    $uploaderA = User::factory()->create();
    $uploaderA->assignRole('uploader');

    $uploaderB = User::factory()->create();
    $uploaderB->assignRole('uploader');

    $type = UploadType::query()->firstOrFail();
    $uploadB = createTestUpload($uploaderB, $type);

    $this->actingAs($uploaderA)
        ->get(route('uploader.uploads.show', $uploadB))
        ->assertForbidden();
});

test('uploader can request temporary file url for their own uploaded file', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $type = UploadType::query()->firstOrFail();
    $upload = createTestUpload($uploader, $type);
    $file = $upload->files()->firstOrFail();

    $this->actingAs($uploader)
        ->postJson(route('uploader.files.url', $file))
        ->assertOk()
        ->assertJsonStructure(['url']);
});

test('uploader cannot request file url for another users uploaded file', function (): void {
    $uploaderA = User::factory()->create();
    $uploaderA->assignRole('uploader');

    $uploaderB = User::factory()->create();
    $uploaderB->assignRole('uploader');

    $type = UploadType::query()->firstOrFail();
    $uploadB = createTestUpload($uploaderB, $type);
    $fileB = $uploadB->files()->firstOrFail();

    $this->actingAs($uploaderA)
        ->postJson(route('uploader.files.url', $fileB))
        ->assertForbidden();
});

test('uploader can preview their own uploaded file', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $type = UploadType::query()->firstOrFail();
    $upload = createTestUpload($uploader, $type);
    $file = $upload->files()->firstOrFail();

    $this->actingAs($uploader)
        ->get(route('uploader.files.preview', $file))
        ->assertOk();
});

test('uploader cannot preview another users uploaded file', function (): void {
    $uploaderA = User::factory()->create();
    $uploaderA->assignRole('uploader');

    $uploaderB = User::factory()->create();
    $uploaderB->assignRole('uploader');

    $type = UploadType::query()->firstOrFail();
    $uploadB = createTestUpload($uploaderB, $type);
    $fileB = $uploadB->files()->firstOrFail();

    $this->actingAs($uploaderA)
        ->get(route('uploader.files.preview', $fileB))
        ->assertForbidden();
});

test('my uploads filters by search and upload type', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $types = UploadType::query()->take(2)->get();
    $type1 = $types[0];
    $type2 = $types[1];

    $upload1 = createTestUpload($uploader, $type1, ['serial_number' => 101]);
    $upload2 = createTestUpload($uploader, $type2, ['serial_number' => 202]);

    // Filter by type1
    $this->actingAs($uploader)
        ->get(route('uploader.uploads', ['upload_type_id' => $type1->getKey()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $upload1->getKey())
        );

    // Search by serial number 202
    $this->actingAs($uploader)
        ->get(route('uploader.uploads', ['search' => '202']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $upload2->getKey())
        );
});
