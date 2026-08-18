<?php

namespace Tests\Feature\Authorization;

use App\Enums\Permission as PermissionEnum;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_redirects_users_to_their_permission_dashboard(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_uploader_dashboard_redirects_to_an_assigned_upload_page(): void
    {
        $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]);

        $uploader = User::factory()->create();
        $uploader->assignRole('uploader');
        $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
        $uploader->uploadAccesses()->create([
            'upload_type_id' => $type->getKey(),
            'is_active' => true,
        ]);

        $this->actingAs($uploader)
            ->get(route('uploader.dashboard'))
            ->assertRedirect(route('receiving.upload.show', $type));
    }

    public function test_direct_admin_permission_can_visit_admin_dashboard_without_admin_role(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionEnum::AccessAdmin->value);

        $this->actingAs($user)
            ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_admin_permission_requires_email_otp_to_visit_admin_dashboard(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.otp.show'));
    }

    public function test_admin_role_without_admin_access_permission_cannot_visit_admin_dashboard(): void
    {
        $this->seed(RolePermissionSeeder::class);

        Role::findByName('admin', 'web')
            ->revokePermissionTo(PermissionEnum::AccessAdmin->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
