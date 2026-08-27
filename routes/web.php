<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminAccessOtpController;
use App\Http\Controllers\Admin\EmailRecipientController;
use App\Http\Controllers\Admin\GoogleSheetSyncController;
use App\Http\Controllers\Admin\LegacyDataImportController;
use App\Http\Controllers\Admin\PurchaseOrderDocumentLinkController;
use App\Http\Controllers\Admin\PurchaseOrderItemScheduleController;
use App\Http\Controllers\Admin\PurchaseOrderReportController;
use App\Http\Controllers\Admin\ReceivingDashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ReceivingSettingsController;
use App\Http\Controllers\Admin\SystemResetController;
use App\Http\Controllers\Admin\UploadAccessController;
use App\Http\Controllers\Admin\UploadLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Driver\DriverAccessOtpController;
use App\Http\Controllers\Driver\DriverDashboardController;
use App\Http\Controllers\Driver\DriverOperationsController;
use App\Http\Controllers\Receiving\NotificationViewController;
use App\Http\Controllers\Receiving\ReviewController;
use App\Http\Controllers\Receiving\ReviewFileController;
use App\Http\Controllers\Receiving\StagingFileController;
use App\Http\Controllers\Receiving\UploadPageController;
use App\Http\Controllers\Receiving\UploadRecordController;
use App\Http\Controllers\Receiving\UploadTransactionController;
use App\Http\Controllers\Uploader\DashboardController as UploaderDashboardController;
use App\Http\Controllers\Warehouse\WarehouseAccessOtpController;
use App\Http\Controllers\Warehouse\WarehouseArrivalsController;
use App\Http\Controllers\Warehouse\WarehouseDashboardController;
use App\Http\Controllers\Warehouse\WarehouseDeliveriesController;
use App\Http\Controllers\Warehouse\WarehouseDwellReportController;
use App\Http\Controllers\Warehouse\WarehouseInventoryController;
use App\Http\Controllers\Warehouse\WarehouseOperationsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('admin', fn () => redirect()->route('admin.dashboard'))
        ->middleware('starter.permission:'.Permission::AccessAdmin->value)
        ->name('admin.entry');

    Route::get('uploader/dashboard', UploaderDashboardController::class)
        ->middleware('starter.permission:'.Permission::AccessUploader->value)
        ->name('uploader.dashboard');

    Route::get('warehouse', fn () => redirect()->route('warehouse.dashboard'))
        ->middleware('starter.permission:'.Permission::AccessWarehouse->value)
        ->name('warehouse.entry');

    Route::get('driver', fn () => redirect()->route('driver.dashboard'))
        ->middleware('starter.permission:'.Permission::AccessDriver->value)
        ->name('driver.entry');

    Route::prefix('warehouse')->name('warehouse.')->middleware(
        'starter.permission:'.Permission::AccessWarehouse->value,
    )->group(function (): void {
        Route::get('otp', [WarehouseAccessOtpController::class, 'show'])->name('otp.show');
        Route::post('otp/verify', [WarehouseAccessOtpController::class, 'verify'])->middleware('throttle:admin-otp')->name('otp.verify');
        Route::post('otp/resend', [WarehouseAccessOtpController::class, 'resend'])->middleware('throttle:admin-otp')->name('otp.resend');
    });

    Route::prefix('warehouse')->name('warehouse.')->middleware([
        'starter.permission:'.Permission::AccessWarehouse->value,
        'warehouse.otp',
    ])->group(function (): void {
        Route::get('dashboard', WarehouseDashboardController::class)->name('dashboard');
        Route::get('arrivals', WarehouseArrivalsController::class)->name('arrivals.index');
        Route::get('inventory', WarehouseInventoryController::class)->name('inventory.index');
        Route::get('deliveries', WarehouseDeliveriesController::class)->name('deliveries.index');

        Route::middleware('starter.permission:'.Permission::ManageWarehouseOperations->value)->group(function (): void {
            Route::post('arrivals/confirm-by-po', [WarehouseOperationsController::class, 'confirmArrivalsByPo'])
                ->name('arrivals.confirm-by-po');
            Route::post('arrivals/{arrival}/confirm', [WarehouseOperationsController::class, 'confirmArrival'])
                ->name('arrivals.confirm');
            Route::post('opening-stock', [WarehouseOperationsController::class, 'storeOpeningStock'])
                ->name('opening-stock.store');
            Route::post('deliveries', [WarehouseOperationsController::class, 'storeDelivery'])
                ->name('deliveries.store');
            Route::post('deliveries/bulk', [WarehouseOperationsController::class, 'storeBulkDeliveries'])
                ->name('deliveries.store-bulk');
            Route::post('deliveries/bulk-dispatch', [WarehouseOperationsController::class, 'dispatchBulk'])
                ->name('deliveries.dispatch-bulk');
            Route::post('deliveries/{delivery}/dispatch', [WarehouseOperationsController::class, 'dispatch'])
                ->name('deliveries.dispatch');
            Route::post('deliveries/{delivery}/deliver', [WarehouseOperationsController::class, 'deliver'])
                ->name('deliveries.deliver');
            Route::delete('deliveries/{delivery}', [WarehouseOperationsController::class, 'destroyDelivery'])
                ->name('deliveries.destroy');
            Route::put('deliveries/{delivery}', [WarehouseOperationsController::class, 'updateDelivery'])
                ->name('deliveries.update');
            Route::put('shipments/{shipment_reference}', [WarehouseOperationsController::class, 'updateShipment'])
                ->name('shipments.update');
            Route::delete('shipments/{shipment_reference}', [WarehouseOperationsController::class, 'destroyShipment'])
                ->name('shipments.destroy');
            Route::post('shipments/{shipment_reference}/dispatch', [WarehouseOperationsController::class, 'dispatchShipment'])
                ->name('shipments.dispatch');
        });
    });

    Route::prefix('driver')->name('driver.')->middleware(
        'starter.permission:'.Permission::AccessDriver->value,
    )->group(function (): void {
        Route::get('otp', [DriverAccessOtpController::class, 'show'])->name('otp.show');
        Route::post('otp/verify', [DriverAccessOtpController::class, 'verify'])->middleware('throttle:admin-otp')->name('otp.verify');
        Route::post('otp/resend', [DriverAccessOtpController::class, 'resend'])->middleware('throttle:admin-otp')->name('otp.resend');
    });

    Route::prefix('driver')->name('driver.')->middleware([
        'starter.permission:'.Permission::AccessDriver->value,
        'driver.otp',
    ])->group(function (): void {
        Route::get('dashboard', [DriverDashboardController::class, 'index'])->name('dashboard');
        Route::get('suggestions', [DriverDashboardController::class, 'suggestions'])->name('suggestions');

        Route::middleware('starter.permission:'.Permission::ManageDriverOperations->value)->group(function (): void {
            Route::post('deliveries/{delivery}/deliver', [DriverOperationsController::class, 'deliver'])
                ->name('deliveries.deliver');
        });
    });

    Route::prefix('upload/{uploadType:slug}')->name('receiving.upload.')->group(function (): void {
        Route::get('/', [UploadPageController::class, 'show'])->name('show');
        Route::post('otp/verify', [UploadPageController::class, 'verify'])->middleware('throttle:upload-otp')->name('otp.verify');
        Route::post('otp/resend', [UploadPageController::class, 'resend'])->middleware('throttle:upload-otp')->name('otp.resend');
        Route::middleware('upload.otp')->group(function (): void {
            Route::get('uploads', [UploadPageController::class, 'history'])->name('history');
            Route::get('uploads/{upload}/edit', [UploadPageController::class, 'editVerified'])->name('history.edit');
            Route::put('uploads/{upload}', [UploadPageController::class, 'updateVerified'])->name('history.update');
            Route::get('files/{file}/preview', [UploadPageController::class, 'filePreview'])->name('files.preview');
            Route::post('transactions', [UploadTransactionController::class, 'store'])->name('transactions.store');
            Route::post('transactions/{upload}/complete', [UploadTransactionController::class, 'complete'])->name('transactions.complete');
        });
    });

    Route::put('receiving/staging/{file}', StagingFileController::class)
        ->middleware('signed:relative')
        ->name('receiving.staging.put');
    Route::post('receiving/uploads/{upload}/resend', [UploadRecordController::class, 'resend'])
        ->middleware(['admin.otp', 'throttle:3,1'])
        ->name('receiving.uploads.resend');
    Route::post('receiving/uploads/{upload}/retry-ai', [UploadRecordController::class, 'retryAi'])
        ->middleware(['admin.otp', 'throttle:3,1'])
        ->name('receiving.uploads.retry-ai');
    Route::post('receiving/files/{file}/url', [UploadRecordController::class, 'fileUrl'])
        ->middleware(['admin.otp', 'throttle:30,1'])
        ->name('receiving.files.url');
    Route::get('receiving/files/{file}/stream', [UploadRecordController::class, 'stream'])
        ->middleware('admin.otp')
        ->name('receiving.files.stream');

    Route::prefix('admin')->name('admin.')->middleware(
        'starter.permission:'.Permission::AccessAdmin->value,
    )->group(function (): void {
        Route::get('otp', [AdminAccessOtpController::class, 'show'])->name('otp.show');
        Route::post('otp/verify', [AdminAccessOtpController::class, 'verify'])->middleware('throttle:admin-otp')->name('otp.verify');
        Route::post('otp/resend', [AdminAccessOtpController::class, 'resend'])->middleware('throttle:admin-otp')->name('otp.resend');
    });

    Route::prefix('admin')->name('admin.')->middleware([
        'starter.permission:'.Permission::AccessAdmin->value,
        'admin.otp',
    ])->group(function (): void {
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');

        Route::middleware('starter.permission:'.Permission::ViewUsers->value)->group(function (): void {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
        });
        Route::middleware('starter.permission:'.Permission::ManageUsers->value)->group(function (): void {
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::put('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        });
        Route::middleware('starter.permission:'.Permission::ManageUploadAccess->value)->group(function (): void {
            Route::get('upload-access', [UploadAccessController::class, 'index'])->name('access.index');
            Route::put('upload-access/{user}', [UploadAccessController::class, 'update'])->name('access.update');
        });
        Route::middleware('starter.permission:'.Permission::ManageRecipients->value)->group(function (): void {
            Route::get('recipients', [EmailRecipientController::class, 'index'])->name('recipients.index');
            Route::post('recipients', [EmailRecipientController::class, 'store'])->name('recipients.store');
            Route::put('recipients/{recipient}', [EmailRecipientController::class, 'update'])->name('recipients.update');
            Route::delete('recipients/{recipient}', [EmailRecipientController::class, 'destroy'])->name('recipients.destroy');
        });
        Route::middleware('starter.permission:'.Permission::ViewUploads->value)->group(function (): void {
            Route::get('uploads', [UploadLogController::class, 'index'])->name('uploads.index');
            Route::get('purchase-orders/reports', [PurchaseOrderReportController::class, 'index'])->name('purchase-orders.reports.index');
            Route::get('purchase-orders/reports/ordered-items', [PurchaseOrderReportController::class, 'orderedItems'])->name('purchase-orders.reports.ordered-items');
            Route::get('purchase-orders/reports/missing-items', [PurchaseOrderReportController::class, 'missingItems'])->name('purchase-orders.reports.missing-items');
            Route::get('purchase-orders/reports/recurring-items', [PurchaseOrderReportController::class, 'recurringItems'])->name('purchase-orders.reports.recurring-items');
            Route::get('purchase-orders/reports/warehouse-dwell', WarehouseDwellReportController::class)
                ->middleware('starter.permission:'.Permission::ViewWarehouseReports->value)
                ->name('purchase-orders.reports.warehouse-dwell');
            Route::get('purchase-orders/items', [PurchaseOrderItemScheduleController::class, 'index'])->name('purchase-orders.items.index');
            Route::get('purchase-orders', [UploadLogController::class, 'index'])->name('purchase-orders.index');
            Route::get('uploads/{upload}', [UploadRecordController::class, 'showAdmin'])->name('uploads.show');
        });
        Route::middleware('starter.permission:'.Permission::RetryOperations->value)->group(function (): void {
            Route::post('uploads/{upload}/extractions/{extraction}/purchase-order-link', [PurchaseOrderDocumentLinkController::class, 'store'])->name('uploads.purchase-order-link.store');
            Route::delete('uploads/{upload}/extractions/{extraction}/purchase-order-link', [PurchaseOrderDocumentLinkController::class, 'destroy'])->name('uploads.purchase-order-link.destroy');
            Route::post('uploads/{upload}/resend-receiving', [UploadLogController::class, 'resendReceiving'])->name('uploads.resend-receiving');
            Route::post('uploads/{upload}/resend-review', [UploadLogController::class, 'resendReview'])->name('uploads.resend-review');
            Route::post('uploads/{upload}/reprocess', [UploadLogController::class, 'reprocess'])->name('uploads.reprocess');
        });
        Route::get('activity', ActivityLogController::class)
            ->middleware('starter.permission:'.Permission::ViewActivityLogs->value)
            ->name('activity.index');
        Route::middleware('starter.permission:'.Permission::ManageSettings->value)->group(function (): void {
            Route::get('receiving-settings', [ReceivingSettingsController::class, 'edit'])->name('receiving-settings.edit');
            Route::post('upload-types/{uploadType}/toggle', [ReceivingSettingsController::class, 'toggleUploadType'])->name('upload-types.toggle');
            Route::post('upload-types/{uploadType}/legacy-import', [LegacyDataImportController::class, 'store'])->name('upload-types.legacy-import');
            Route::post('purchase-orders/items', [PurchaseOrderItemScheduleController::class, 'store'])->name('purchase-orders.items.store');
            Route::put('purchase-orders/items/{item}', [PurchaseOrderItemScheduleController::class, 'update'])->name('purchase-orders.items.update');
            Route::delete('purchase-orders/items/{item}', [PurchaseOrderItemScheduleController::class, 'destroy'])->name('purchase-orders.items.destroy');
            Route::delete('system-reset', SystemResetController::class)
                ->name('system-reset');
        });

        Route::prefix('sheets-sync')->name('sheets-sync.')->group(function (): void {
            Route::get('/', [GoogleSheetSyncController::class, 'index'])->name('index');
            Route::get('items', [GoogleSheetSyncController::class, 'items'])->name('items');
            Route::post('refresh/{slug}', [GoogleSheetSyncController::class, 'refresh'])->name('refresh');
            Route::post('import-raw/{slug}', [GoogleSheetSyncController::class, 'importRaw'])->name('import-raw');
            Route::post('sync-serial/{slug}/{serialNumber}', [GoogleSheetSyncController::class, 'syncSerial'])->name('sync-serial');
            Route::post('batch-preview', [GoogleSheetSyncController::class, 'batchPreview'])->name('batch-preview');
            Route::post('batch-sync', [GoogleSheetSyncController::class, 'batchSync'])->name('batch-sync');
            Route::get('progress', [GoogleSheetSyncController::class, 'progress'])->name('progress');
            Route::post('cancel', [GoogleSheetSyncController::class, 'cancelSync'])->name('cancel');
            Route::post('config', [GoogleSheetSyncController::class, 'updateConfig'])->name('config');
            Route::post('generate-secret', [GoogleSheetSyncController::class, 'generateWebhookSecret'])->name('generate-secret');
        });
    });
});

Route::prefix('review')->name('receiving.review.')->middleware('throttle:60,1')->group(function (): void {
    Route::get('completed', fn () => Inertia::render('review/completed'))->name('completed');
    Route::get('{token}', [ReviewController::class, 'show'])->name('show');
    Route::put('{token}/extractions/{extraction}', [ReviewController::class, 'update'])->name('update');
    Route::post('{token}/verify', [ReviewController::class, 'verify'])->middleware('throttle:10,1')->name('verify');
    Route::get('{token}/files/{file}', ReviewFileController::class)->name('file');
});

Route::get('receiving/notification/{upload}', NotificationViewController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->name('receiving.notification.show');

require __DIR__.'/settings.php';
