<?php

namespace App\Services\GoogleSheets;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkSource;
use App\Enums\ReviewStatus;
use App\Enums\UploadProcessingStatus;
use App\Enums\UserStatus;
use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseStockSource;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\PurchaseOrderItemMatcher;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Models\AiExtraction;
use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetExtraction;
use App\Models\GoogleSheetFile;
use App\Models\GoogleSheetLog;
use App\Models\GoogleSheetSyncJob;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Models\WarehouseItem;
use App\Models\WarehouseStockLot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleSheetsDataSyncService
{
    public function __construct(
        private readonly GoogleSheetsApiService $apiService,
        private readonly GoogleSheetsTableParser $tableParser,
        private readonly PurchaseOrderDataNormalizer $normalizer,
        private readonly ActivityLogger $activityLogger
    ) {}

    /**
     * Fetch & Ingest sheet data from Google Sheets API v4.
     *
     * @return array{logs: int, files: int, extractions: int, message: string}
     */
    public function refreshFromApi(string $slug): array
    {
        $config = GoogleSheetConfig::query()->where('slug', $slug)->first();
        if (! $config) {
            throw new RuntimeException("Config for sheet '{$slug}' not found.");
        }

        $sheetId = $config->spreadsheet_id ?: config("services.google.sheets_{$slug}_id");
        if (! $sheetId) {
            throw new RuntimeException("Spreadsheet ID for {$config->name} is not set. Please configure it in Settings.");
        }

        $data = $this->apiService->fetchAllTabs($sheetId);

        $this->stageData($slug, $data['logs'], $data['files'], $data['extractions']);
        $this->updateSheetCounts($slug);

        $config->update(['last_synced_at' => now()]);

        return [
            'logs' => count($data['logs']),
            'files' => count($data['files']),
            'extractions' => count($data['extractions']),
            'message' => "Successfully ingested rows from Google Sheet for {$config->name}.",
        ];
    }

    /**
     * Import raw pasted HTML table or CSV content.
     *
     * @return array{logs: int, files: int, extractions: int, message: string}
     */
    public function importRaw(string $slug, string $content): array
    {
        $config = GoogleSheetConfig::query()->where('slug', $slug)->first();
        if (! $config) {
            throw new RuntimeException("Config for sheet '{$slug}' not found.");
        }

        $data = $this->tableParser->parse($content);
        if (empty($data['logs']) && empty($data['files']) && empty($data['extractions'])) {
            throw new RuntimeException('No valid rows could be parsed from the provided text.');
        }

        $this->stageData($slug, $data['logs'], $data['files'], $data['extractions']);
        $this->updateSheetCounts($slug);

        return [
            'logs' => count($data['logs']),
            'files' => count($data['files']),
            'extractions' => count($data['extractions']),
            'message' => 'Successfully imported table records.',
        ];
    }

    /**
     * Store parsed logs, files, and extractions into staging tables.
     *
     * @param  array<int, array<string, mixed>>  $logs
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int, array<string, mixed>>  $extractions
     */
    public function stageData(string $slug, array $logs, array $files, array $extractions): void
    {
        DB::transaction(function () use ($slug, $logs, $files, $extractions): void {
            // 1. Stage Logs
            foreach ($logs as $log) {
                $snRaw = $this->tableParser->getCaseInsensitive($log, ['serial number', 'serial_number', 'sn']);
                $sn = (int) preg_replace('/[^\d]/', '', $snRaw);
                if ($sn <= 0) {
                    continue;
                }

                $timestamp = $this->tableParser->getCaseInsensitive($log, ['timestamp', 'upload timestamp', 'created_at']);
                $driveLink = $this->tableParser->getCaseInsensitive($log, ['drive folder link', 'drive_folder_link']);
                $fileCount = (int) $this->tableParser->getCaseInsensitive($log, ['file count', 'file_count'], '1');
                $emailStatus = $this->tableParser->getCaseInsensitive($log, ['email status', 'email_status']);
                $aiStatus = $this->tableParser->getCaseInsensitive($log, ['ai status', 'ai_status']);
                $reviewStatus = $this->tableParser->getCaseInsensitive($log, ['review status', 'review_status']);
                $reviewToken = $this->tableParser->getCaseInsensitive($log, ['review token', 'review_token']);
                $reviewedAt = $this->tableParser->getCaseInsensitive($log, ['reviewed at', 'reviewed_at']);
                $reviewedBy = $this->tableParser->getCaseInsensitive($log, ['reviewed by', 'reviewed_by']);
                if (empty($reviewedBy) || strtolower(trim($reviewedBy)) === 'unassigned') {
                    $reviewedBy = 'jaezelle.benito@pingconmarketing.com';
                }
                $tokenCreated = $this->tableParser->getCaseInsensitive($log, ['review token created at', 'review_token_created_at']);
                $tokenExpires = $this->tableParser->getCaseInsensitive($log, ['review expires at', 'review_expires_at']);
                $uploaderLoc = $this->tableParser->getCaseInsensitive($log, ['uploader location', 'uploader_location']);

                GoogleSheetLog::query()->updateOrCreate(
                    ['sheet_slug' => $slug, 'serial_number' => $sn],
                    [
                        'timestamp' => $timestamp,
                        'drive_folder_link' => $driveLink,
                        'file_count' => max(1, $fileCount),
                        'email_status' => $emailStatus,
                        'ai_status' => $aiStatus,
                        'review_status' => $reviewStatus,
                        'review_token' => $reviewToken,
                        'reviewed_at' => $reviewedAt,
                        'reviewedBy' => $reviewedBy,
                        'reviewed_by' => $reviewedBy,
                        'review_token_created_at' => $tokenCreated,
                        'review_expires_at' => $tokenExpires,
                        'uploader_location' => $uploaderLoc,
                    ]
                );
            }

            // 2. Stage Files
            foreach ($files as $f) {
                $sn = (int) ($f['serial_number'] ?? 0);
                $fname = trim((string) ($f['file_name'] ?? ''));
                $fid = trim((string) ($f['file_id'] ?? ''));

                if ($sn > 0 && ($fname !== '' || $fid !== '')) {
                    GoogleSheetFile::query()->updateOrCreate(
                        [
                            'sheet_slug' => $slug,
                            'serial_number' => $sn,
                            'file_name' => $fname ?: "file_{$fid}",
                        ],
                        [
                            'file_no' => $f['file_no'] ?? null,
                            'file_id' => $fid ?: null,
                            'file_url' => $f['file_url'] ?? null,
                            'mime_type' => $f['mime_type'] ?? 'image/jpeg',
                            'r2_url' => $f['r2_url'] ?? null,
                            'row_index' => $f['_rowIndex'] ?? null,
                        ]
                    );
                }
            }

            // 3. Stage Extractions
            foreach ($extractions as $e) {
                $sn = (int) ($e['serial_number'] ?? 0);
                if ($sn > 0) {
                    GoogleSheetExtraction::query()->updateOrCreate(
                        ['sheet_slug' => $slug, 'serial_number' => $sn],
                        [
                            'ai_status' => $e['ai_status'] ?? null,
                            'raw_ai_json' => $e['raw_ai_json'] ?? null,
                            'corrected_json' => $e['corrected_json'] ?? null,
                            'extracted_at' => $e['extracted_at'] ?? null,
                            'error_message' => $e['error_message'] ?? null,
                        ]
                    );
                }
            }
        });
    }

    /**
     * Synchronize a specific Serial Number data into Receiving Application Database.
     *
     * @return array{success: bool, upload_id: int|null, message: string, serial_number: int}
     */
    public function syncSerialNumber(string $slug, int $serialNumber): array
    {
        $log = GoogleSheetLog::query()
            ->where('sheet_slug', $slug)
            ->where('serial_number', $serialNumber)
            ->first();

        if (! $log) {
            // Auto-create minimal log record if files exist
            $filesCount = GoogleSheetFile::query()
                ->where('sheet_slug', $slug)
                ->where('serial_number', $serialNumber)
                ->count();

            if ($filesCount === 0) {
                throw new RuntimeException("Serial Number {$serialNumber} not found in staged data for {$slug}.");
            }

            $log = GoogleSheetLog::query()->create([
                'sheet_slug' => $slug,
                'serial_number' => $serialNumber,
                'file_count' => $filesCount,
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        $files = GoogleSheetFile::query()
            ->where('sheet_slug', $slug)
            ->where('serial_number', $serialNumber)
            ->get();

        $extraction = GoogleSheetExtraction::query()
            ->where('sheet_slug', $slug)
            ->where('serial_number', $serialNumber)
            ->first();

        // 1. Resolve UploadType
        /** @var UploadType $uploadType */
        $uploadType = UploadType::query()->where('slug', strtolower($slug))->first();
        if (! $uploadType) {
            $uploadType = UploadType::query()->firstOrCreate(
                ['slug' => strtolower($slug)],
                [
                    'name' => strtoupper($slug),
                    'r2_prefix' => strtolower($slug),
                    'is_active' => true,
                ]
            );
        }

        // 2. Resolve User
        $reviewerEmail = trim((string) $log->reviewed_by);
        if ($reviewerEmail === '' || strtolower($reviewerEmail) === 'unassigned') {
            $reviewerEmail = 'jaezelle.benito@pingconmarketing.com';
        }

        $activeUser = null;
        if (filter_var($reviewerEmail, FILTER_VALIDATE_EMAIL)) {
            $activeUser = User::query()->where('email', strtolower($reviewerEmail))->first();
            if (! $activeUser) {
                $activeUser = User::query()->create([
                    'email' => strtolower($reviewerEmail),
                    'name' => str_starts_with(strtolower($reviewerEmail), 'jaezelle') ? 'Jaezelle Benito' : 'Sheet Reviewer',
                    'password' => 'Password12345678!',
                    'status' => UserStatus::Active,
                ]);
                $activeUser->syncRoles(['uploader']);
            }
        }

        if (! $activeUser) {
            $activeUser = User::query()->firstOrCreate(
                ['email' => 'jaezelle.benito@pingconmarketing.com'],
                [
                    'name' => 'Jaezelle Benito',
                    'password' => 'Password12345678!',
                    'status' => UserStatus::Active,
                ]
            );
            if (! $activeUser->hasRole('uploader')) {
                $activeUser->syncRoles(['uploader']);
            }
        }

        // Parse Timestamps
        $createdAt = $this->parseDate($log->timestamp) ?? now();
        $reviewedAt = $this->parseDate($log->reviewed_at);

        // Parse Lat/Lng
        $lat = null;
        $lng = null;
        if ($log->uploader_location && str_contains($log->uploader_location, ',')) {
            $parts = explode(',', $log->uploader_location);
            if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
                $lat = (float) trim($parts[0]);
                $lng = (float) trim($parts[1]);
            }
        }

        // Status Enums
        $emailStatus = strtolower((string) $log->email_status) === 'sent' ? EmailStatus::Sent : EmailStatus::Pending;
        $aiStatus = match (strtolower((string) $log->ai_status)) {
            'extracted', 'completed' => AiStatus::Extracted,
            'processing' => AiStatus::Processing,
            'failed' => AiStatus::Failed,
            default => AiStatus::Pending,
        };
        $reviewStatus = match (strtolower((string) $log->review_status)) {
            'verified' => ReviewStatus::Verified,
            'rejected' => ReviewStatus::Revision,
            default => ReviewStatus::Pending,
        };

        $bucket = config('filesystems.disks.r2.bucket', 'receiving-production');

        return DB::transaction(function () use (
            $slug, $serialNumber, $log, $files, $extraction, $uploadType,
            $activeUser, $createdAt, $reviewedAt, $lat, $lng,
            $emailStatus, $aiStatus, $reviewStatus, $bucket
        ): array {
            // 3. Find existing ReceivingUpload or create new
            /** @var ReceivingUpload|null $upload */
            $upload = null;
            if ($log->synced_receiving_upload_id) {
                $upload = ReceivingUpload::query()->find($log->synced_receiving_upload_id);
            }

            if (! $upload) {
                $submissionId = (string) Str::uuid();
                $upload = ReceivingUpload::query()->create([
                    'submission_id' => $submissionId,
                    'upload_type_id' => $uploadType->getKey(),
                    'uploader_user_id' => $activeUser->getKey(),
                    'uploader_email' => $activeUser->email,
                    'file_count' => max(1, $files->count() ?: $log->file_count),
                    'processing_status' => UploadProcessingStatus::Completed,
                    'email_status' => $emailStatus,
                    'review_email_status' => $emailStatus,
                    'ai_status' => $aiStatus,
                    'review_status' => $reviewStatus,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'location_captured_at' => $lat !== null ? $createdAt : null,
                    'r2_bucket' => $bucket,
                    'r2_prefix' => $uploadType->slug,
                    'upload_completed_at' => $createdAt,
                    'notification_sent_at' => $emailStatus === EmailStatus::Sent ? $createdAt : null,
                    'review_notification_sent_at' => $emailStatus === EmailStatus::Sent ? $createdAt : null,
                    'created_at' => $createdAt,
                    'updated_at' => $reviewedAt ?? $createdAt,
                ]);
            } else {
                $upload->update([
                    'uploader_user_id' => $activeUser->getKey(),
                    'uploader_email' => $activeUser->email,
                    'file_count' => max(1, $files->count() ?: $log->file_count),
                    'processing_status' => UploadProcessingStatus::Completed,
                    'email_status' => $emailStatus,
                    'review_email_status' => $emailStatus,
                    'ai_status' => $aiStatus,
                    'review_status' => $reviewStatus,
                    'latitude' => $lat ?? $upload->latitude,
                    'longitude' => $lng ?? $upload->longitude,
                    'updated_at' => $reviewedAt ?? now(),
                ]);
            }

            // 4. Create ReviewLink if review_token present
            if ($log->review_token && trim($log->review_token) !== '') {
                $tokenHash = hash('sha256', trim($log->review_token));
                ReviewLink::query()->updateOrCreate(
                    ['token_hash' => $tokenHash],
                    [
                        'receiving_upload_id' => $upload->getKey(),
                        'upload_type_id' => $uploadType->getKey(),
                        'email' => $activeUser->email,
                        'expires_at' => $this->parseDate($log->review_expires_at) ?? $createdAt->copy()->addDays(7),
                        'used_at' => $reviewStatus === ReviewStatus::Verified ? ($reviewedAt ?? $createdAt) : null,
                        'created_at' => $this->parseDate($log->review_token_created_at) ?? $createdAt,
                        'updated_at' => $reviewedAt ?? $createdAt,
                    ]
                );
            }

            // 5. Parse Extractions JSON
            $extractedDocs = [];
            if ($extraction) {
                $jsonStr = ($extraction->corrected_json && $extraction->corrected_json !== 'null')
                    ? $extraction->corrected_json
                    : $extraction->raw_ai_json;

                if ($jsonStr && trim($jsonStr) !== '') {
                    $decoded = json_decode($jsonStr, true);
                    if (is_array($decoded) && isset($decoded['documents'])) {
                        $extractedDocs = $decoded['documents'];
                    }
                }
            }

            // 6. Sync Uploaded Files & AI Extractions
            $r2Prefix = $uploadType->r2_prefix ?: strtolower($uploadType->slug);

            foreach ($files as $fIdx => $fileItem) {
                $fname = $fileItem->file_name;
                $fid = $fileItem->file_id;
                $mime = $fileItem->mime_type ?: 'image/jpeg';
                $ext = pathinfo($fname, PATHINFO_EXTENSION) ?: 'jpg';
                $sanitizedName = Str::slug(pathinfo($fname, PATHINFO_FILENAME)).'.'.$ext;
                if ($sanitizedName === '.') {
                    $sanitizedName = "file_{$serialNumber}_{$fIdx}.{$ext}";
                }
                $storedFileName = $fid ? "{$fid}_{$sanitizedName}" : $sanitizedName;

                // Extract R2 Key from R2 URL or construct canonical path
                $targetR2Key = null;
                if ($fileItem->r2_url && str_contains($fileItem->r2_url, 'http')) {
                    $parsedPath = parse_url($fileItem->r2_url, PHP_URL_PATH);
                    if ($parsedPath) {
                        $cleanPath = rawurldecode(ltrim($parsedPath, '/'));
                        $bucketName = (string) config('filesystems.disks.r2.bucket');
                        if ($bucketName !== '' && str_starts_with($cleanPath, $bucketName.'/')) {
                            $cleanPath = substr($cleanPath, strlen($bucketName) + 1);
                        } elseif (preg_match('#^receiving-[a-z0-9_-]+/(.+)#i', $cleanPath, $m)) {
                            $cleanPath = $m[1];
                        }
                        $targetR2Key = $cleanPath;
                    }
                }

                if (! $targetR2Key) {
                    $targetR2Key = sprintf(
                        'receiving/%s/%s/%s/%s/SN-%d/%s',
                        $r2Prefix,
                        $createdAt->format('Y'),
                        $createdAt->format('m'),
                        $createdAt->format('d'),
                        $upload->getKey(),
                        $storedFileName
                    );
                }

                /** @var UploadedFile $uploadedFile */
                $uploadedFile = UploadedFile::query()->updateOrCreate(
                    [
                        'receiving_upload_id' => $upload->getKey(),
                        'stored_file_name' => $storedFileName,
                    ],
                    [
                        'original_file_name' => $fname ?: $sanitizedName,
                        'sanitized_file_name' => $sanitizedName,
                        'file_extension' => $ext,
                        'r2_bucket' => $bucket,
                        'r2_object_key' => $targetR2Key,
                        'r2_staging_object_key' => "staging/{$upload->submission_id}/{$sanitizedName}",
                        'original_file_size' => 1024,
                        'final_file_size' => 1024,
                        'declared_content_type' => $mime,
                        'content_type' => $mime,
                        'validation_status' => 'valid',
                        'virus_scan_status' => 'clean',
                        'compression_status' => 'skipped',
                        'ai_status' => $aiStatus->value,
                        'review_status' => $reviewStatus->value,
                        'uploaded_at' => $createdAt,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]
                );

                // Find matching document extraction for this file
                $matchingDoc = null;
                foreach ($extractedDocs as $doc) {
                    if (isset($doc['fileId']) && $doc['fileId'] === $fid) {
                        $matchingDoc = $doc;
                        break;
                    }
                    if (isset($doc['fileName']) && strtolower(trim($doc['fileName'])) === strtolower(trim($fname))) {
                        $matchingDoc = $doc;
                        break;
                    }
                }

                if (! $matchingDoc && count($extractedDocs) === 1 && $files->count() === 1) {
                    $matchingDoc = $extractedDocs[0];
                }

                if ($matchingDoc) {
                    $fields = $matchingDoc['fields'] ?? [];
                    $docTypeStr = $matchingDoc['documentType'] ?? $matchingDoc['document_type'] ?? $this->extractField($fields, ['document type', 'doc type'], 'invoice');
                    $invNo = $this->extractField($fields, ['invoice no', 'invoice number', 'sales invoice no', 'receipt number', 'dr no', 'invoice no.']);
                    $poNo = $this->extractField($fields, ['po number', 'po no', 'p.o. no.', 'customer po #', 'order no', 'so#']);
                    $poDateStr = $this->extractField($fields, ['po date', 'date', 'invoice date']);
                    $supplierName = $this->extractField($fields, ['supplier', 'supplier name', 'vendor', 'vendor name', 'company name']);
                    $totalAmtStr = $this->extractField($fields, ['total amount due', 'total sales', 'total amount', 'amount due', 'total']);

                    $poNoNormalized = $this->normalizer->normalizeIdentifier($poNo);

                    /** @var AiExtraction $aiExt */
                    $aiExt = AiExtraction::query()->updateOrCreate(
                        [
                            'receiving_upload_id' => $upload->getKey(),
                            'uploaded_file_id' => $uploadedFile->getKey(),
                        ],
                        [
                            'document_type' => strtolower($docTypeStr),
                            'invoice_number' => $invNo !== '' ? $invNo : null,
                            'po_number' => $poNo !== '' ? $poNo : null,
                            'po_number_normalized' => $poNoNormalized,
                            'po_date' => $poDateStr !== '' ? $poDateStr : null,
                            'raw_extracted_json' => $matchingDoc,
                            'corrected_json' => $matchingDoc,
                            'ai_status' => $aiStatus->value,
                            'review_status' => $reviewStatus->value,
                            'extracted_at' => $this->parseDate($extraction?->extracted_at) ?? $createdAt,
                            'reviewed_at' => $reviewedAt ?? $createdAt,
                            'reviewed_by_email' => $activeUser->email,
                            'created_at' => $createdAt,
                            'updated_at' => $reviewedAt ?? $createdAt,
                        ]
                    );

                    // If PO Document, create PoExtraction and PoExtractionItems
                    if (strtolower(trim($docTypeStr)) === 'purchase order') {
                        $parsedPoDateVal = $poDateStr !== '' ? $this->parseDate($poDateStr)?->toDateString() : null;

                        /** @var PoExtraction $poExt */
                        $poExt = PoExtraction::query()->updateOrCreate(
                            ['ai_extraction_id' => $aiExt->getKey()],
                            [
                                'receiving_upload_id' => $upload->getKey(),
                                'po_number' => $poNo !== '' ? $poNo : "PO-{$upload->getKey()}",
                                'po_number_normalized' => $poNoNormalized ?? "po{$upload->getKey()}",
                                'po_date' => $poDateStr !== '' ? $poDateStr : null,
                                'po_date_value' => $parsedPoDateVal,
                                'arrival_status' => PurchaseOrderArrivalStatus::Arrived->value,
                                'vendor_name' => $supplierName !== '' ? $supplierName : null,
                                'total_amount' => $totalAmtStr !== '' ? $totalAmtStr : null,
                                'created_at' => $createdAt,
                                'updated_at' => $createdAt,
                            ]
                        );

                        // Save line items for PO Extraction
                        $docItems = $matchingDoc['items'] ?? [];
                        if (! empty($docItems) && is_array($docItems)) {
                            PoExtractionItem::query()->where('po_extraction_id', $poExt->getKey())->delete();
                            foreach ($docItems as $idx => $it) {
                                PoExtractionItem::query()->create([
                                    'po_extraction_id' => $poExt->getKey(),
                                    'sort_order' => $idx + 1,
                                    'item_code' => $it['itemCode'] ?? $it['code'] ?? null,
                                    'product_description' => $it['description'] ?? $it['itemDescription'] ?? null,
                                    'package' => $it['package'] ?? null,
                                    'quantity' => isset($it['quantity']) ? (string) $it['quantity'] : '1',
                                    'unit' => $it['unit'] ?? $it['uom'] ?? 'unit',
                                    'unit_price' => isset($it['unitPrice']) ? (string) $it['unitPrice'] : null,
                                    'line_total' => isset($it['amount']) ? (string) $it['amount'] : null,
                                ]);
                            }
                        }

                        // Automatically match and synchronize against master PO item schedule
                        app(PurchaseOrderItemMatcher::class)->sync($poExt);
                        // Link any previously uploaded invoices that were waiting for this PO
                        app(PurchaseOrderLinker::class)->syncPoExtraction($poExt);
                    } else {
                        // For Invoice / Delivery Receipt / Receiving documents, link to real PO if already present
                        if ($poNoNormalized !== null) {
                            /** @var PoExtraction|null $existingRealPo */
                            $existingRealPo = PoExtraction::query()
                                ->where('po_number_normalized', $poNoNormalized)
                                ->first();

                            if ($existingRealPo) {
                                app(PurchaseOrderLinker::class)->link(
                                    $aiExt,
                                    $existingRealPo,
                                    $activeUser,
                                    PurchaseOrderLinkSource::Automatic
                                );
                            }
                        }
                    }

                    // For Verified Invoices/Receipts with Supplier, create Stock Lots
                    if ($reviewStatus === ReviewStatus::Verified && $supplierName !== '') {
                        $rawItems = $matchingDoc['items'] ?? [];
                        $itemsToProcess = [];

                        if (! empty($rawItems) && is_array($rawItems)) {
                            foreach ($rawItems as $idx => $it) {
                                $itemsToProcess[] = [
                                    'key_suffix' => (string) ($idx + 1),
                                    'desc' => $it['description'] ?? $it['itemDescription'] ?? "Received Item - {$supplierName}",
                                    'code' => $it['itemCode'] ?? $it['code'] ?? '',
                                    'barcode' => $it['barcode'] ?? $it['ean'] ?? '',
                                    'qty' => isset($it['quantity']) ? (string) $it['quantity'] : '1',
                                    'unit' => $it['unit'] ?? $it['uom'] ?? 'unit',
                                ];
                            }
                        } else {
                            $itemDesc = $this->extractField($fields, ['product / description', 'description', 'description 1', 'product', 'item description']);
                            if ($itemDesc === '') {
                                $itemDesc = "Received Item - {$supplierName}";
                            }
                            $itemCode = $this->extractField($fields, ['item code', 'sku', 'sku number', 'product code']);
                            $itemBarcode = $this->extractField($fields, ['item barcode', 'ean', 'barcode']);
                            $itemQtyStr = $this->extractField($fields, ['quantity', 'qty', 'total quantity', 'quantity 1'], '1');
                            $itemUnit = $this->extractField($fields, ['unit', 'uom', 'package', 'unit 1'], 'unit');

                            $itemsToProcess[] = [
                                'key_suffix' => '1',
                                'desc' => $itemDesc,
                                'code' => $itemCode,
                                'barcode' => $itemBarcode,
                                'qty' => $itemQtyStr,
                                'unit' => $itemUnit,
                            ];
                        }

                        foreach ($itemsToProcess as $pItem) {
                            $itemDesc = $pItem['desc'];
                            $itemCode = $pItem['code'];
                            $itemBarcode = $pItem['barcode'];
                            $itemQtyStr = $pItem['qty'];
                            $itemUnit = $pItem['unit'];
                            $suffix = $pItem['key_suffix'];

                            $normDesc = $this->normalizer->normalizeDescription($itemDesc);
                            $normSku = $this->normalizer->normalizeIdentifier($itemCode);
                            $normBarcode = $this->normalizer->normalizeIdentifier($itemBarcode);

                            /** @var PurchaseOrderItemSchedule|null $poScheduleItem */
                            $poScheduleItem = null;
                            if ($normSku) {
                                $poScheduleItem = PurchaseOrderItemSchedule::query()->where('sku_number_normalized', $normSku)->first();
                            }
                            if (! $poScheduleItem && $normBarcode) {
                                $poScheduleItem = PurchaseOrderItemSchedule::query()->where('ean_barcode_normalized', $normBarcode)->first();
                            }
                            if (! $poScheduleItem && $normDesc) {
                                $poScheduleItem = PurchaseOrderItemSchedule::query()->where('description_normalized', $normDesc)->first();
                            }

                            $canonicalDesc = $poScheduleItem ? $poScheduleItem->description : $itemDesc;
                            $canonicalSku = $poScheduleItem ? $poScheduleItem->sku_number : ($itemCode !== '' ? $itemCode : null);

                            $identityKey = hash('sha256', strtolower(($normDesc ?? $itemDesc).'_'.($supplierName)));
                            $whItem = WarehouseItem::query()->firstOrCreate(
                                ['identity_key' => $identityKey],
                                [
                                    'sku_number' => $canonicalSku,
                                    'sku_number_normalized' => $normSku,
                                    'description' => $canonicalDesc,
                                    'description_normalized' => $normDesc ?? strtolower($canonicalDesc),
                                    'base_unit' => $itemUnit,
                                ]
                            );

                            $parsedQty = (float) preg_replace('/[^\d.]/', '', $itemQtyStr);
                            $finalQty = $parsedQty > 0 ? $parsedQty : 1.000;

                            WarehouseStockLot::query()->updateOrCreate(
                                ['source_key' => "GSHEET-STOCK-{$slug}-{$serialNumber}-{$uploadedFile->getKey()}-{$suffix}"],
                                [
                                    'warehouse_item_id' => $whItem->getKey(),
                                    'source_type' => WarehouseStockSource::Arrival->value,
                                    'ai_extraction_id' => $aiExt->getKey(),
                                    'receiving_upload_id' => $upload->getKey(),
                                    'po_number' => $poNo !== '' ? $poNo : null,
                                    'quantity_received' => $finalQty,
                                    'received_at' => $createdAt,
                                    'received_date_quality' => WarehouseDateQuality::Confirmed->value,
                                    'confirmed_by_user_id' => $activeUser->getKey(),
                                    'confirmed_at' => $reviewedAt ?? $createdAt,
                                    'created_at' => $createdAt,
                                    'updated_at' => $createdAt,
                                ]
                            );
                        }
                    }
                }
            }

            // 7. Update Staging Record
            $log->update([
                'is_synced_to_db' => true,
                'synced_receiving_upload_id' => $upload->getKey(),
                'synced_at' => now(),
                'error_message' => null,
            ]);

            $this->updateSheetCounts($slug);

            $this->activityLogger->record(
                'admin',
                'google_sheet_serial_sync',
                'success',
                "Synchronized {$uploadType->name} Serial Number {$serialNumber} into Database (Upload #{$upload->getKey()}).",
                $activeUser
            );

            return [
                'success' => true,
                'upload_id' => (int) $upload->getKey(),
                'serial_number' => $serialNumber,
                'message' => "Successfully synced SN-{$serialNumber} ({$uploadType->name}) into Database.",
            ];
        });
    }

    /**
     * Calculate live preview of matching items for batch sync.
     *
     * @return array{matchedCount: int, totalPendingCount: int, excludedCount: int, sampleSerials: array<int>}
     */
    public function calculateBatchPreview(
        string $slug,
        ?int $limit = null,
        ?string $includeSerials = null,
        ?string $excludeSerials = null,
        string $sortOrder = 'ASC'
    ): array {
        $query = GoogleSheetLog::query()
            ->where('sheet_slug', $slug)
            ->where('is_synced_to_db', false);

        $totalPendingCount = (clone $query)->count();

        // Apply Included Serials filter
        if ($includeSerials && trim($includeSerials) !== '') {
            $includedList = $this->parseSerialRanges($includeSerials);
            if (! empty($includedList)) {
                $query->whereIn('serial_number', $includedList);
            }
        }

        // Apply Excluded Serials filter
        $excludedCount = 0;
        if ($excludeSerials && trim($excludeSerials) !== '') {
            $excludedList = $this->parseSerialRanges($excludeSerials);
            if (! empty($excludedList)) {
                $excludedCount = count($excludedList);
                $query->whereNotIn('serial_number', $excludedList);
            }
        }

        $query->orderBy('serial_number', strtoupper($sortOrder) === 'DESC' ? 'desc' : 'asc');

        if ($limit && $limit > 0) {
            $query->limit($limit);
        }

        $serials = $query->pluck('serial_number')->all();

        return [
            'matchedCount' => count($serials),
            'totalPendingCount' => $totalPendingCount,
            'excludedCount' => $excludedCount,
            'sampleSerials' => array_slice($serials, 0, 10),
        ];
    }

    /**
     * Execute batch sync with real-time progress logging in GoogleSheetSyncJob.
     *
     * @return array{batch_id: string, total: int, processed: int, successful: int, failed: int}
     */
    public function runBatchSync(
        string $slug,
        string $batchId,
        ?int $limit = null,
        ?string $includeSerials = null,
        ?string $excludeSerials = null,
        string $sortOrder = 'ASC'
    ): array {
        $query = GoogleSheetLog::query()
            ->where('sheet_slug', $slug)
            ->where('is_synced_to_db', false);

        if ($includeSerials && trim($includeSerials) !== '') {
            $includedList = $this->parseSerialRanges($includeSerials);
            if (! empty($includedList)) {
                $query->whereIn('serial_number', $includedList);
            }
        }

        if ($excludeSerials && trim($excludeSerials) !== '') {
            $excludedList = $this->parseSerialRanges($excludeSerials);
            if (! empty($excludedList)) {
                $query->whereNotIn('serial_number', $excludedList);
            }
        }

        $query->orderBy('serial_number', strtoupper($sortOrder) === 'DESC' ? 'desc' : 'asc');

        if ($limit && $limit > 0) {
            $query->limit($limit);
        }

        $serials = $query->pluck('serial_number')->all();
        $total = count($serials);

        /** @var GoogleSheetSyncJob $job */
        $job = GoogleSheetSyncJob::query()->create([
            'sheet_slug' => $slug,
            'batch_id' => $batchId,
            'status' => 'running',
            'total_items' => $total,
            'processed_items' => 0,
            'successful_items' => 0,
            'failed_items' => 0,
            'started_at' => now(),
            'current_status_text' => "Starting sync for {$total} serial numbers...",
            'logs' => [],
        ]);

        $successful = 0;
        $failed = 0;
        $logs = [];

        foreach ($serials as $i => $sn) {
            // Check for cancellation
            $job->refresh();
            if ($job->status === 'cancelled') {
                $job->update([
                    'completed_at' => now(),
                    'current_status_text' => 'Batch sync cancelled by user.',
                ]);
                break;
            }

            $job->update([
                'processed_items' => $i + 1,
                'current_serial' => $sn,
                'current_status_text' => "Syncing SN-{$sn} (".($i + 1)." of {$total})...",
            ]);

            try {
                $result = $this->syncSerialNumber($slug, $sn);
                $successful++;
                $logs[] = [
                    'id' => $i + 1,
                    'serial_number' => $sn,
                    'status' => 'success',
                    'message' => "SN-{$sn} synced (Upload #{$result['upload_id']})",
                    'timestamp' => now()->toIso8601String(),
                ];
            } catch (\Throwable $e) {
                $failed++;
                Log::error("Failed to sync SN-{$sn} for {$slug}: {$e->getMessage()}");
                $logs[] = [
                    'id' => $i + 1,
                    'serial_number' => $sn,
                    'status' => 'failed',
                    'message' => "SN-{$sn} error: {$e->getMessage()}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            // Keep latest 100 log entries
            $job->update([
                'successful_items' => $successful,
                'failed_items' => $failed,
                'logs' => array_slice($logs, -100),
            ]);
        }

        $job->update([
            'status' => $job->status === 'cancelled' ? 'cancelled' : ($failed > 0 && $successful === 0 ? 'failed' : 'completed'),
            'completed_at' => now(),
            'current_status_text' => "Completed batch sync: {$successful} successful, {$failed} failed.",
            'logs' => array_slice($logs, -200),
        ]);

        $this->updateSheetCounts($slug);

        return [
            'batch_id' => $batchId,
            'total' => $total,
            'processed' => $successful + $failed,
            'successful' => $successful,
            'failed' => $failed,
        ];
    }

    /**
     * Recalculate and persist sheet counters.
     */
    public function updateSheetCounts(string $slug): void
    {
        $total = GoogleSheetLog::query()->where('sheet_slug', $slug)->count();
        $synced = GoogleSheetLog::query()->where('sheet_slug', $slug)->where('is_synced_to_db', true)->count();
        $failed = GoogleSheetLog::query()->where('sheet_slug', $slug)->whereNotNull('error_message')->count();
        $pending = max(0, $total - $synced);

        GoogleSheetConfig::query()->where('slug', $slug)->update([
            'total_serials' => $total,
            'synced_serials' => $synced,
            'pending_serials' => $pending,
            'failed_serials' => $failed,
        ]);
    }

    /**
     * Get aggregate overview statistics.
     *
     * @return array<string, mixed>
     */
    public function getOverviewStats(): array
    {
        $total = GoogleSheetLog::query()->count();
        $synced = GoogleSheetLog::query()->where('is_synced_to_db', true)->count();
        $pending = GoogleSheetLog::query()->where('is_synced_to_db', false)->count();
        $files = GoogleSheetFile::query()->count();
        $filesPendingR2 = GoogleSheetFile::query()->where(function ($q) {
            $q->whereNull('r2_url')->orWhere('r2_url', '');
        })->count();
        $filesSyncedR2 = GoogleSheetFile::query()->whereNotNull('r2_url')->where('r2_url', '!=', '')->count();
        $extractions = GoogleSheetExtraction::query()->count();
        $percentage = $total > 0 ? round(($synced / $total) * 100, 1) : 0;

        return [
            'total_serials' => $total,
            'synced_serials' => $synced,
            'pending_serials' => $pending,
            'total_files' => $files,
            'files_pending_r2' => $filesPendingR2,
            'files_synced_r2' => $filesSyncedR2,
            'total_extractions' => $extractions,
            'completion_percentage' => $percentage,
        ];
    }

    /**
     * Parse range strings like "1-50, 100, 105-110" into distinct sorted integer list.
     *
     * @return array<int>
     */
    public function parseSerialRanges(string $rangeStr): array
    {
        $result = [];
        $parts = explode(',', $rangeStr);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '-')) {
                [$start, $end] = explode('-', $part, 2);
                $s = (int) preg_replace('/[^\d]/', '', $start);
                $e = (int) preg_replace('/[^\d]/', '', $end);
                if ($s > 0 && $e >= $s) {
                    for ($n = $s; $n <= $e; $n++) {
                        $result[$n] = $n;
                    }
                }
            } else {
                $val = (int) preg_replace('/[^\d]/', '', $part);
                if ($val > 0) {
                    $result[$val] = $val;
                }
            }
        }

        return array_values($result);
    }

    private function extractField(array $fields, array $targetLabels, string $default = ''): string
    {
        foreach ($fields as $f) {
            $lbl = strtolower(trim((string) ($f['label'] ?? '')));
            foreach ($targetLabels as $target) {
                if ($lbl === strtolower($target)) {
                    return trim((string) ($f['value'] ?? ''));
                }
            }
        }

        return $default;
    }

    private function parseDate(?string $dateStr): ?Carbon
    {
        if (! $dateStr || trim($dateStr) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($dateStr));
        } catch (\Throwable) {
            return null;
        }
    }
}
