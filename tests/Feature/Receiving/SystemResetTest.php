<?php

use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Warehouse\Services\WarehouseOperations;
use App\Models\ActivityLog;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\ReceivingUpload;
use App\Models\SystemSetting;
use App\Models\UploadType;
use App\Models\User;
use App\Models\WarehouseItem;
use App\Models\WarehouseStockLot;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('preserves item records and the initiating admin while clearing operational data and stored files', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $otherUser = User::factory()->create();
    $normalizer = app(PurchaseOrderDataNormalizer::class);
    $schedule = PurchaseOrderItemSchedule::query()->create([
        'sku_number' => 'KEEP-1',
        'sku_number_normalized' => $normalizer->normalizeIdentifier('KEEP-1'),
        'description' => 'Preserved item record',
        'description_normalized' => $normalizer->normalizeDescription('Preserved item record'),
        'target_quantity' => '25.000',
        'unit' => 'pc',
        'expected_week' => null,
        'is_special_order' => false,
        'is_active' => true,
    ]);
    ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => UploadType::query()->firstOrFail()->getKey(),
        'uploader_user_id' => $otherUser->getKey(),
        'uploader_email' => $otherUser->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/reset',
        'file_count' => 0,
    ]);
    SystemSetting::query()->create([
        'key' => 'reset-me',
        'value' => ['value' => true],
        'updated_by' => $admin->getKey(),
    ]);
    $warehouseLot = app(WarehouseOperations::class)->addOpeningStock([
        'sku_number' => 'KEEP-WH-1',
        'description' => 'Preserved warehouse item identity',
        'unit' => 'pc',
        'quantity_received' => 10,
        'received_at' => '2026-07-01',
        'received_date_quality' => 'confirmed',
    ], $otherUser);

    $diskName = (string) config('receiving.disk');
    Storage::fake($diskName);
    Storage::disk($diskName)->put('receiving/reset/file.pdf', 'test');

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.system-reset'), [
            'confirmation' => 'RESET SYSTEM',
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors('password');

    expect(ReceivingUpload::query()->count())->toBe(1)
        ->and(PurchaseOrderItemSchedule::query()->count())->toBe(1)
        ->and(WarehouseStockLot::query()->find($warehouseLot->getKey()))->not->toBeNull()
        ->and(Storage::disk($diskName)->exists('receiving/reset/file.pdf'))->toBeTrue();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.system-reset'), [
            'confirmation' => 'RESET SYSTEM',
            'password' => 'password',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'System factory reset successfully.');

    expect(PurchaseOrderItemSchedule::query()->pluck('id')->all())->toBe([$schedule->getKey()])
        ->and(User::query()->count())->toBe(2)
        ->and(ReceivingUpload::query()->count())->toBe(0)
        ->and(WarehouseStockLot::query()->count())->toBe(0)
        ->and(WarehouseItem::query()->count())->toBe(1)
        ->and(SystemSetting::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'factory_reset')->count())->toBe(1)
        ->and(Storage::disk($diskName)->allFiles())->toBe([]);
});
