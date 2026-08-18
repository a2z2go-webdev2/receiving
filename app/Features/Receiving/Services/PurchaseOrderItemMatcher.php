<?php

namespace App\Features\Receiving\Services;

use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use App\Models\PurchaseOrderItemFulfillment;
use App\Models\PurchaseOrderItemSchedule;
use Illuminate\Support\Collection;

class PurchaseOrderItemMatcher
{
    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    public function sync(PoExtraction $poExtraction): void
    {
        $poExtraction->loadMissing(['items', 'upload']);
        $schedules = PurchaseOrderItemSchedule::query()
            ->where('is_active', true)
            ->get();

        PurchaseOrderItemFulfillment::query()
            ->where('po_extraction_id', $poExtraction->getKey())
            ->delete();

        $poDate = $poExtraction->po_date_value ?? $this->normalizer->parseDate($poExtraction->po_date);
        $poWeek = $poDate === null ? null : $this->normalizer->weekOfMonth($poDate);

        foreach ($poExtraction->items as $item) {
            $match = $this->scheduleFor($item, $schedules);
            if ($match === null) {
                continue;
            }

            PurchaseOrderItemFulfillment::query()->create([
                'purchase_order_item_schedule_id' => $match['schedule']->getKey(),
                'po_extraction_id' => $poExtraction->getKey(),
                'po_extraction_item_id' => $item->getKey(),
                'receiving_upload_id' => $poExtraction->receiving_upload_id,
                'po_number' => $poExtraction->po_number,
                'po_date' => $poDate?->toDateString(),
                'po_week' => $poWeek,
                'ordered_quantity' => $this->normalizer->decimalString(
                    $this->normalizer->quantity($item->quantity),
                ),
                'unit' => $item->unit,
                'matched_by' => $match['matched_by'],
            ]);
        }
    }

    /**
     * @param  Collection<int, PurchaseOrderItemSchedule>  $schedules
     * @return array{schedule: PurchaseOrderItemSchedule, matched_by: string}|null
     */
    private function scheduleFor(PoExtractionItem $item, Collection $schedules): ?array
    {
        $candidates = $schedules
            ->map(function (PurchaseOrderItemSchedule $schedule) use ($item): ?array {
                $identifierMatch = $this->normalizer->identifierMatchType($item->item_code, $schedule->sku_number);
                if ($identifierMatch !== null) {
                    $descriptionScore = $this->normalizer->descriptionMatchScore(
                        $item->product_description,
                        $schedule->description,
                    ) ?? 0.0;

                    return $this->candidate(
                        $schedule,
                        $identifierMatch,
                        ($identifierMatch === 'sku' ? 300.0 : 250.0) + ($descriptionScore * 10.0),
                    );
                }

                $eanMatch = $this->normalizer->eanMatchType($item->item_code, $schedule->ean_barcode);
                if ($eanMatch !== null) {
                    $descriptionScore = $this->normalizer->descriptionMatchScore(
                        $item->product_description,
                        $schedule->description,
                    ) ?? 0.0;

                    return $this->candidate(
                        $schedule,
                        $eanMatch,
                        ($eanMatch === 'ean' ? 290.0 : 240.0) + ($descriptionScore * 10.0),
                    );
                }

                $descriptionScore = $this->normalizer->descriptionMatchScore(
                    $item->product_description,
                    $schedule->description,
                );
                if ($descriptionScore === null) {
                    return null;
                }

                $matchedBy = $descriptionScore >= 1.0 ? 'description' : 'description_partial';

                return $this->candidate($schedule, $matchedBy, 100.0 + ($descriptionScore * 100.0));
            })
            ->filter()
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = $candidates
            ->sort($this->candidateSorter())
            ->first();

        return is_array($best) ? [
            'schedule' => $best['schedule'],
            'matched_by' => $best['matched_by'],
        ] : null;
    }

    /** @return array{schedule: PurchaseOrderItemSchedule, matched_by: string, score: float, id: int} */
    private function candidate(
        PurchaseOrderItemSchedule $schedule,
        string $matchedBy,
        float $score,
    ): array {
        return [
            'schedule' => $schedule,
            'matched_by' => $matchedBy,
            'score' => $score,
            'id' => (int) $schedule->getKey(),
        ];
    }

    /** @return callable(array, array): int */
    private function candidateSorter(): callable
    {
        return function (array $left, array $right): int {
            return [$right['score'], -$right['id']] <=> [$left['score'], -$left['id']];
        };
    }
}
