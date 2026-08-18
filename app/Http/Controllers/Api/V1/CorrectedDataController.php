<?php

namespace App\Http\Controllers\Api\V1;

use App\Features\Receiving\Services\CorrectedDataMetadata;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaginatedCorrectedDataRequest;
use App\Http\Requests\Api\PoNumberCorrectedDataRequest;
use App\Http\Requests\Api\SerialCorrectedDataRequest;
use App\Models\AiExtraction;
use App\Models\UploadedFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorrectedDataController extends Controller
{
    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    public function bySerial(SerialCorrectedDataRequest $request): JsonResponse
    {
        $serialNumber = $request->serialNumber();

        return $this->respond(
            $this->baseQuery()->where('receiving_upload_id', $serialNumber),
            $request,
            ['type' => 'serial_number', 'value' => $serialNumber],
            fn (AiExtraction $extraction): bool => $this->isInvoiceOrReceipt($extraction),
        );
    }

    public function byPoNumber(PoNumberCorrectedDataRequest $request): JsonResponse
    {
        $poNumber = $request->string('po_number')->toString();
        $normalizedPoNumber = CorrectedDataMetadata::normalizedIdentifier($poNumber);
        $query = $this->baseQuery();

        $normalizedPoNumber === null
            ? $query->whereRaw('1 = 0')
            : $query->where('po_number_normalized', $normalizedPoNumber);

        return $this->respond(
            $query,
            $request,
            ['type' => 'po_number', 'value' => $poNumber],
            fn (AiExtraction $extraction): bool => $this->isInvoiceOrReceipt($extraction),
        );
    }

    /** @return Builder<AiExtraction> */
    private function baseQuery(): Builder
    {
        return AiExtraction::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('corrected_json')
                    ->orWhereNotNull('raw_extracted_json');
            });
    }

    /**
     * @param  Builder<AiExtraction>  $query
     * @param  array{type: string, value?: int|string}  $filter
     * @param  (callable(AiExtraction): bool)|null  $rowFilter
     */
    private function respond(
        Builder $query,
        PaginatedCorrectedDataRequest $request,
        array $filter,
        ?callable $rowFilter = null,
    ): JsonResponse {
        $perPage = $request->integer('per_page', 50);
        $afterId = $request->integer('after_id', 0);

        $rows = $rowFilter === null
            ? $this->pageRows($query, $afterId, $perPage)
            : $this->filteredPageRows($query, $afterId, $perPage, $rowFilter);

        $hasMore = $rows->count() > $perPage;
        if ($hasMore) {
            $rows->pop();
        }
        $nextAfterId = $hasMore ? $rows->last()?->getKey() : null;

        return response()->json([
            'data' => $rows->map(function (AiExtraction $extraction): array {
                $documentData = $extraction->preferredData() ?? [];

                return [
                    'id' => $extraction->getKey(),
                    'upload' => [
                        'id' => $extraction->upload->getKey(),
                        'submission_id' => $extraction->upload->submission_id,
                        'upload_type' => $extraction->upload->uploadType->only(['id', 'name', 'slug']),
                        'completed_at' => $extraction->upload->upload_completed_at?->toISOString(),
                        'location' => $extraction->upload->latitude === null ? null : [
                            'latitude' => $extraction->upload->latitude,
                            'longitude' => $extraction->upload->longitude,
                            'accuracy_meters' => $extraction->upload->location_accuracy_meters,
                            'captured_at' => $extraction->upload->location_captured_at?->toISOString(),
                        ],
                    ],
                    'source_file' => [
                        'id' => $extraction->file->getKey(),
                        'name' => $extraction->file->original_file_name,
                        'url' => $this->generateFileUrl($extraction->file),
                    ],
                    'document_type' => $extraction->document_type,
                    'invoice_number' => CorrectedDataMetadata::invoiceNumber($documentData),
                    'po_number' => CorrectedDataMetadata::poNumber($documentData),
                    'po_date' => CorrectedDataMetadata::poDate($documentData),
                    'verification_status' => $extraction->dataProvenance(),
                    'corrected_data' => $documentData,
                    'reviewed_at' => $extraction->reviewed_at?->toISOString(),
                ];
            })->values(),
            'meta' => [
                'per_page' => $perPage,
                'has_more' => $hasMore,
                'next_after_id' => $nextAfterId,
                'filter' => $filter,
            ],
        ]);
    }

    /** @return Collection<int, AiExtraction> */
    private function pageRows(Builder $query, int $afterId, int $perPage): Collection
    {
        /** @var Collection<int, AiExtraction> $rows */
        $rows = $this->withResponseRelations($query)
            ->when($afterId > 0, fn (Builder $query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get();

        return $rows;
    }

    /** @param callable(AiExtraction): bool $rowFilter @return Collection<int, AiExtraction> */
    private function filteredPageRows(Builder $query, int $afterId, int $perPage, callable $rowFilter): Collection
    {
        $rows = collect();
        $cursor = $afterId;
        $batchSize = max($perPage + 1, 50);

        while ($rows->count() <= $perPage) {
            $batch = $this->pageRows(clone $query, $cursor, $batchSize - 1);
            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $extraction) {
                $cursor = (int) $extraction->getKey();
                if ($rowFilter($extraction)) {
                    $rows->push($extraction);
                }

                if ($rows->count() > $perPage) {
                    break 2;
                }
            }

            if ($batch->count() < $batchSize) {
                break;
            }
        }

        return $rows;
    }

    /** @param Builder<AiExtraction> $query @return Builder<AiExtraction> */
    private function withResponseRelations(Builder $query): Builder
    {
        return $query->with([
            'file:id,original_file_name,r2_object_key,content_type,declared_content_type',
            'upload:id,submission_id,upload_type_id,latitude,longitude,location_accuracy_meters,location_captured_at,upload_completed_at',
            'upload.uploadType:id,name,slug',
        ]);
    }

    private function isInvoiceOrReceipt(AiExtraction $extraction): bool
    {
        $data = $extraction->preferredData() ?? [];

        $data['document_type'] ??= $extraction->document_type;

        return $this->normalizer->isInvoiceOrReceipt($data);
    }

    private function generateFileUrl(UploadedFile $file): ?string
    {
        if ($file->r2_object_key === null) {
            return null;
        }

        try {
            return Storage::disk((string) config('receiving.disk'))->temporaryUrl(
                $file->r2_object_key,
                now()->addMinutes(60),
            );
        } catch (\Throwable) {
            return URL::temporarySignedRoute(
                'api.v1.corrected-data.stream',
                now()->addMinutes(60),
                ['file' => $file->getKey()]
            );
        }
    }

    public function stream(UploadedFile $file): StreamedResponse
    {
        abort_if($file->r2_object_key === null, 404);

        return Storage::disk((string) config('receiving.disk'))->response(
            $file->r2_object_key,
            $file->original_file_name,
            ['Content-Type' => $file->content_type ?? $file->declared_content_type],
        );
    }
}
