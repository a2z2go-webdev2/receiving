<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(PermissionEnum::cases())
            ->mapWithKeys(fn (PermissionEnum $permission): array => [
                $permission->value => Permission::findOrCreate($permission->value, 'web'),
            ]);

        $admin = Role::findOrCreate('admin', 'web');
        $uploader = Role::findOrCreate('uploader', 'web');
        $warehouseOperator = Role::findOrCreate('warehouse_operator', 'web');
        $driver = Role::findOrCreate('driver', 'web');

        $admin->syncPermissions($permissions
            ->except([
                PermissionEnum::AccessWarehouse->value,
                PermissionEnum::ManageWarehouseOperations->value,
                PermissionEnum::AccessDriver->value,
                PermissionEnum::ManageDriverOperations->value,
            ])
            ->values()
            ->all());

        $uploader->syncPermissions([
            PermissionEnum::AccessUploader->value,
        ]);

        $warehouseOperator->syncPermissions([
            PermissionEnum::AccessWarehouse->value,
            PermissionEnum::ManageWarehouseOperations->value,
        ]);

        $driver->syncPermissions([
            PermissionEnum::AccessDriver->value,
            PermissionEnum::ManageDriverOperations->value,
        ]);
    }
}
