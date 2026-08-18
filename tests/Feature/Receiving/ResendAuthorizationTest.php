<?php

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Features\Receiving\Jobs\StartAiExtraction;
use App\Models\ActivityLog;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('allows resend only for a failed owned upload while access remains active', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $grant = $owner->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = receivingUploadFor($owner, $type, EmailStatus::Failed);

    expect($owner->can('resendNotification', $upload))->toBeTrue()
        ->and($other->can('resendNotification', $upload))->toBeFalse();

    $grant->update(['is_active' => false]);
    expect($owner->can('resendNotification', $upload))->toBeFalse();

    $grant->update(['is_active' => true]);
    $upload->forceFill(['email_status' => EmailStatus::Sent])->save();
    expect($owner->can('resendNotification', $upload))->toBeFalse();
});

it('allows a retry operator to resend a failed transaction but not a sent one', function (): void {
    $admin = User::factory()->create();
    $admin->givePermissionTo(Permission::RetryOperations->value);
    $type = UploadType::query()->firstOrFail();
    $upload = receivingUploadFor(User::factory()->create(), $type, EmailStatus::Failed);

    expect($admin->can('resendNotification', $upload))->toBeTrue();
    $upload->forceFill(['email_status' => EmailStatus::Sent])->save();
    expect($admin->can('resendNotification', $upload))->toBeFalse();
});

it('lets the owning uploader retry failed ai processing and records who requested it', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $type = UploadType::query()->where('slug', 'pingcon')->firstOrFail();
    $owner->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = receivingUploadFor($owner, $type, EmailStatus::Sent);
    $upload->forceFill(['ai_status' => AiStatus::Failed])->save();

    $this->actingAs($other)
        ->post(route('receiving.uploads.retry-ai', $upload))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('receiving.uploads.retry-ai', $upload))
        ->assertSessionHas('status');

    Queue::assertPushed(
        StartAiExtraction::class,
        fn (StartAiExtraction $job): bool => $job->uploadId === $upload->getKey() && $job->retryFailed,
    );
    expect(ActivityLog::query()->where('action', 'user_ai_retry_requested')->value('message'))
        ->toContain('PINGCON upload')
        ->toContain($owner->email);
});

function receivingUploadFor(User $user, UploadType $type, EmailStatus $emailStatus): ReceivingUpload
{
    return ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(), 'uploader_user_id' => $user->getKey(), 'uploader_email' => $user->email,
        'r2_bucket' => 'test', 'r2_prefix' => 'receiving/test', 'file_count' => 1, 'email_status' => $emailStatus,
    ]);
}
