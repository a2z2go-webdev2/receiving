<?php

namespace App\Features\Receiving\Services;

use App\Enums\UploadWorkflow;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use Illuminate\Database\Eloquent\Collection;

class UploadSerialNumber
{
    public function prefix(UploadType $uploadType): string
    {
        return $uploadType->workflow->serialPrefix();
    }

    public function number(ReceivingUpload $upload): int
    {
        $upload->loadMissing('uploadType:id,workflow');

        if ($upload->serial_number !== null && $upload->serial_number > 0) {
            return (int) $upload->serial_number;
        }

        if ($upload->uploadType->workflow !== UploadWorkflow::PurchaseOrder) {
            return (int) $upload->getKey();
        }

        return ReceivingUpload::query()
            ->where('upload_type_id', $upload->upload_type_id)
            ->where('id', '<=', $upload->getKey())
            ->count();
    }

    /**
     * @param  Collection<int, ReceivingUpload>  $uploads
     * @return array<int, int>
     */
    public function numbersFor(Collection $uploads): array
    {
        if ($uploads->isEmpty()) {
            return [];
        }

        $uploads->loadMissing('uploadType:id,workflow');
        $numbers = [];

        $missingPurchaseOrders = collect();

        foreach ($uploads as $upload) {
            if ($upload->serial_number !== null && $upload->serial_number > 0) {
                $numbers[(int) $upload->getKey()] = (int) $upload->serial_number;
            } elseif ($upload->uploadType->workflow !== UploadWorkflow::PurchaseOrder) {
                $numbers[(int) $upload->getKey()] = (int) $upload->getKey();
            } else {
                $missingPurchaseOrders->push($upload);
            }
        }

        if ($missingPurchaseOrders->isNotEmpty()) {
            foreach ($missingPurchaseOrders->groupBy('upload_type_id') as $uploadTypeId => $typeUploads) {
                $uploadIds = $typeUploads
                    ->map(fn (ReceivingUpload $upload): int => (int) $upload->getKey())
                    ->all();

                ReceivingUpload::query()
                    ->where('upload_type_id', $uploadTypeId)
                    ->whereIn('id', $uploadIds)
                    ->select('id')
                    ->selectSub(function ($query) use ($uploadTypeId): void {
                        $query
                            ->from('receiving_uploads as earlier_uploads')
                            ->selectRaw('COUNT(*)')
                            ->where('earlier_uploads.upload_type_id', $uploadTypeId)
                            ->whereColumn('earlier_uploads.id', '<=', 'receiving_uploads.id');
                    }, 'workflow_serial_number')
                    ->get()
                    ->each(function (ReceivingUpload $upload) use (&$numbers): void {
                        $numbers[(int) $upload->getKey()] = (int) $upload->getAttribute('workflow_serial_number');
                    });
            }
        }

        return $numbers;
    }

    public function resolve(UploadType $uploadType, int $serialNumber): ?int
    {
        if ($serialNumber < 1) {
            return null;
        }

        $id = ReceivingUpload::query()
            ->where('upload_type_id', $uploadType->getKey())
            ->where('serial_number', $serialNumber)
            ->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        if ($uploadType->workflow !== UploadWorkflow::PurchaseOrder) {
            $fallbackId = ReceivingUpload::query()
                ->where('upload_type_id', $uploadType->getKey())
                ->whereKey($serialNumber)
                ->value('id');

            return $fallbackId === null ? null : (int) $fallbackId;
        }

        $fallbackId = ReceivingUpload::query()
            ->where('upload_type_id', $uploadType->getKey())
            ->orderBy('id')
            ->skip($serialNumber - 1)
            ->value('id');

        return $fallbackId === null ? null : (int) $fallbackId;
    }

    public function label(ReceivingUpload $upload): string
    {
        $upload->loadMissing('uploadType:id,workflow');

        return sprintf('%s-%d', $this->prefix($upload->uploadType), $this->number($upload));
    }
}
