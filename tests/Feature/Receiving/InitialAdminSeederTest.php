<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds the configured verified administrator without resetting an existing password', function (): void {
    $this->seed(RolePermissionSeeder::class);
    config()->set('receiving.initial_admin', [
        'name' => 'Configured Administrator',
        'email' => 'configured-admin@example.com',
        'password' => 'first-secure-password',
    ]);

    $this->seed(UserSeeder::class);

    $admin = User::query()->where('email', 'configured-admin@example.com')->sole();
    expect($admin->name)->toBe('Configured Administrator')
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and(Hash::check('first-secure-password', $admin->password))->toBeTrue();

    config()->set('receiving.initial_admin.password', 'different-secure-password');
    $this->seed(UserSeeder::class);

    expect(Hash::check('first-secure-password', $admin->refresh()->password))->toBeTrue()
        ->and(Hash::check('different-secure-password', $admin->password))->toBeFalse();
});
