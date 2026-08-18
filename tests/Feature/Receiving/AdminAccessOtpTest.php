<?php

use App\Features\Receiving\Services\AdminAccessOtpService;
use App\Models\AdminAccessOtp;
use App\Models\User;
use App\Notifications\AdminAccessOtpCode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolePermissionSeeder::class);
});

it('bounds smtp operations below the web request execution limit', function (): void {
    expect(config('mail.mailers.smtp.timeout'))
        ->toBeFloat()
        ->toBeGreaterThan(0)
        ->toBeLessThan(30);
});

it('issues a hashed email otp before an administrator can enter the admin area', function (): void {
    Notification::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.otp.show'));

    $this->get(route('admin.otp.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/otp'));

    $otp = AdminAccessOtp::query()->sole();
    expect($otp->otp_hash)->not->toMatch('/^\d{6}$/')
        ->and($otp->expires_at->isFuture())->toBeTrue();
    Notification::assertSentTo($admin, AdminAccessOtpCode::class);
});

it('queues admin otp delivery instead of waiting for smtp in the web request', function (): void {
    Queue::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.otp.show'))
        ->assertOk();

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($admin): bool {
        return $job->notification instanceof AdminAccessOtpCode
            && $job->notifiables->contains($admin)
            && $job->queue === 'otp';
    });
});

it('queues resend delivery and returns immediately', function (): void {
    Queue::fake();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->from(route('admin.otp.show'))
        ->post(route('admin.otp.resend'))
        ->assertRedirect(route('admin.otp.show'))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(SendQueuedNotifications::class);
});

it('consumes an admin otp once and grants only the current session', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    AdminAccessOtp::query()->create([
        'user_id' => $admin->getKey(),
        'email' => $admin->email,
        'otp_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($admin)
        ->withSession(['url.intended' => '/admin/uploads'])
        ->post(route('admin.otp.verify'), ['code' => '123456'])
        ->assertRedirect('/admin/uploads')
        ->assertSessionHas('admin.otp_verified_at');

    expect(AdminAccessOtp::query()->sole()->used_at)->not->toBeNull();
    $this->get(route('admin.dashboard'))->assertOk();
    expect(app(AdminAccessOtpService::class)->verify($admin, '123456'))->toBeFalse();
});

it('limits wrong attempts and rejects expired admin codes', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    AdminAccessOtp::query()->create([
        'user_id' => $admin->getKey(),
        'email' => $admin->email,
        'otp_hash' => Hash::make('123456'),
        'expires_at' => now()->subSecond(),
    ]);

    expect(app(AdminAccessOtpService::class)->verify($admin, '123456'))->toBeFalse();

    AdminAccessOtp::query()->update(['expires_at' => now()->addMinutes(5)]);
    expect(app(AdminAccessOtpService::class)->verify($admin, '000000'))->toBeFalse()
        ->and(AdminAccessOtp::query()->value('attempt_count'))->toBe(1);
});

it('denies non admins and json requests without an otp grant', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');
    $this->actingAs($uploader)->get(route('admin.otp.show'))->assertForbidden();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin)->getJson(route('admin.dashboard'))->assertForbidden();
});

it('flashes a clear popup message when an admin otp session expires', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->subHours(9)->getTimestamp()])
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.otp.show'))
        ->assertSessionHas('session_expired');
});

it('renews an admin otp grant while the administrator remains active', function (): void {
    config()->set('receiving.otp.grant_minutes', 30);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->travelTo(now()->startOfSecond());
    $verifiedAt = now()->subMinutes(29)->getTimestamp();
    $renewedAt = now()->getTimestamp();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => $verifiedAt])
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSessionHas('admin.otp_verified_at', $renewedAt);

    $this->travel(29)->minutes();
    $renewedAt = now()->getTimestamp();

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSessionHas('admin.otp_verified_at', $renewedAt);
});

it('does not renew an admin otp grant from automatic live refreshes', function (): void {
    config()->set('receiving.otp.grant_minutes', 30);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $verifiedAt = now()->subMinutes(29)->getTimestamp();

    $this->actingAs($admin)
        ->withHeader('X-Live-Refresh', '1')
        ->withSession(['admin.otp_verified_at' => $verifiedAt])
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSessionHas('admin.otp_verified_at', $verifiedAt);

    $this->travel(2)->minutes();

    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.otp.show'))
        ->assertSessionMissing('admin.otp_verified_at');
});
