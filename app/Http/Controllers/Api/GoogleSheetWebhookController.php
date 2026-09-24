<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPurchaseOrderSheet;
use App\Models\GoogleSheetConfig;
use App\Services\GoogleSheets\GoogleSheetsDataSyncService;
use App\Services\GoogleSheets\PurchaseOrderSheetSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleSheetWebhookController extends Controller
{
    public function __construct(
        private readonly GoogleSheetsDataSyncService $syncService
    ) {}

    /**
     * Handle incoming webhook notification or payload from Google Sheets / Apps Script.
     */
    public function handle(Request $request, string $slug): JsonResponse
    {
        $slug = strtolower(trim($slug));

        $config = null;
        if ($slug === 'purchase-orders' || $slug === 'po') {
            $config = GoogleSheetConfig::query()->where('slug', $slug)->first();
            if (! $config) {
                $config = GoogleSheetConfig::query()->firstOrCreate(
                    ['slug' => 'purchase-orders'],
                    [
                        'name' => 'Purchase Orders Master',
                        'sheet_type' => 'purchase_order',
                        'spreadsheet_id' => config('services.google.purchase_orders_sheet_id'),
                        'webhook_secret' => config('services.google.webhook_secret') ?: ('whsec_'.Str::random(32)),
                    ]
                );
            }
        } else {
            $config = GoogleSheetConfig::query()->where('slug', $slug)->first();
        }

        if (! $config) {
            return response()->json([
                'success' => false,
                'error' => "Sheet configuration not found for lane: {$slug}",
            ], 404);
        }

        // Authenticate Webhook Secret
        $providedSecret = $request->header('X-Webhook-Secret')
            ?? $request->bearerToken()
            ?? $request->input('secret');

        $authorizedSecret = $config->webhook_secret ?: config('services.google.webhook_secret');

        if (empty($authorizedSecret) || ! $providedSecret || ! hash_equals($authorizedSecret, (string) $providedSecret)) {
            Log::warning("Google Sheets Webhook unauthorized attempt for lane: {$slug}");

            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: Invalid or unconfigured webhook secret token.',
            ], 401);
        }

        try {
            if ($config->sheet_type === 'purchase_order' || $slug === 'purchase-orders' || $slug === 'po') {
                $tabInput = (string) ($request->input('tab') ?? $request->input('sheet') ?? '');
                $targetSlug = 'all';

                if ($tabInput !== '') {
                    $targetSlug = app(PurchaseOrderSheetSyncService::class)->resolveSlugFromTabName($tabInput);
                } elseif ($config->slug !== 'purchase-orders' && $config->slug !== 'po') {
                    $targetSlug = $config->slug;
                }

                SyncPurchaseOrderSheet::dispatch($targetSlug);

                return response()->json([
                    'success' => true,
                    'message' => "Queued purchase order synchronization for target '{$targetSlug}' via webhook.",
                    'sheet' => $slug,
                    'target_slug' => $targetSlug,
                ]);
            }

            $payload = $request->all();
            $serialNumber = (int) ($payload['serial_number'] ?? $payload['serialNumber'] ?? $payload['log']['Serial Number'] ?? 0);

            // Case A: Full direct payload sent from Apps Script
            if (isset($payload['log']) || isset($payload['files']) || isset($payload['extraction'])) {
                if ($serialNumber <= 0) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Invalid serial number provided in webhook payload.',
                    ], 422);
                }

                $logs = isset($payload['log']) ? [$payload['log']] : [];
                $files = $payload['files'] ?? [];
                $extractions = isset($payload['extraction']) ? [$payload['extraction']] : [];

                $this->syncService->stageData($slug, $logs, $files, $extractions);

                $uploadId = null;
                $isSynced = false;

                if ($config->auto_sync_on_webhook) {
                    $syncRes = $this->syncService->syncSerialNumber($slug, $serialNumber);
                    $uploadId = $syncRes['upload_id'] ?? null;
                    $isSynced = true;
                }

                return response()->json([
                    'success' => true,
                    'message' => "Staged SN-{$serialNumber} for {$config->name} via webhook.".($isSynced ? " Automatically synchronized to database (Upload #{$uploadId})." : ''),
                    'sheet' => $slug,
                    'serial_number' => $serialNumber,
                    'auto_synced' => $isSynced,
                    'upload_id' => $uploadId,
                ]);
            }

            // Case B: Ping / Trigger event (Apps Script pinging to fetch fresh rows from Google Sheets API)
            $refreshResult = $this->syncService->refreshFromApi($slug);

            $uploadId = null;
            $isSynced = false;

            if ($serialNumber > 0 && $config->auto_sync_on_webhook) {
                $syncRes = $this->syncService->syncSerialNumber($slug, $serialNumber);
                $uploadId = $syncRes['upload_id'] ?? null;
                $isSynced = true;
            }

            return response()->json([
                'success' => true,
                'message' => "Refreshed {$config->name} via webhook.".($isSynced ? " Automatically synchronized SN-{$serialNumber} (Upload #{$uploadId})." : ''),
                'sheet' => $slug,
                'stats' => $refreshResult,
                'auto_synced' => $isSynced,
                'upload_id' => $uploadId,
            ]);
        } catch (\Throwable $e) {
            Log::error("Google Sheets Webhook execution error for {$slug}: ".$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
