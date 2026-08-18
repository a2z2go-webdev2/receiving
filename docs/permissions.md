# Permissions

Roles and permissions are managed by Spatie Laravel Permission.

This starter follows a permission-first rule:

- check permissions for application access and business actions
- use roles only to group permissions or seed default access
- use policies when authorization depends on a specific record
- deny by default when the required permission or policy rule is unclear
- keep frontend visibility aligned with backend permissions, but never rely on frontend checks as security

Seeded roles:

- `admin`
- `uploader`
- `warehouse_operator`

Default access matrix:

| Capability | Admin | Uploader | Warehouse operator |
|---|---:|---:|---:|
| Admin area | Yes | No | No |
| Assigned upload lanes | No | Yes | No |
| Warehouse operations | No | No | Yes |
| Warehouse dwell report | Yes, read only | No | No |

The separation between administrator and warehouse operator is deliberate. Administrators can inspect the dwell report, create users, and assign the `warehouse_operator` role, but they cannot confirm stock, dispatch it, or mark a customer delivery complete. This keeps physical inventory progress tied to the operational user who performed it.

Permissions live in `App\Enums\Permission` and are seeded by `Database\Seeders\RolePermissionSeeder`.

There is no duplicate `users.role` column. Use roles and permissions from Spatie's tables. Assign roles for grouping:

```php
$user->assignRole('warehouse_operator');
```

Check permissions for access:

```php
if ($user->can(App\Enums\Permission::ViewWarehouseReports->value)) {
    // ...
}
```

Protect routes with permission middleware:

```php
Route::get('/admin/purchase-orders/reports/warehouse-dwell', ReportController::class)
    ->middleware([
        'starter.permission:'.App\Enums\Permission::AccessAdmin->value,
        'admin.otp',
        'starter.permission:'.App\Enums\Permission::ViewWarehouseReports->value,
    ]);
```

For record-specific actions, put the context rule in a policy:

```php
public function update(User $user, Report $report): bool
{
    return $user->can(App\Enums\Permission::ViewWarehouseReports->value)
        && $report->account_id === $user->account_id;
}
```

When adding a protected feature:

1. Add or reuse a permission in `App\Enums\Permission`.
2. Seed it through `RolePermissionSeeder`.
3. Protect the backend route, controller, form request, job, export, or download.
4. Add or update a policy when the action touches a specific record.
5. Hide frontend controls with `auth.user.permissions`, matching the backend permission.
6. Add authorization tests for allowed, forbidden, and unauthenticated behavior.
7. Add audit logging for sensitive, destructive, financial, export, role, permission, or security-setting changes.

Avoid role checks for business authorization:

```php
// Avoid this for application access rules.
$user->hasRole('admin');

// Prefer this.
$user->can(App\Enums\Permission::AccessAdmin->value);
```
