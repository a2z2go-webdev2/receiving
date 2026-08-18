<?php

use App\Features\Receiving\Services\UploadOtpService;
use App\Models\ActivityLog;
use App\Models\ReceivingUpload;
use App\Models\UploadOtp;
use App\Models\UploadType;
use App\Models\User;
use App\Notifications\UploadOtpCode;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]);
});

it('shows purchase order as a separate upload access column', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.access.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploadTypes', 5)
            ->where('uploadTypes.4.name', 'Purchase Order')
            ->where('uploadTypes.4.slug', 'purchase-order'));
});

it('denies an uploader that has no active grant before issuing an otp', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

    $this->actingAs($user)->get(route('receiving.upload.show', $type))->assertRedirect(route('dashboard'));
    Notification::assertNothingSent();
});

it('shows an informative unavailable page for a disabled lane without issuing an otp', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $type->forceFill(['is_active' => false])->save();

    $this->actingAs($user)
        ->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('upload/unavailable')
            ->where('uploadType.name', $type->name)
            ->where('uploadType.slug', $type->slug));

    Notification::assertNothingSent();
    expect(UploadOtp::query()->count())->toBe(0);
});

it('issues one scoped hashed otp for an authorized upload page', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'pingcon')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->actingAs($user)
        ->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('upload/otp')->where('uploadType.slug', 'pingcon'));

    $otp = UploadOtp::query()->sole();
    expect($otp->otp_hash)->not->toMatch('/^\d{6}$/')
        ->and($otp->upload_type_id)->toBe($type->getKey())
        ->and($otp->expires_at->isFuture())->toBeTrue();
    Notification::assertSentTo(
        $user,
        UploadOtpCode::class,
        fn (UploadOtpCode $notification): bool => $notification->toMail($user)->subject === '[PINGCON] OTP for receiving upload',
    );
    expect(ActivityLog::query()->where('action', 'upload_otp_sent')->value('message'))
        ->toContain('PINGCON upload access');
});

it('dispatches upload otp email on the dedicated priority queue', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'pingcon')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->actingAs($user)
        ->get(route('receiving.upload.show', $type))
        ->assertOk();

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
        return $job->notification instanceof UploadOtpCode
            && $job->queue === 'otp';
    });
});

it('returns an uploader to the requested upload page after login', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->get(route('receiving.upload.show', $type))
        ->assertRedirect(route('login'));

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('receiving.upload.show', $type));

    $this->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('upload/otp')
            ->where('uploadType.slug', 'a2z2go'));
});

it('sends an admin-created uploader with assigned access straight to the lane otp', function (): void {
    Notification::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.users.store'), [
            'name' => 'Assigned Uploader',
            'email' => 'assigned-uploader@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'uploader',
            'status' => 'active',
        ])
        ->assertRedirect();

    $uploader = User::query()->where('email', 'assigned-uploader@example.com')->sole();

    expect($uploader->hasVerifiedEmail())->toBeTrue();

    $this->put(route('admin.access.update', $uploader), [
        'upload_type_ids' => [$type->getKey()],
    ])->assertRedirect();

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->get(route('receiving.upload.show', $type))
        ->assertRedirect(route('login'));

    $this->post(route('login.store'), [
        'email' => $uploader->email,
        'password' => 'password',
    ])->assertRedirect(route('receiving.upload.show', $type));

    $this->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('upload/otp')
            ->where('uploadType.slug', 'a2z2go'));
});

it('keeps upload history scoped to the verified lane and current uploader', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'keysys')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $user->getKey(),
        'uploader_email' => $user->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 2,
        'processing_status' => 'completed',
    ]);

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->get(route('receiving.upload.history', $type))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('upload/history')
            ->where('uploadType.slug', 'keysys')
            ->has('uploads.data', 1)
            ->where('uploads.data.0.serial_prefix', 'SN')
            ->where('uploads.data.0.serial_number', $upload->getKey())
            ->missing('uploads.data.0.processing_status'));
});

it('numbers purchase order upload history in its own POSN sequence', function (): void {
    $user = User::factory()->create();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $standardType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $user->uploadAccesses()->create([
        'upload_type_id' => $purchaseOrderType->getKey(),
        'is_active' => true,
    ]);

    foreach ([$purchaseOrderType, $standardType, $purchaseOrderType] as $index => $type) {
        ReceivingUpload::query()->create([
            'submission_id' => fake()->uuid(),
            'upload_type_id' => $type->getKey(),
            'uploader_user_id' => $user->getKey(),
            'uploader_email' => $user->email,
            'r2_bucket' => 'test',
            'r2_prefix' => 'receiving/test',
            'file_count' => 1,
            'processing_status' => 'completed',
            'created_at' => now()->addSeconds($index),
        ]);
    }

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$purchaseOrderType->getKey()}" => now()->getTimestamp()])
        ->get(route('receiving.upload.history', $purchaseOrderType))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 2)
            ->where('uploads.data.0.serial_prefix', 'POSN')
            ->where('uploads.data.0.serial_number', 2)
            ->where('uploads.data.1.serial_prefix', 'POSN')
            ->where('uploads.data.1.serial_number', 1));
});

it('limits attempts and consumes a correct otp exactly once', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'bonita')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    UploadOtp::query()->create([
        'user_id' => $user->getKey(),
        'upload_type_id' => $type->getKey(),
        'email' => $user->email,
        'otp_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
    ]);
    $service = app(UploadOtpService::class);

    expect($service->verify($user, $type, '000000'))->toBeFalse()
        ->and(UploadOtp::query()->value('attempt_count'))->toBe(1)
        ->and($service->verify($user, $type, '123456'))->toBeTrue()
        ->and($service->verify($user, $type, '123456'))->toBeFalse();
});

it('rejects expired and wrong-upload-type codes', function (): void {
    $user = User::factory()->create();
    $first = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $second = UploadType::query()->where('slug', 'keysys')->firstOrFail();
    foreach ([$first, $second] as $type) {
        $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    }
    UploadOtp::query()->create([
        'user_id' => $user->getKey(), 'upload_type_id' => $first->getKey(), 'email' => $user->email,
        'otp_hash' => Hash::make('123456'), 'expires_at' => now()->subSecond(),
    ]);
    $service = app(UploadOtpService::class);

    expect($service->verify($user, $first, '123456'))->toBeFalse()
        ->and($service->verify($user, $second, '123456'))->toBeFalse();
});

it('revokes effective access immediately even after otp verification', function (): void {
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $grant = $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->actingAs($user)->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()]);
    $grant->update(['is_active' => false]);

    $this->postJson(route('receiving.upload.transactions.store', $type), ['submission_id' => fake()->uuid(), 'files' => [[
        'name' => 'invoice.pdf', 'size' => 100, 'content_type' => 'application/pdf', 'extension' => 'pdf',
    ]]])->assertForbidden();
});

it('records an expired upload session once and requires a new verification code', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'bonita')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $sessionKey = "receiving.otp_grants.{$type->getKey()}";

    $this->actingAs($user)
        ->withSession([$sessionKey => now()->subHours(9)->getTimestamp()])
        ->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertSessionMissing($sessionKey)
        ->assertSessionHas('session_expired');

    expect(ActivityLog::query()->where('action', 'upload_session_expired')->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', 'upload_session_expired')->value('message'))
        ->toContain($user->email)
        ->toContain('BONITA upload session expired');
});

it('returns an explicit session-expired response for upload requests after the otp grant expires', function (): void {
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->subHours(9)->getTimestamp()])
        ->postJson(route('receiving.upload.transactions.store', $type), [])
        ->assertStatus(419)
        ->assertJsonPath('code', 'SESSION_EXPIRED');
});

it('renews an upload otp grant while the uploader remains active', function (): void {
    config()->set('receiving.otp.grant_minutes', 30);
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $sessionKey = "receiving.otp_grants.{$type->getKey()}";
    $this->travelTo(now()->startOfSecond());
    $verifiedAt = now()->subMinutes(29)->getTimestamp();
    $renewedAt = now()->getTimestamp();

    $this->actingAs($user)
        ->withSession([$sessionKey => $verifiedAt])
        ->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertSessionHas($sessionKey, $renewedAt);

    $this->travel(29)->minutes();
    $renewedAt = now()->getTimestamp();

    $this->get(route('receiving.upload.show', $type))
        ->assertOk()
        ->assertSessionHas($sessionKey, $renewedAt);
});

it('does not renew an upload otp grant from automatic live refreshes', function (): void {
    config()->set('receiving.otp.grant_minutes', 30);
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $sessionKey = "receiving.otp_grants.{$type->getKey()}";
    $verifiedAt = now()->subMinutes(29)->getTimestamp();

    $this->actingAs($user)
        ->withHeader('X-Live-Refresh', '1')
        ->withSession([$sessionKey => $verifiedAt])
        ->get(route('receiving.upload.history', $type))
        ->assertOk()
        ->assertSessionHas($sessionKey, $verifiedAt);

    $this->travel(2)->minutes();

    $this->get(route('receiving.upload.history', $type))
        ->assertRedirect(route('receiving.upload.show', $type))
        ->assertSessionMissing($sessionKey);
});

it('records logout from every active upload page grant', function (): void {
    $user = User::factory()->create();
    $user->assignRole('uploader');
    $type = UploadType::query()->where('slug', 'keysys')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);

    $this->actingAs($user)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    expect(ActivityLog::query()->where('action', 'upload_access_logged_out')->value('message'))
        ->toContain($user->email)
        ->toContain('KEYSYS INC. upload page');
});
