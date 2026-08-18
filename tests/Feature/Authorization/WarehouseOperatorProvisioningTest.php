<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WarehouseOperatorProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_seeded_role_cannot_leave_a_roleless_user_behind(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Role::findByName('warehouse_operator', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)
            ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
            ->post(route('admin.users.store'), [
                'name' => 'Unseeded Warehouse User',
                'email' => 'unseeded-warehouse@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role' => 'warehouse_operator',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'unseeded-warehouse@example.com']);
    }

    public function test_admin_can_create_a_warehouse_operator_with_effective_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
            ->post(route('admin.users.store'), [
                'name' => 'Warehouse User',
                'email' => 'warehouse@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role' => 'warehouse_operator',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $operator = User::query()->where('email', 'warehouse@example.com')->sole();

        $this->assertTrue($operator->hasRole('warehouse_operator'));
        $this->assertTrue($operator->can('warehouse.access'));
        $this->assertSame('warehouse.dashboard', $operator->dashboardRouteName());
    }

    public function test_dashboard_redirect_is_relative_so_local_session_host_is_preserved(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $operator = User::factory()->create();
        $operator->assignRole('warehouse_operator');

        $this->withServerVariables(['HTTP_HOST' => 'localhost:8000'])
            ->actingAs($operator)
            ->get(route('dashboard', absolute: false))
            ->assertHeader('Location', '/warehouse/dashboard');
    }
}
