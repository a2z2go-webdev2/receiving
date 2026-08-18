<?php

namespace App\Features\Receiving\Jobs;

use App\Enums\AiStatus;
use App\Enums\UploadWorkflow;
use App\Features\Receiving\Contracts\DocumentExtractor;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\PoExtractionStore;
use App\Features\Receiving\Services\PurchaseOrderDataIntegrator;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Models\UploadedFile;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExtractReceivingBatch implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** @param array<int, int> $fileIds */
    public function __construct(public readonly array $fileIds)
    {
        $this->tries = max(1, (int) config('receiving.ai.retry_limit', 3));
    }

    public function middleware(): array
    {
        $key = implode('-', $this->fileIds);

        return [(new WithoutOverlapping("ai-extract-batch-{$key}"))->expireAfter(300)->releaseAfter(5)];
    }

    public function backoff(): array
    {
        $seconds = max(1, (int) config('receiving.ai.retry_backoff_seconds', 60));

        return [$seconds, $seconds * 3, $seconds * 10];
    }

    public function handle(
        DocumentExtractor $extractor,
        ActivityLogger $activity,
        PurchaseOrderDataIntegrator $purchaseOrderData,
        PoExtractionStore $poExtractionStore,
        PurchaseOrderLinker $purchaseOrderLinks,
    ): void {
        $failed = false;

        foreach (UploadedFile::query()->with(['upload.uploadType', 'extraction'])->whereKey($this->fileIds)->get() as $file) {
            if ($file->ai_status === AiStatus::Extracted) {
                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'receiving-ai-');
            if ($path === false) {
                throw new RuntimeException('Unable to allocate temporary extraction storage.');
            }

            try {
                $file->forceFill(['ai_status' => AiStatus::Processing])->save();
                $file->extraction?->forceFill(['ai_status' => AiStatus::Processing])->save();
                $source = Storage::disk((string) config('receiving.disk'))->readStream((string) $file->r2_object_key);
                $destination = fopen($path, 'wb');
                if (! is_resource($source) || ! is_resource($destination)) {
                    throw new RuntimeException('Unable to retrieve the accepted file for AI extraction.');
                }
                stream_copy_to_stream($source, $destination);
                fclose($source);
                fclose($destination);

                $data = $extractor->extract(
                    $path,
                    (string) $file->content_type,
                    $file->upload->uploadType->workflow,
                    $file->upload->created_at,
                );
                if ($file->upload->uploadType->workflow === UploadWorkflow::Standard) {
                    $data = $purchaseOrderData->fillMissingPoDate($data);
                }

                $documentType = (string) ($data['document_type'] ?? 'other');
                $aiExtraction = $file->extraction;
                if ($aiExtraction) {
                    $aiExtraction->forceFill([
                        'document_type' => $documentType,
                        'raw_extracted_json' => $data,
                        'corrected_json' => null,
                        'ai_status' => AiStatus::Extracted,
                        'failure_reason' => null,
                        'extracted_at' => now(),
                    ])->save();

                    if ($file->upload->uploadType->workflow === UploadWorkflow::PurchaseOrder) {
                        $poExtractionStore->store($aiExtraction, $data);
                    } elseif ($file->upload->uploadType->workflow === UploadWorkflow::Standard) {
                        $purchaseOrderLinks->syncExtraction($aiExtraction);
                    }
                }
                $file->forceFill(['ai_status' => AiStatus::Extracted, 'failure_reason' => null])->save();
                $activity->record('ai', 'ai_extraction_completed', 'success', "AI processing completed for {$file->sanitized_file_name}.", null, $file->upload);
            } catch (Throwable $error) {
                $failed = true;
                $file->forceFill(['ai_status' => AiStatus::Failed, 'failure_reason' => $error->getMessage()])->save();
                $file->extraction?->forceFill(['ai_status' => AiStatus::Failed, 'failure_reason' => $error->getMessage()])->save();
                $activity->record('ai', 'ai_extraction_failed', 'error', "AI processing failed for {$file->sanitized_file_name}.", null, $file->upload, null, $error);
            } finally {
                @unlink($path);
            }
        }

        if ($failed) {
            throw new RuntimeException('One or more file extractions failed; successful results were retained.');
        }
    }
}
