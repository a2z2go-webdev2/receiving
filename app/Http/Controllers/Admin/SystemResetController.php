<?php

namespace App\Http\Controllers\Admin;

use App\Features\Receiving\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

class SystemResetController extends Controller
{
    public function __invoke(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:RESET SYSTEM'],
            'password' => ['required', 'string', 'current_password'],
        ]);

        $admin = $request->user();
        abort_if(! $admin, 403);

        // 1. Purge R2 bucket files
        $disk = Storage::disk((string) config('receiving.disk'));
        $files = $disk->allFiles();

        foreach (array_chunk($files, 1000) as $chunk) {
            $disk->delete($chunk);
        }

        // 2. Truncate transactional and user-configured data while retaining item records.
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function (): void {
                foreach ([
                    'warehouse_progress_events',
                    'warehouse_allocations',
                    'warehouse_delivery_lines',
                    'warehouse_deliveries',
                    'warehouse_stock_lots',
                    'purchase_order_item_arrivals',
                    'purchase_order_document_links',
                    'purchase_order_item_fulfillments',
                    'po_extraction_items',
                    'po_extractions',
                    'ai_extractions',
                    'uploaded_files',
                    'review_links',
                    'upload_otps',
                    'receiving_uploads',
                    'activity_logs',
                    'auth_audit_logs',
                    'admin_access_otps',
                    'system_settings',
                    'password_reset_tokens',
                    'jobs',
                    'job_batches',
                    'failed_jobs',
                ] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->truncate();
                    }
                }

                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('id', '!=', Session::getId())->delete();
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 5. Clear Cache
        Artisan::call('cache:clear');

        // 6. Log activity
        $activity->record('system', 'factory_reset', 'success', 'System transactional data was purged. User accounts, access permissions, email recipients, and item records were preserved.', $admin, null, $request);

        return back()->with('status', 'System factory reset successfully.');
    }
}
