<?php

use App\Enums\Permission;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->group(function () {
    Route::redirect('settings', '/settings/profile')->middleware('deny.uploader.settings');

    Route::get('settings/profile', [ProfileController::class, 'edit'])
        ->middleware('deny.uploader.settings')
        ->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware(RequirePassword::class)
        ->name('profile.update');
});

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware([RequirePassword::class, 'deny.uploader.settings'])
        ->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware([RequirePassword::class, 'deny.uploader.settings'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')
        ->middleware('deny.uploader.settings')
        ->name('appearance.edit');
});

Route::middleware([
    'auth',
    'verified',
    'active',
    'deny.uploader.settings',
    'starter.permission:'.Permission::ViewUploads->value,
    RequirePassword::class,
])->group(function (): void {
    Route::get('settings/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('settings/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
});
