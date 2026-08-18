<?php

use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Actions\InitiateReceivingUpload;
use App\Features\Receiving\Jobs\ProcessReceivingUpload;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => $this->seed(UploadTypeSeeder::class));

it('creates collision-free staging keys for every file in one transaction', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'pingcon')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [
        ['name' => '../../invoice one.pdf', 'size' => 100, 'content_type' => 'application/pdf', 'extension' => 'pdf'],
        ['name' => 'invoice two.pdf', 'size' => 200, 'content_type' => 'application/pdf', 'extension' => 'pdf'],
    ]);

    expect($upload->files)->toHaveCount(2)
        ->and($upload->files->pluck('r2_staging_object_key')->unique())->toHaveCount(2)
        ->and($upload->files->first()->sanitized_file_name)->not->toContain('..')
        ->and($upload->r2_prefix)->toContain("SN-{$upload->getKey()}");
});

it('derives the security extension from the original filename instead of client metadata', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'payload.exe', 'size' => 10, 'content_type' => 'image/jpeg', 'extension' => 'jpg',
    ]]);

    expect($upload->files->first()->file_extension)->toBe('exe');
});

it('replays the same submission id without creating a duplicate transaction', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $metadata = [['name' => 'invoice.pdf', 'size' => 10, 'content_type' => 'application/pdf', 'extension' => 'pdf']];
    $submissionId = fake()->uuid();
    $action = app(InitiateReceivingUpload::class);

    $first = $action->handle($user, $type, $metadata, submissionId: $submissionId);
    $second = $action->handle($user, $type, $metadata, submissionId: $submissionId);

    expect($second->getKey())->toBe($first->getKey())
        ->and(ReceivingUpload::query()->count())->toBe(1)
        ->and($first->files()->count())->toBe(1);
});

it('rejects empty and over-limit metadata at the request boundary', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $session = ["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()];

    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.store', $type), ['submission_id' => fake()->uuid(), 'files' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files');

    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.store', $type), ['submission_id' => fake()->uuid(), 'files' => [[
            'name' => 'archive.zip', 'size' => 100, 'content_type' => 'application/zip', 'extension' => 'zip',
        ]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files.0.content_type', 'files.0.extension']);
});

it('accepts only PDF files on the purchase order lane', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $session = ["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()];
    $location = [
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'accuracy' => 15,
        'captured_at' => now()->toISOString(),
    ];

    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.store', $type), [
            'submission_id' => fake()->uuid(),
            'location' => $location,
            'files' => [[
                'name' => 'purchase-order.jpg',
                'size' => 100,
                'content_type' => 'image/jpeg',
                'extension' => 'jpg',
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files.0.content_type', 'files.0.extension']);

    $this->withSession($session)
        ->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('uploadType.workflow', 'purchase_order')
            ->where('constraints.allowedExtensions', ['pdf']));
});

it('accepts uploads without a location reading', function (): void {
    config()->set('receiving.proxy_uploads', true);
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $session = ["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()];

    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.store', $type), [
            'submission_id' => fake()->uuid(),
            'files' => [[
                'name' => 'invoice.pdf', 'size' => 100, 'content_type' => 'application/pdf', 'extension' => 'pdf',
            ]],
        ])
        ->assertCreated();

    $upload = ReceivingUpload::query()->sole();
    expect($upload->latitude)->toBeNull()
        ->and($upload->longitude)->toBeNull()
        ->and($upload->location_accuracy_meters)->toBeNull()
        ->and($upload->location_captured_at)->toBeNull();
});

it('still validates the location reading when one is supplied', function (): void {
    config()->set('receiving.proxy_uploads', true);
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $session = ["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()];
    $payload = [
        'submission_id' => fake()->uuid(),
        'files' => [[
            'name' => 'invoice.pdf', 'size' => 100, 'content_type' => 'application/pdf', 'extension' => 'pdf',
        ]],
    ];

    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.store', $type), [...$payload, 'location' => [
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy' => 1500,
            'captured_at' => now()->toISOString(),
        ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location.accuracy');

    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.store', $type), [...$payload, 'location' => [
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy' => 15,
            'captured_at' => now()->subMinutes(5)->toISOString(),
        ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location.captured_at');
});

it('accepts a practical browser location reading and preserves its reported accuracy', function (): void {
    config()->set('receiving.proxy_uploads', true);
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.store', $type), [
            'submission_id' => fake()->uuid(),
            'location' => [
                'latitude' => 14.5995123,
                'longitude' => 120.9842234,
                'accuracy' => 149,
                'captured_at' => now()->toISOString(),
            ],
            'files' => [[
                'name' => 'invoice.pdf', 'size' => 100, 'content_type' => 'application/pdf', 'extension' => 'pdf',
            ]],
        ])
        ->assertCreated();

    expect(ReceivingUpload::query()->sole()->location_accuracy_meters)->toBe(149.0);
});

it('persists the validated upload location with the transaction', function (): void {
    config()->set('receiving.proxy_uploads', true);
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.store', $type), [
            'submission_id' => fake()->uuid(),
            'location' => [
                'latitude' => 14.5995123,
                'longitude' => 120.9842234,
                'accuracy' => 12.5,
                'captured_at' => now()->toISOString(),
            ],
            'files' => [[
                'name' => 'invoice.pdf', 'size' => 100, 'content_type' => 'application/pdf', 'extension' => 'pdf',
            ]],
        ])
        ->assertCreated();

    $upload = ReceivingUpload::query()->sole();
    expect($upload->latitude)->toBe(14.5995123)
        ->and($upload->longitude)->toBe(120.9842234)
        ->and($upload->location_accuracy_meters)->toBe(12.5)
        ->and($upload->location_captured_at)->not->toBeNull();
});

it('acknowledges durable staging and queues processing without running it in the request', function (): void {
    Queue::fake();
    Storage::fake('r2');
    config()->set('receiving.disk', 'r2');
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'invoice.pdf', 'size' => 8, 'content_type' => 'application/pdf', 'extension' => 'pdf',
    ]]);
    Storage::disk('r2')->put($upload->files->firstOrFail()->r2_staging_object_key, '%PDF-1.7');

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.complete', [$type, $upload]))
        ->assertOk()
        ->assertJsonPath('upload_id', $upload->getKey());

    Queue::assertPushed(ProcessReceivingUpload::class, fn (ProcessReceivingUpload $job): bool => $job->uploadId === $upload->getKey());
});

it('treats repeated completion as a replay without enqueueing processing twice', function (): void {
    Queue::fake();
    Storage::fake('r2');
    config()->set('receiving.disk', 'r2');
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'invoice.pdf', 'size' => 8, 'content_type' => 'application/pdf', 'extension' => 'pdf',
    ]]);
    Storage::disk('r2')->put($upload->files->firstOrFail()->r2_staging_object_key, '%PDF-1.7');
    $session = ["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()];

    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.complete', [$type, $upload]))
        ->assertOk();
    $this->actingAs($user)->withSession($session)
        ->postJson(route('receiving.upload.transactions.complete', [$type, $upload]))
        ->assertOk()
        ->assertJsonPath('message', 'This upload was already submitted.');

    Queue::assertPushed(ProcessReceivingUpload::class, 1);
    expect($upload->fresh()->processing_status)->toBe(UploadProcessingStatus::Queued)
        ->and($upload->files()->firstOrFail()->uploaded_at)->not->toBeNull();
});

it('uses one metadata request to confirm each staged object', function (): void {
    Queue::fake();
    config()->set('receiving.disk', 'r2');
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'invoice.pdf', 'size' => 8, 'content_type' => 'application/pdf', 'extension' => 'pdf',
    ]]);
    $file = $upload->files->firstOrFail();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('size')->once()->with($file->r2_staging_object_key)->andReturn(8);
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.complete', [$type, $upload]))
        ->assertOk();

    Queue::assertPushed(ProcessReceivingUpload::class, 1);
});

it('rejects completion when a staged object cannot be confirmed', function (): void {
    Queue::fake();
    Storage::fake('r2');
    config()->set('receiving.disk', 'r2');
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'missing.pdf', 'size' => 8, 'content_type' => 'application/pdf', 'extension' => 'pdf',
    ]]);
    $file = $upload->files->firstOrFail();

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.complete', [$type, $upload]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors("files.{$file->getKey()}");

    Queue::assertNotPushed(ProcessReceivingUpload::class);
    expect($upload->fresh()->processing_status)->toBe(UploadProcessingStatus::Staging);
});

it('rejects completion when staged bytes differ from the declared size', function (): void {
    Queue::fake();
    Storage::fake('r2');
    config()->set('receiving.disk', 'r2');
    $user = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'mismatch.pdf', 'size' => 8, 'content_type' => 'application/pdf', 'extension' => 'pdf',
    ]]);
    $file = $upload->files->firstOrFail();
    Storage::disk('r2')->put($file->r2_staging_object_key, '%PDF-1.70');

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.complete', [$type, $upload]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors("files.{$file->getKey()}");

    Queue::assertNotPushed(ProcessReceivingUpload::class);
    expect($file->fresh()->uploaded_at)->toBeNull();
});

it('does not let another authorized uploader complete someone elses transaction', function (): void {
    Storage::fake('r2');
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    foreach ([$owner, $attacker] as $user) {
        $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    }
    $upload = app(InitiateReceivingUpload::class)->handle($owner, $type, [[
        'name' => 'invoice.pdf', 'size' => 8, 'content_type' => 'application/pdf', 'extension' => 'pdf',
    ]]);

    $this->actingAs($attacker)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.complete', [$type, $upload]))
        ->assertForbidden();
});
