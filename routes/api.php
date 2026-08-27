<?php

use App\Http\Controllers\Api\GoogleSheetWebhookController;
use App\Http\Controllers\Api\V1\CorrectedDataController;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', fn (Request $request): array => [
        'status' => 'ok',
        'app' => config('app.name'),
        'environment' => app()->environment(),
    ])->name('health');
    Route::prefix('corrected-data')->name('corrected-data.')->group(function (): void {
        Route::get('stream/{file}', [CorrectedDataController::class, 'stream'])
            ->name('stream')
            ->middleware('signed');

        Route::middleware([
            'throttle:api-auth',
            'api.key:'.ApiKey::ABILITY_READ_CORRECTED_DATA,
            'throttle:api-keys',
        ])->group(function (): void {
            Route::get('by-serial-number', [CorrectedDataController::class, 'bySerial'])->name('by-serial');
            Route::get('by-po-number', [CorrectedDataController::class, 'byPoNumber'])->name('by-po-number');
        });
    });
});

Route::post('webhooks/sheets/{slug}', [GoogleSheetWebhookController::class, 'handle'])
    ->name('api.webhooks.sheets');
