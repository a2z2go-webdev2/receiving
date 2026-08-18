<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]);
});

it('allows an email otp verified admin to open every admin module', function (string $routeName): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route($routeName))
        ->assertOk();
})->with([
    'admin.dashboard',
    'admin.users.index',
    'admin.access.index',
    'admin.recipients.index',
    'admin.uploads.index',
    'admin.purchase-orders.index',
    'admin.activity.index',
    'admin.receiving-settings.edit',
]);

it('denies an uploader from every admin module even when directly requested', function (): void {
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    foreach (['admin.dashboard', 'admin.users.index', 'admin.uploads.index', 'admin.receiving-settings.edit'] as $routeName) {
        $this->actingAs($uploader)->get(route($routeName))->assertForbidden();
    }
});

it('does not register the standalone uploaded files page', function (): void {
    $this->get('/admin/files')->assertNotFound();
});

it('provides a dedicated admin entry that preserves the admin otp gate', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $this->get(route('admin.entry'))->assertRedirect(route('login'));

    $this->actingAs($uploader)->get(route('admin.entry'))->assertForbidden();

    $this->actingAs($admin)
        ->get(route('admin.entry'))
        ->assertRedirect(route('admin.dashboard'));
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.otp.show'));

    $this->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.dashboard'))
        ->assertOk();
});

it('returns an admin qr visitor to the admin otp flow after login', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->get(route('admin.entry'))
        ->assertRedirect(route('login'));

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect(route('admin.entry', absolute: false));

    $this->get(route('admin.entry'))
        ->assertRedirect(route('admin.dashboard'));
    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.otp.show'));
});

it('shows only upload lanes in receiving settings and never exposes environment secrets', function (): void {
    config()->set('services.gemini.key', 'super-secret-gemini-value');
    config()->set('filesystems.disks.r2.secret', 'super-secret-r2-value');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.receiving-settings.edit'))
        ->assertOk()
        ->assertDontSee('super-secret-gemini-value')
        ->assertDontSee('super-secret-r2-value')
        ->assertInertia(fn ($page) => $page
            ->component('admin/settings/index')
            ->has('uploadTypes', 5)
            ->missing('settings')
            ->missing('secretReadiness'));
});
