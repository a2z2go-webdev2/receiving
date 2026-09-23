<?php

namespace App\Features\Receiving\Services;

use App\Enums\UploadWorkflow;
use App\Models\ActivityLog;
use App\Models\AiExtraction;
use App\Models\GoogleSheetLog;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderItemArrival;
use App\Models\ReceivingUpload;
use App\Models\User;
use App\Models\WarehouseProgressEvent;
use App\Models\WarehouseStockLot;
use App\Services\GoogleSheets\GoogleSheetsDataSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteReceivingUploadService
{
    public function __construct(
        private readonly PurchaseOrderLinker $linker,
        private readonly ActivityLogger $activity,
        private readonly UploadSerialNumber $serials,
    ) {}

    /**
     * Safely delete a ReceivingUpload and its related files, unallocated stock, and links.
     *
     * @throws ValidationException
     */
    public function delete(ReceivingUpload $upload, ?User $actor = null, ?Request $request = null): void
    {
        $upload->loadMissing([
            'uploadType',
            'files',
            'extractions',
            'poExtractions',
            'purchaseOrderItemArrivals',
        ]);

        $isPo = $upload->uploadType->workflow === UploadWorkflow::PurchaseOrder;
        $typeLabel = $isPo ? 'purchase order' : 'receive log';
        $serial = $this->serials->prefix($upload->uploadType).'-'.$this->serials->number($upload);

        // 1. Warehouse safety check
        $uploadArrivalIds = $upload->purchaseOrderItemArrivals->pluck('id');
        $poArrivalIds = $isPo && $upload->poExtractions->isNotEmpty()
            ? PurchaseOrderItemArrival::query()->whereIn('po_extraction_id', $upload->poExtractions->pluck('id'))->pluck('id')
            : collect();
        $allArrivalIds = $uploadArrivalIds->merge($poArrivalIds)->unique()->filter();
        $extractionIds = $upload->extractions->pluck('id');

        $lotsQuery = WarehouseStockLot::query()->where(function ($q) use ($upload, $allArrivalIds, $extractionIds) {
            $q->where('receiving_upload_id', $upload->getKey());
            if ($allArrivalIds->isNotEmpty()) {
                $q->orWhereIn('purchase_order_item_arrival_id', $allArrivalIds);
            }
            if ($extractionIds->isNotEmpty()) {
                $q->orWhereIn('ai_extraction_id', $extractionIds);
            }
        });

        $hasAllocations = (clone $lotsQuery)->has('allocations')->exists();
        if ($hasAllocations) {
            throw ValidationException::withMessages([
                'upload' => "Cannot delete this {$typeLabel} because items from it have already been received and allocated to warehouse deliveries.",
            ]);
        }

        // 2. Identify linked documents for post-deletion status reconciliation
        $affectedPoExtractions = collect();
        $affectedAiExtractions = collect();

        if (! $isPo && $extractionIds->isNotEmpty()) {
            $affectedPoExtractions = PoExtraction::query()
                ->whereHas('activeDocumentLinks', fn ($q) => $q->whereIn('ai_extraction_id', $extractionIds))
                ->get();
        } elseif ($isPo && $upload->poExtractions->isNotEmpty()) {
            $affectedAiExtractions = AiExtraction::query()
                ->whereHas('activePurchaseOrderLink', fn ($q) => $q->whereIn('po_extraction_id', $upload->poExtractions->pluck('id')))
                ->get();
        }

        // 3. Purge physical files from storage disk
        $disk = Storage::disk((string) config('receiving.disk'));
        foreach ($upload->files as $file) {
            $paths = array_filter([$file->r2_staging_object_key, $file->r2_object_key]);
            foreach ($paths as $path) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        }

        // 4. Clean up unallocated stock lots & progress events
        $unallocatedLotIds = (clone $lotsQuery)->pluck('id');
        if ($unallocatedLotIds->isNotEmpty()) {
            WarehouseProgressEvent::query()
                ->where('aggregate_type', 'stock_lot')
                ->whereIn('aggregate_id', $unallocatedLotIds)
                ->delete();
            (clone $lotsQuery)->delete();
        }

        // 5. Reset Google Sheets sync log if this upload originated from a sheet
        if (Schema::hasTable('google_sheet_logs')) {
            $affectedSheetSlugs = GoogleSheetLog::query()
                ->where('synced_receiving_upload_id', $upload->getKey())
                ->pluck('sheet_slug')
                ->unique();

            GoogleSheetLog::query()
                ->where('synced_receiving_upload_id', $upload->getKey())
                ->update([
                    'is_synced_to_db' => false,
                    'synced_receiving_upload_id' => null,
                    'synced_at' => null,
                ]);

            if ($affectedSheetSlugs->isNotEmpty() && app()->bound(GoogleSheetsDataSyncService::class)) {
                /** @var GoogleSheetsDataSyncService $syncService */
                $syncService = app(GoogleSheetsDataSyncService::class);
                foreach ($affectedSheetSlugs as $slug) {
                    $syncService->updateSheetCounts($slug);
                }
            }
        }

        // 6. Delete upload-related activity logs before deleting upload
        ActivityLog::query()->where('receiving_upload_id', $upload->getKey())->delete();

        // 7. Delete the upload record inside a transaction (DB cascades handle dependent tables)
        DB::transaction(function () use ($upload): void {
            $upload->delete();
        });

        // 8. Reconcile linked statuses
        foreach ($affectedPoExtractions as $poExtraction) {
            $this->linker->refreshPoArrivalStatus($poExtraction);
        }

        foreach ($affectedAiExtractions as $aiExtraction) {
            $this->linker->syncExtraction($aiExtraction);
        }

        // 9. Record deletion in activity log
        $this->activity->record(
            $isPo ? 'purchase_order' : 'receiving',
            $isPo ? 'purchase_order_deleted' : 'upload_deleted',
            'success',
            ucfirst($typeLabel)." {$serial} was permanently deleted.",
            $actor,
            null,
            $request,
        );
    }
}
