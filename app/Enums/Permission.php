<?php

namespace App\Enums;

enum Permission: string
{
    case AccessAdmin = 'admin.access';
    case AccessUploader = 'uploader.access';
    case AccessWarehouse = 'warehouse.access';
    case ManageWarehouseOperations = 'warehouse.operations.manage';
    case AccessDriver = 'driver.access';
    case ManageDriverOperations = 'driver.operations.manage';
    case ViewWarehouseReports = 'warehouse.reports.view';
    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';
    case ManageUploadAccess = 'upload-access.manage';
    case ManageRecipients = 'recipients.manage';
    case ViewUploads = 'uploads.view';
    case RetryOperations = 'operations.retry';
    case ViewActivityLogs = 'activity-logs.view';
    case ManageSettings = 'settings.manage';

    public function label(): string
    {
        return match ($this) {
            self::AccessAdmin => 'Access admin area',
            self::AccessUploader => 'Access uploader area',
            self::AccessWarehouse => 'Access warehouse area',
            self::ManageWarehouseOperations => 'Manage warehouse operations',
            self::AccessDriver => 'Access driver area',
            self::ManageDriverOperations => 'Manage driver operations',
            self::ViewWarehouseReports => 'View warehouse reports',
            self::ViewUsers => 'View users',
            self::ManageUsers => 'Manage users',
            self::ManageUploadAccess => 'Manage upload access',
            self::ManageRecipients => 'Manage email recipients',
            self::ViewUploads => 'View receiving uploads',
            self::RetryOperations => 'Retry failed operations',
            self::ViewActivityLogs => 'View activity logs',
            self::ManageSettings => 'Manage settings',
        };
    }
}
