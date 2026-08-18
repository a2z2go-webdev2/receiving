<?php

use App\Models\EmailRecipient;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('paginates recipient table data within the selected upload lane', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

    foreach (range(1, 21) as $index) {
        EmailRecipient::query()->create([
            'upload_type_id' => $type->getKey(),
            'email' => "recipient{$index}@example.com",
            'type' => 'to',
            'is_active' => true,
        ]);
    }

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.recipients.index', ['upload_type_id' => $type->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/recipients/index')
            ->where('activeUploadTypeId', $type->getKey())
            ->has('recipients.data', 20)
            ->where('recipients.current_page', 1)
            ->where('recipients.last_page', 2)
            ->where('uploadTypes.0.recipient_count', 21));
});
