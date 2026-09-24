<?php

namespace App\Features\Warehouse\Services;

use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseStockSource;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\PurchaseOrderItemArrival;
use App\Models\ReceivingUpload;
use App\Models\User;
use App\Models\WarehouseProgressEvent;
use App\Models\WarehouseStockLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReceiptPostingService
{
    public function __construct(
        private readonly WarehouseItemResolver $itemResolver,
    ) {}

    /**
     * Post stock for a single arrival record atomically and idempotently.
     */
    public function postArrival(
        PurchaseOrderItemArrival $arrival,
        ?User $actor = null,
        string $provenance = 'automatic_upload',
    ): ?WarehouseStockLot {
        return DB::transaction(function () use ($arrival, $actor, $provenance): ?WarehouseStockLot {
            /** @var PurchaseOrderItemArrival $locked */
            $locked = PurchaseOrderItemArrival::query()
                ->with(['upload', 'schedule', 'poItem'])
                ->lockForUpdate()
                ->findOrFail($arrival->getKey());

            // 1. Idempotency check: already posted or has lot
            if ($locked->posting_status === 'posted' && $locked->warehouse_stock_lot_id !== null) {
                return WarehouseStockLot::query()->find($locked->warehouse_stock_lot_id);
            }

            $sourceKey = $locked->source_key ?? "arrival:{$locked->getKey()}";

            $existingLot = WarehouseStockLot::query()->where('source_key', $sourceKey)->first();
            if ($existingLot !== null) {
                $locked->forceFill([
                    'posting_status' => 'posted',
                    'posted_at' => $locked->posted_at ?? now(),
                    'warehouse_stock_lot_id' => $existingLot->getKey(),
                ])->save();

                return $existingLot;
            }

            // 2. Eligibility check: positive arrived quantity and non-adjustment
            $arrivedQty = (float) $locked->arrived_quantity;
            if ($arrivedQty <= 0) {
                $locked->forceFill(['posting_status' => 'exempt'])->save();

                return null;
            }

            if ($locked->poItem?->is_financial_adjustment) {
                $locked->forceFill(['posting_status' => 'exempt'])->save();

                return null;
            }

            // 3. Resolve warehouse item
            $item = $this->itemResolver->forArrival($locked);

            // 4. Determine placement timestamp and actor attribution
            $upload = $locked->upload;
            $placedAt = $upload->upload_completed_at ?? $upload->created_at ?? now();
            if (! $placedAt instanceof CarbonImmutable) {
                $placedAt = CarbonImmutable::parse($placedAt);
            }

            $confirmedByUserId = $actor?->getKey() ?? $upload->uploader_user_id;

            // 5. Create WarehouseStockLot
            $lot = WarehouseStockLot::query()->create([
                'warehouse_item_id' => $item->getKey(),
                'source_type' => WarehouseStockSource::Arrival,
                'source_key' => $sourceKey,
                'purchase_order_item_arrival_id' => $locked->getKey(),
                'ai_extraction_id' => $locked->ai_extraction_id,
                'receiving_upload_id' => $locked->receiving_upload_id,
                'po_number' => $locked->po_number,
                'lot_number' => $locked->po_number,
                'quantity_received' => number_format($arrivedQty, 3, '.', ''),
                'received_at' => $placedAt->toDateString(),
                'received_date_quality' => WarehouseDateQuality::Confirmed,
                'confirmed_by_user_id' => $confirmedByUserId,
                'confirmed_at' => $placedAt,
                'posting_provenance' => $provenance,
                'notes' => 'Auto-posted from receiving upload'.($upload ? " SN-{$upload->getKey()}" : ''),
            ]);

            // 6. Update arrival
            $locked->forceFill([
                'posting_status' => 'posted',
                'posted_at' => now(),
                'warehouse_stock_lot_id' => $lot->getKey(),
            ])->save();

            // 7. Record progress event
            WarehouseProgressEvent::query()->create([
                'aggregate_type' => 'stock_lot',
                'aggregate_id' => $lot->getKey(),
                'from_status' => 'pending_arrival',
                'to_status' => 'in_warehouse',
                'event_date' => $placedAt->toDateString(),
                'actor_user_id' => $confirmedByUserId,
                'metadata' => [
                    'source' => WarehouseStockSource::Arrival->value,
                    'quantity' => $lot->quantity_received,
                    'provenance' => $provenance,
                ],
            ]);

            return $lot;
        }, 3);
    }

    /**
     * Post stock for all eligible arrivals belonging to a document link.
     *
     * @return Collection<int, WarehouseStockLot>
     */
    public function postForLink(
        PurchaseOrderDocumentLink $link,
        ?User $actor = null,
        string $provenance = 'automatic_upload',
    ): Collection {
        $link->loadMissing(['arrivals.upload']);
        $lots = collect();

        foreach ($link->arrivals as $arrival) {
            $lot = $this->postArrival($arrival, $actor, $provenance);
            if ($lot !== null) {
                $lots->push($lot);
            }
        }

        return $lots;
    }

    /**
     * Post stock for all eligible arrivals of a live receiving upload.
     *
     * @return Collection<int, WarehouseStockLot>
     */
    public function postForUpload(
        ReceivingUpload $upload,
        ?User $actor = null,
        string $provenance = 'automatic_upload',
    ): Collection {
        if ($upload->is_historical) {
            return collect();
        }

        $arrivals = PurchaseOrderItemArrival::query()
            ->where('receiving_upload_id', $upload->getKey())
            ->get();

        $lots = collect();
        foreach ($arrivals as $arrival) {
            $lot = $this->postArrival($arrival, $actor, $provenance);
            if ($lot !== null) {
                $lots->push($lot);
            }
        }

        return $lots;
    }
}
