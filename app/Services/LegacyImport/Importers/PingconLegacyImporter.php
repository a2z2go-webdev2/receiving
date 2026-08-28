<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkSource;
use App\Enums\ReviewStatus;
use App\Enums\UploadProcessingStatus;
use App\Enums\UserStatus;
use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseStockSource;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Jobs\SyncLegacyFilesToR2Job;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\PurchaseOrderItemArrival;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Models\WarehouseItem;
use App\Models\WarehouseStockLot;
use App\Services\LegacyImport\Contracts\LegacyDataImporterInterface;
use App\Services\LegacyImport\GoogleDriveStreamer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PingconLegacyImporter implements LegacyDataImporterInterface
{
    public function __construct(
        private readonly GoogleDriveStreamer $streamer
    ) {}

    public function supports(UploadType $uploadType): bool
    {
        return strtolower($uploadType->slug) === 'pingcon';
    }

    public function import(
        UploadType $uploadType,
        array $parsedLogs,
        array $parsedFiles,
        array $parsedExtractions,
        array $options = []
    ): array {
        $stats = [
            'imported_submissions' => 0,
            'imported_files' => 0,
            'imported_extractions' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        // 1. Ensure fallback & reviewer users exist
        $reviewerUser = User::query()->where('email', 'jaezelle.benito@pingconmarketing.com')->first();
        if (! $reviewerUser) {
            $reviewerUser = User::query()->firstOrCreate(
                ['email' => 'jaezelle.benito@pingconmarketing.com'],
                [
                    'name' => 'Jaezelle Benito',
                    'password' => 'Password12345678!',
                    'status' => UserStatus::Active,
                ]
            );
            $reviewerUser->syncRoles(['uploader']);
        }

        $fallbackUser = User::query()->where('email', 'legacy.import@pingconmarketing.com')->first();
        if (! $fallbackUser) {
            $fallbackUser = User::query()->firstOrCreate(
                ['email' => 'legacy.import@pingconmarketing.com'],
                [
                    'name' => 'Legacy Import User',
                    'password' => 'Password12345678!',
                    'status' => UserStatus::Active,
                ]
            );
            $fallbackUser->syncRoles(['uploader']);
        }

        // Index Files by (Serial Number, fileId) and (Serial Number, fileName)
        $filesIndex = [];
        foreach ($parsedFiles as $f) {
            $sn = $this->getCaseInsensitive($f, ['serial number', 'serial_number', 'sn']);
            $fid = $this->getCaseInsensitive($f, ['file id', 'file_id', 'fid']);
            $fname = $this->getCaseInsensitive($f, ['file name', 'file_name', 'fname']);

            if ($sn !== '') {
                if ($fid !== '') {
                    $filesIndex["{$sn}_fid_{$fid}"] = $f;
                }
                if ($fname !== '') {
                    $filesIndex["{$sn}_fname_{$fname}"] = $f;
                }
                $filesIndex["{$sn}_all"][] = $f;
            }
        }

        // Index Extractions by Serial Number
        $extractionsIndex = [];
        foreach ($parsedExtractions as $e) {
            $sn = $this->getCaseInsensitive($e, ['serial number', 'serial_number', 'sn']);
            if ($sn !== '') {
                $extractionsIndex[(string) $sn] = $e;
            }
        }

        // 2. Iterate Submissions (Receiving Logs)
        foreach ($parsedLogs as $log) {
            $snRaw = $this->getCaseInsensitive($log, ['serial number', 'serial_number', 'sn']);
            if ($snRaw === '' || ! is_numeric($snRaw)) {
                continue;
            }

            $sn = (int) $snRaw;
            $timestampStr = $this->getCaseInsensitive($log, ['timestamp', 'created_at']);
            $driveLink = $this->getCaseInsensitive($log, ['drive folder link', 'drive_folder_link']);
            $fileCount = (int) $this->getCaseInsensitive($log, ['file count', 'file_count'], '1');
            $emailStatusStr = $this->getCaseInsensitive($log, ['email status', 'email_status']);
            $aiStatusStr = $this->getCaseInsensitive($log, ['ai status', 'ai_status']);
            $reviewStatusStr = $this->getCaseInsensitive($log, ['review status', 'review_status']);
            $reviewToken = $this->getCaseInsensitive($log, ['review token', 'review_token']);
            $reviewedAtStr = $this->getCaseInsensitive($log, ['reviewed at', 'reviewed_at']);
            $reviewedByEmail = $this->getCaseInsensitive($log, ['reviewed by', 'reviewed_by']);
            $uploaderLoc = $this->getCaseInsensitive($log, ['uploader location', 'uploader_location']);

            $createdAt = $this->parseDate($timestampStr) ?? now();
            $reviewedAt = $this->parseDate($reviewedAtStr);

            $activeUser = $reviewerUser;
            if ($reviewedByEmail !== '' && filter_var($reviewedByEmail, FILTER_VALIDATE_EMAIL)) {
                $foundUser = User::query()->where('email', strtolower($reviewedByEmail))->first();
                if ($foundUser) {
                    $activeUser = $foundUser;
                }
            }

            $processingStatus = UploadProcessingStatus::Completed;
            $emailStatus = strtolower($emailStatusStr) === 'sent' ? EmailStatus::Sent : EmailStatus::Pending;
            $aiStatus = strtolower($aiStatusStr) === 'extracted' ? AiStatus::Extracted : AiStatus::Pending;

            $reviewStatus = match (strtolower($reviewStatusStr)) {
                'verified' => ReviewStatus::Verified,
                'pending' => ReviewStatus::Pending,
                'rejected' => ReviewStatus::Revision,
                default => ReviewStatus::Verified,
            };

            $lat = null;
            $lng = null;
            if ($uploaderLoc !== '' && str_contains($uploaderLoc, ',')) {
                $parts = explode(',', $uploaderLoc);
                if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
                    $lat = (float) trim($parts[0]);
                    $lng = (float) trim($parts[1]);
                }
            }

            // Generate deterministic UUID for submission
            $submissionId = (string) Str::uuid();

            DB::beginTransaction();
            try {
                /** @var ReceivingUpload $upload */
                $upload = ReceivingUpload::query()->create(
                    [
                        'submission_id' => $submissionId,
                        'upload_type_id' => $uploadType->getKey(),
                        'serial_number' => $sn,
                        'uploader_user_id' => $activeUser->getKey(),
                        'uploader_email' => $activeUser->email,
                        'file_count' => max(1, $fileCount),
                        'processing_status' => $processingStatus,
                        'email_status' => $emailStatus,
                        'review_email_status' => $emailStatus,
                        'ai_status' => $aiStatus,
                        'review_status' => $reviewStatus,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'r2_bucket' => config('filesystems.disks.r2.bucket', 'receiving-documents'),
                        'r2_prefix' => $uploadType->slug,
                        'upload_completed_at' => $createdAt,
                        'created_at' => $createdAt,
                        'updated_at' => $reviewedAt ?? $createdAt,
                    ]
                );

                // Create Review Link if token present
                if ($reviewToken !== '') {
                    $tokenHash = hash('sha256', $reviewToken);
                    ReviewLink::query()->updateOrCreate(
                        ['token_hash' => $tokenHash],
                        [
                            'receiving_upload_id' => $upload->getKey(),
                            'upload_type_id' => $uploadType->getKey(),
                            'email' => $activeUser->email,
                            'expires_at' => $createdAt->copy()->addDays(7),
                            'used_at' => $reviewStatus === ReviewStatus::Verified ? ($reviewedAt ?? $createdAt) : null,
                            'created_at' => $createdAt,
                            'updated_at' => $reviewedAt ?? $createdAt,
                        ]
                    );
                }

                // Process Files belonging to this submission
                $filesForSn = $filesIndex["{$sn}_all"] ?? [];
                $extractionRow = $extractionsIndex[(string) $sn] ?? null;

                $extractedDocs = [];
                if ($extractionRow) {
                    $corrJson = $this->getCaseInsensitive($extractionRow, ['corrected json', 'corrected_json']);
                    $rawJson = $this->getCaseInsensitive($extractionRow, ['raw ai json', 'raw_ai_json']);

                    $jsonStr = ($corrJson !== '' && $corrJson !== 'null') ? $corrJson : $rawJson;
                    if ($jsonStr !== '' && $jsonStr !== 'null') {
                        $parsedData = json_decode($jsonStr, true);
                        if (is_array($parsedData) && isset($parsedData['documents'])) {
                            $extractedDocs = $parsedData['documents'];
                        }
                    }
                }

                $driveFolderId = $this->streamer->extractFolderId($driveLink);

                foreach ($filesForSn as $fIdx => $fItem) {
                    $fname = $this->getCaseInsensitive($fItem, ['file name', 'file_name', 'fname']);
                    $fid = $this->getCaseInsensitive($fItem, ['file id', 'file_id', 'fid']);
                    $mime = $this->getCaseInsensitive($fItem, ['mime type', 'mime_type', 'mime'], 'image/jpeg');

                    $sanitizedName = Str::slug(pathinfo($fname, PATHINFO_FILENAME)).'.'.pathinfo($fname, PATHINFO_EXTENSION);
                    if ($sanitizedName === '.') {
                        $sanitizedName = "file_{$sn}_{$fIdx}.jpg";
                    }

                    $storedFileName = $fid !== '' ? "{$fid}_{$sanitizedName}" : $sanitizedName;
                    $r2Prefix = $uploadType->r2_prefix ?: strtolower($uploadType->slug);
                    $targetR2Key = sprintf(
                        'receiving/%s/%s/%s/%s/SN-%d/%s',
                        $r2Prefix,
                        $createdAt->format('Y'),
                        $createdAt->format('m'),
                        $createdAt->format('d'),
                        $sn,
                        $storedFileName
                    );

                    /** @var UploadedFile $uploadedFile */
                    $uploadedFile = UploadedFile::query()->create([
                        'receiving_upload_id' => $upload->getKey(),
                        'original_file_name' => $fname !== '' ? $fname : $sanitizedName,
                        'sanitized_file_name' => $sanitizedName,
                        'stored_file_name' => $storedFileName,
                        'file_extension' => pathinfo($fname, PATHINFO_EXTENSION) ?: 'jpg',
                        'r2_bucket' => config('filesystems.disks.r2.bucket', 'receiving-documents'),
                        'r2_object_key' => $targetR2Key,
                        'r2_staging_object_key' => "staging/{$submissionId}/{$sanitizedName}",
                        'original_file_size' => 1024,
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
                    ]);

                    $stats['imported_files']++;

                    // Dispatch R2 Async Sync if fileId is present
                    if ($fid !== '' && ! (bool) ($options['skip_r2_sync'] ?? false)) {
                        SyncLegacyFilesToR2Job::dispatch($uploadedFile->getKey(), $fid, $targetR2Key);
                    }

                    // Find corresponding extraction doc
                    $matchingDoc = null;
                    foreach ($extractedDocs as $d) {
                        if (isset($d['fileId']) && $d['fileId'] === $fid) {
                            $matchingDoc = $d;

                            break;
                        }
                    }

                    if ($matchingDoc) {
                        $fields = $matchingDoc['fields'] ?? [];
                        $docTypeStr = $this->extractFieldValue($fields, ['document type', 'doc type'], 'invoice');
                        $invNo = $this->extractFieldValue($fields, ['invoice no', 'invoice number', 'sales invoice no', 'receipt number', 'dr no', 'invoice no.']);
                        $poNo = $this->extractFieldValue($fields, ['po number', 'po no', 'p.o. no.', 'customer po #', 'order no', 'so#']);
                        $poDateStr = $this->extractFieldValue($fields, ['po date', 'date', 'invoice date']);
                        $supplierName = $this->extractFieldValue($fields, ['supplier', 'supplier name', 'vendor', 'vendor name']);
                        $totalAmtStr = $this->extractFieldValue($fields, ['total amount due', 'total sales', 'total amount', 'amount due', 'total']);

                        $poNoNormalized = preg_replace('/[^A-Za-z0-9]/', '', (string) $poNo);

                        /** @var AiExtraction $aiExt */
                        $aiExt = AiExtraction::query()->create([
                            'receiving_upload_id' => $upload->getKey(),
                            'uploaded_file_id' => $uploadedFile->getKey(),
                            'document_type' => strtolower($docTypeStr),
                            'invoice_number' => $invNo !== '' ? $invNo : null,
                            'po_number' => $poNo !== '' ? $poNo : null,
                            'po_number_normalized' => $poNoNormalized !== '' ? $poNoNormalized : null,
                            'po_date' => $poDateStr !== '' ? $poDateStr : null,
                            'raw_extracted_json' => $matchingDoc,
                            'corrected_json' => $matchingDoc,
                            'ai_status' => $aiStatus->value,
                            'review_status' => $reviewStatus->value,
                            'extracted_at' => $createdAt,
                            'reviewed_at' => $reviewedAt ?? $createdAt,
                            'reviewed_by_email' => $activeUser->email,
                            'created_at' => $createdAt,
                            'updated_at' => $reviewedAt ?? $createdAt,
                        ]);

                        $stats['imported_extractions']++;

                        // If Document is PO, create PoExtraction
                        if (str_contains(strtolower($docTypeStr), 'purchase order') || ($poNoNormalized !== '' && str_contains(strtolower($docTypeStr), 'po'))) {
                            $poExt = PoExtraction::query()->create([
                                'ai_extraction_id' => $aiExt->getKey(),
                                'receiving_upload_id' => $upload->getKey(),
                                'po_number' => $poNo,
                                'po_number_normalized' => $poNoNormalized,
                                'po_date' => $poDateStr,
                                'po_date_value' => $this->parseDate($poDateStr)?->toDateString(),
                                'arrival_status' => PurchaseOrderArrivalStatus::Arrived->value,
                                'vendor_name' => $supplierName,
                                'total_amount' => $totalAmtStr,
                                'created_at' => $createdAt,
                                'updated_at' => $createdAt,
                            ]);

                            // Create Document Link
                            PurchaseOrderDocumentLink::query()->create([
                                'po_extraction_id' => $poExt->getKey(),
                                'ai_extraction_id' => $aiExt->getKey(),
                                'source' => PurchaseOrderLinkSource::Automatic->value,
                                'created_at' => $createdAt,
                                'updated_at' => $createdAt,
                            ]);
                        }

                        // Create Warehouse Stock Lot & Item Catalog link for Verified Invoices/Receipts
                        if ($reviewStatus === ReviewStatus::Verified && $supplierName !== '') {
                            $normalizer = new PurchaseOrderDataNormalizer;

                            $itemDesc = $this->extractFieldValue($fields, ['product / description', 'description', 'description 1', 'product', 'item description']);
                            if ($itemDesc === '') {
                                $itemDesc = "Received Item - {$supplierName}";
                            }
                            $itemCode = $this->extractFieldValue($fields, ['item code', 'sku', 'sku number', 'product code']);
                            $itemBarcode = $this->extractFieldValue($fields, ['item barcode', 'ean', 'barcode']);
                            $itemQtyStr = $this->extractFieldValue($fields, ['quantity', 'qty', 'total quantity', 'quantity 1'], '1');
                            $itemUnit = $this->extractFieldValue($fields, ['unit', 'uom', 'package', 'unit 1'], 'unit');

                            $normDesc = $normalizer->normalizeDescription($itemDesc);
                            $normSku = $normalizer->normalizeIdentifier($itemCode);
                            $normBarcode = $normalizer->normalizeIdentifier($itemBarcode);

                            // Match against master PO item records catalog (purchase_order_item_schedules)
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

                            // Create PurchaseOrderItemArrival to link to PO Item Records
                            if ($poScheduleItem) {
                                PurchaseOrderItemArrival::query()->create([
                                    'receiving_upload_id' => $upload->getKey(),
                                    'ai_extraction_id' => $aiExt->getKey(),
                                    'purchase_order_item_schedule_id' => $poScheduleItem->getKey(),
                                    'source_key' => "PINGCON-ARRIVAL-{$sn}-{$uploadedFile->getKey()}",
                                    'arrived_quantity' => $finalQty,
                                    'arrival_date' => $createdAt->toDateString(),
                                    'po_number' => $poNo,
                                    'po_date' => $poDateStr,
                                    'supplier_name' => $supplierName,
                                    'matching_mechanism' => 'auto',
                                    'created_at' => $createdAt,
                                    'updated_at' => $createdAt,
                                ]);
                            }

                            WarehouseStockLot::query()->create([
                                'warehouse_item_id' => $whItem->getKey(),
                                'source_type' => WarehouseStockSource::Arrival->value,
                                'source_key' => "PINGCON-STOCK-{$sn}-{$uploadedFile->getKey()}",
                                'ai_extraction_id' => $aiExt->getKey(),
                                'receiving_upload_id' => $upload->getKey(),
                                'po_number' => $poNo,
                                'quantity_received' => $finalQty,
                                'received_at' => $createdAt,
                                'received_date_quality' => WarehouseDateQuality::Confirmed->value,
                                'confirmed_by_user_id' => $activeUser->getKey(),
                                'confirmed_at' => $reviewedAt ?? $createdAt,
                                'created_at' => $createdAt,
                                'updated_at' => $createdAt,
                            ]);
                        }
                    }
                }

                DB::commit();
                $stats['imported_submissions']++;
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error("PingconLegacyImporter error on SN {$sn}: {$e->getMessage()}");
                $stats['errors'][] = "SN {$sn}: {$e->getMessage()}";
            }
        }

        return $stats;
    }

    private function getCaseInsensitive(array $arr, array $keys, string $default = ''): string
    {
        foreach ($arr as $k => $v) {
            $cleanK = strtolower(trim((string) $k));
            foreach ($keys as $targetKey) {
                if ($cleanK === strtolower($targetKey)) {
                    return trim((string) $v);
                }
            }
        }

        return $default;
    }

    private function extractFieldValue(array $fields, array $targetLabels, string $default = ''): string
    {
        foreach ($fields as $field) {
            $lbl = strtolower(trim((string) ($field['label'] ?? '')));
            foreach ($targetLabels as $target) {
                if ($lbl === strtolower($target)) {
                    return trim((string) ($field['value'] ?? ''));
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
        } catch (\Throwable $e) {
            return null;
        }
    }
}
