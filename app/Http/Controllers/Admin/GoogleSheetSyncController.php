<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetExtraction;
use App\Models\GoogleSheetFile;
use App\Models\GoogleSheetLog;
use App\Models\GoogleSheetSyncJob;
use App\Services\GoogleSheets\GoogleSheetsApiService;
use App\Services\GoogleSheets\GoogleSheetsDataSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GoogleSheetSyncController extends Controller
{
    public function __construct(
        private readonly GoogleSheetsDataSyncService $syncService,
        private readonly GoogleSheetsApiService $apiService
    ) {}

    /**
     * Render the Google Sheets Sync dashboard page.
     */
    public function index(Request $request): Response
    {
        $activeSheet = $request->input('sheet', 'a2z2go');

        // Ensure every sheet configuration has an active webhook secret
        $sheets = GoogleSheetConfig::query()->orderBy('id')->get();
        foreach ($sheets as $sheet) {
            if (empty($sheet->webhook_secret)) {
                $sheet->update([
                    'webhook_secret' => 'whsec_'.Str::random(32),
                ]);
            }
        }
        $sheets = GoogleSheetConfig::query()->orderBy('id')->get();
        $overview = $this->syncService->getOverviewStats();

        return Inertia::render('admin/sheets-sync/index', [
            'sheets' => $sheets,
            'overview' => $overview,
            'initialSheet' => $activeSheet,
        ]);
    }

    /**
     * Paginated items endpoint for table browsing, search, and filters.
     */
    public function items(Request $request): JsonResponse
    {
        $sheet = $request->input('sheet', 'a2z2go');
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status', 'all');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(10, (int) $request->input('limit', 25)));

        $query = GoogleSheetLog::query()
            ->where('sheet_slug', $sheet)
            ->with([
                'syncedUpload:id,serial_number,submission_id,file_count,review_status,ai_status,created_at',
            ]);

        // Filter by Status
        if ($status === 'pending') {
            $query->where('is_synced_to_db', false);
        } elseif ($status === 'synced') {
            $query->where('is_synced_to_db', true);
        } elseif ($status === 'updates_available') {
            $query->where('is_synced_to_db', true)->whereColumn('updated_at', '>', 'synced_at');
        } elseif ($status === 'verified') {
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(review_status) = ?', ['verified']));
        } elseif ($status === 'with_extractions') {
            $query->whereExists(function ($sub) use ($sheet) {
                $sub->selectRaw(1)
                    ->from('google_sheet_extractions')
                    ->whereColumn('google_sheet_extractions.serial_number', 'google_sheet_logs.serial_number')
                    ->where('google_sheet_extractions.sheet_slug', $sheet)
                    ->where(fn ($sq) => $sq->whereNotNull('raw_ai_json')->orWhereNotNull('corrected_json'));
            });
        } elseif ($status === 'pending_r2') {
            $query->whereExists(function ($sub) use ($sheet) {
                $sub->selectRaw(1)
                    ->from('google_sheet_files')
                    ->whereColumn('google_sheet_files.serial_number', 'google_sheet_logs.serial_number')
                    ->where('google_sheet_files.sheet_slug', $sheet)
                    ->where(fn ($sq) => $sq->whereNull('r2_url')->orWhere('r2_url', ''));
            });
        } elseif ($status === 'all_in_r2') {
            $query->whereExists(function ($sub) use ($sheet) {
                $sub->selectRaw(1)
                    ->from('google_sheet_files')
                    ->whereColumn('google_sheet_files.serial_number', 'google_sheet_logs.serial_number')
                    ->where('google_sheet_files.sheet_slug', $sheet);
            })->whereNotExists(function ($sub) use ($sheet) {
                $sub->selectRaw(1)
                    ->from('google_sheet_files')
                    ->whereColumn('google_sheet_files.serial_number', 'google_sheet_logs.serial_number')
                    ->where('google_sheet_files.sheet_slug', $sheet)
                    ->where(fn ($sq) => $sq->whereNull('r2_url')->orWhere('r2_url', ''));
            });
        }

        // Search by Serial Number, File Name, or File ID
        if ($search !== '') {
            $cleanSn = preg_replace('/[^\d]/', '', $search);
            $query->where(function (Builder $q) use ($search, $cleanSn, $sheet) {
                if ($cleanSn !== '') {
                    $q->orWhere('serial_number', (int) $cleanSn);
                }
                $q->orWhere('reviewed_by', 'like', "%{$search}%")
                    ->orWhere('uploader_location', 'like', "%{$search}%")
                    ->orWhereExists(function ($sub) use ($search, $sheet) {
                        $sub->selectRaw(1)
                            ->from('google_sheet_files')
                            ->whereColumn('google_sheet_files.serial_number', 'google_sheet_logs.serial_number')
                            ->where('google_sheet_files.sheet_slug', $sheet)
                            ->where(function ($fq) use ($search) {
                                $fq->where('file_name', 'like', "%{$search}%")
                                    ->orWhere('file_id', 'like', "%{$search}%");
                            });
                    });
            });
        }

        // Dynamic Sorting with Smart Priority as Default
        $sort = (string) $request->input('sort', 'priority');
        if ($sort === 'priority') {
            $query->orderByRaw('CASE 
                WHEN is_synced_to_db = false THEN 1 
                WHEN is_synced_to_db = true AND updated_at > synced_at THEN 2 
                ELSE 3 
            END ASC')
                ->orderBy('serial_number', 'desc');
        } elseif ($sort === 'sn_desc') {
            $query->orderBy('serial_number', 'desc');
        } elseif ($sort === 'sn_asc') {
            $query->orderBy('serial_number', 'asc');
        } elseif ($sort === 'latest') {
            $query->orderBy('updated_at', 'desc');
        } else {
            $query->orderBy('serial_number', 'asc');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $serialNumbers = collect($paginator->items())->pluck('serial_number')->all();
        if (! empty($serialNumbers)) {
            $filesBySerial = GoogleSheetFile::query()
                ->where('sheet_slug', $sheet)
                ->whereIn('serial_number', $serialNumbers)
                ->orderBy('id')
                ->get()
                ->groupBy('serial_number');

            $extractionsBySerial = GoogleSheetExtraction::query()
                ->where('sheet_slug', $sheet)
                ->whereIn('serial_number', $serialNumbers)
                ->get()
                ->keyBy('serial_number');

            foreach ($paginator->items() as $log) {
                $log->setRelation('files', $filesBySerial->get($log->serial_number, collect()));
                $log->setRelation('extraction', $extractionsBySerial->get($log->serial_number));
            }
        }

        return response()->json([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Refresh data from Google Sheets API v4.
     */
    public function refresh(string $slug): JsonResponse
    {
        try {
            $result = $this->syncService->refreshFromApi($slug);
            $sheetConfig = GoogleSheetConfig::query()->where('slug', $slug)->first();
            $overview = $this->syncService->getOverviewStats();

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'stats' => $result,
                'sheet' => $sheetConfig,
                'overview' => $overview,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Direct Raw HTML / CSV Table import.
     */
    public function importRaw(string $slug, Request $request): JsonResponse
    {
        $content = (string) $request->input('content', '');
        if (trim($content) === '') {
            return response()->json(['error' => 'No content provided to import.'], 422);
        }

        try {
            $result = $this->syncService->importRaw($slug, $content);
            $sheetConfig = GoogleSheetConfig::query()->where('slug', $slug)->first();
            $overview = $this->syncService->getOverviewStats();

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'stats' => $result,
                'sheet' => $sheetConfig,
                'overview' => $overview,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Single Serial Number Database Sync.
     */
    public function syncSerial(string $slug, int $serialNumber): JsonResponse
    {
        try {
            $result = $this->syncService->syncSerialNumber($slug, $serialNumber);
            $sheetConfig = GoogleSheetConfig::query()->where('slug', $slug)->first();
            $overview = $this->syncService->getOverviewStats();

            return response()->json([
                'success' => true,
                'upload_id' => $result['upload_id'],
                'message' => $result['message'],
                'sheet' => $sheetConfig,
                'overview' => $overview,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Batch Sync Preview calculation.
     */
    public function batchPreview(Request $request): JsonResponse
    {
        $sheetSlug = $request->input('sheetSlug', 'a2z2go');
        $limit = $request->input('limit') ? (int) $request->input('limit') : null;
        $includeSerials = $request->input('includeSerials');
        $excludeSerials = $request->input('excludeSerials');
        $sortOrder = $request->input('sortOrder', 'ASC');

        $preview = $this->syncService->calculateBatchPreview(
            $sheetSlug,
            $limit,
            $includeSerials,
            $excludeSerials,
            $sortOrder
        );

        return response()->json($preview);
    }

    /**
     * Start Batch Sync operation.
     */
    public function batchSync(Request $request): JsonResponse
    {
        $sheetSlug = $request->input('sheetSlug', 'a2z2go');
        $limit = $request->input('limit') ? (int) $request->input('limit') : null;
        $includeSerials = $request->input('includeSerials');
        $excludeSerials = $request->input('excludeSerials');
        $sortOrder = $request->input('sortOrder', 'ASC');

        $batchId = (string) Str::uuid();

        // Run batch sync execution
        $result = $this->syncService->runBatchSync(
            $sheetSlug,
            $batchId,
            $limit,
            $includeSerials,
            $excludeSerials,
            $sortOrder
        );

        $overview = $this->syncService->getOverviewStats();
        $sheetConfig = GoogleSheetConfig::query()->where('slug', $sheetSlug)->first();

        return response()->json([
            'success' => true,
            'batchId' => $batchId,
            'result' => $result,
            'sheet' => $sheetConfig,
            'overview' => $overview,
        ]);
    }

    /**
     * Poll active batch sync progress.
     */
    public function progress(): JsonResponse
    {
        /** @var GoogleSheetSyncJob|null $latestJob */
        $latestJob = GoogleSheetSyncJob::query()->latest('id')->first();

        if (! $latestJob) {
            return response()->json([
                'isRunning' => false,
                'sheetSlug' => null,
                'total' => 0,
                'current' => 0,
                'successful' => 0,
                'failed' => 0,
                'currentSerial' => null,
                'percentage' => 0,
                'statusText' => 'Idle',
                'logs' => [],
            ]);
        }

        $percentage = $latestJob->total_items > 0
            ? round(($latestJob->processed_items / $latestJob->total_items) * 100)
            : ($latestJob->status === 'completed' ? 100 : 0);

        return response()->json([
            'isRunning' => $latestJob->status === 'running',
            'sheetSlug' => $latestJob->sheet_slug,
            'total' => $latestJob->total_items,
            'current' => $latestJob->processed_items,
            'successful' => $latestJob->successful_items,
            'failed' => $latestJob->failed_items,
            'currentSerial' => $latestJob->current_serial,
            'percentage' => $percentage,
            'statusText' => $latestJob->current_status_text ?: ucfirst($latestJob->status),
            'startedAt' => $latestJob->started_at?->toIso8601String(),
            'completedAt' => $latestJob->completed_at?->toIso8601String(),
            'logs' => $latestJob->logs ?? [],
        ]);
    }

    /**
     * Cancel ongoing batch sync.
     */
    public function cancelSync(): JsonResponse
    {
        GoogleSheetSyncJob::query()
            ->where('status', 'running')
            ->update([
                'status' => 'cancelled',
                'completed_at' => now(),
                'current_status_text' => 'Cancelled by user.',
            ]);

        return response()->json(['success' => true, 'message' => 'Sync cancelled.']);
    }

    /**
     * Update sheet configuration (Spreadsheet ID, URL, name, webhook settings).
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'exists:google_sheet_configs,slug'],
            'spreadsheet_id' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:100'],
            'webhook_secret' => ['nullable', 'string', 'max:64'],
            'auto_sync_on_webhook' => ['nullable', 'boolean'],
        ]);

        $cleanId = $this->apiService->extractSpreadsheetId($validated['spreadsheet_id'] ?? '');

        /** @var GoogleSheetConfig $config */
        $config = GoogleSheetConfig::query()->where('slug', $validated['slug'])->firstOrFail();
        $config->update([
            'spreadsheet_id' => $cleanId ?: $config->spreadsheet_id,
            'name' => $validated['name'] ?: $config->name,
            'webhook_secret' => $validated['webhook_secret'] ?? $config->webhook_secret,
            'auto_sync_on_webhook' => $validated['auto_sync_on_webhook'] ?? $config->auto_sync_on_webhook,
        ]);

        return response()->json([
            'success' => true,
            'sheet' => $config->fresh(),
            'message' => "Settings updated for {$config->name}.",
        ]);
    }

    /**
     * Generate or regenerate a fresh secure Webhook Secret for a specific sheet.
     */
    public function generateWebhookSecret(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'exists:google_sheet_configs,slug'],
        ]);

        /** @var GoogleSheetConfig $config */
        $config = GoogleSheetConfig::query()->where('slug', $validated['slug'])->firstOrFail();
        $secret = 'whsec_'.Str::random(32);
        $config->update(['webhook_secret' => $secret]);

        return response()->json([
            'success' => true,
            'secret' => $secret,
            'sheet' => $config->fresh(),
            'message' => "Fresh webhook secret generated for {$config->name}.",
        ]);
    }
}
