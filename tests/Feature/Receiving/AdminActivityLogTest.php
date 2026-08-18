<?php

use App\Models\ActivityLog;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('searches and filters the activity timeline while preserving paginator controls', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    ActivityLog::query()->create([
        'user_email' => 'worker@example.com',
        'role' => 'system',
        'module' => 'ai',
        'action' => 'ai_processing_completed',
        'status' => 'success',
        'message' => 'AI completed for NEEDLE-1042.',
        'created_at' => now(),
    ]);
    ActivityLog::query()->create([
        'role' => 'system',
        'module' => 'email',
        'action' => 'review_notification_failed',
        'status' => 'error',
        'message' => 'Unrelated event.',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.activity.index', [
            'search' => 'NEEDLE-1042',
            'module' => 'ai',
            'status' => 'success',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/activity/index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'ai_processing_completed')
            ->where('logs.current_page', 1)
            ->where('filters.search', 'NEEDLE-1042')
            ->where('filters.module', 'ai')
            ->where('filters.status', 'success')
            ->has('filterOptions.modules')
            ->has('filterOptions.statuses'));
});

it('treats wildcard characters literally in activity search', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    ActivityLog::query()->create([
        'role' => 'system',
        'module' => 'upload',
        'action' => 'upload_completed',
        'status' => 'success',
        'message' => 'Processed 100% of files.',
        'created_at' => now(),
    ]);
    ActivityLog::query()->create([
        'role' => 'system',
        'module' => 'upload',
        'action' => 'ordinary_event',
        'status' => 'success',
        'message' => 'No percentage marker.',
        'created_at' => now()->subSecond(),
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.activity.index', ['search' => '%']))
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'upload_completed'));
});

it('finds and labels purchase order activity by POSN sequence', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $uploader = User::factory()->create();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $standardType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $purchaseOrder = null;

    foreach ([$purchaseOrderType, $standardType, $purchaseOrderType] as $type) {
        $upload = ReceivingUpload::query()->create([
            'submission_id' => fake()->uuid(),
            'upload_type_id' => $type->getKey(),
            'uploader_user_id' => $uploader->getKey(),
            'uploader_email' => $uploader->email,
            'r2_bucket' => 'test',
            'r2_prefix' => 'receiving/test',
            'file_count' => 1,
        ]);
        if ($type->is($purchaseOrderType)) {
            $purchaseOrder = $upload;
        }
    }

    ActivityLog::query()->create([
        'receiving_upload_id' => $purchaseOrder?->getKey(),
        'role' => 'system',
        'module' => 'upload',
        'action' => 'purchase_order_uploaded',
        'status' => 'success',
        'message' => 'Purchase order uploaded.',
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.activity.index', ['search' => 'POSN-2']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.upload.id', $purchaseOrder?->getKey())
            ->where('logs.data.0.upload.serial_prefix', 'POSN')
            ->where('logs.data.0.upload.serial_number', 2));
});
