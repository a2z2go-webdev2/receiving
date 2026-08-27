<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoogleSheetConfig;
use App\Services\GoogleSheets\GoogleSheetsDataSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        /** @var GoogleSheetConfig|null $config */
        $config = GoogleSheetConfig::query()->where('slug', $slug)->first();

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

        if ($config->webhook_secret && (! $providedSecret || ! hash_equals($config->webhook_secret, (string) $providedSecret))) {
            Log::warning("Google Sheets Webhook unauthorized attempt for lane: {$slug}");

            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: Invalid webhook secret token.',
            ], 401);
        }

        try {
            $payload = $request->all();
            $serialNumber = (int) ($payload['serial_number'] ?? $payload['serialNumber'] ?? $payload['log']['Serial Number'] ?? 0);

            // Case A: Full direct payload sent from Apps Script
            if (isset($payload['log']) || isset($payload['files']) || isset($payload['extraction'])) {
                $logs = isset($payload['log']) ? [$payload['log']] : [];
                $files = $payload['files'] ?? [];
                $extractions = isset($payload['extraction']) ? [$payload['extraction']] : [];

                $this->syncService->stageData($slug, $logs, $files, $extractions);

                $uploadId = null;
                $isSynced = false;

                if ($serialNumber > 0 && $config->auto_sync_on_webhook) {
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
