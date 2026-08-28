<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UploadWorkflow;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request, UploadSerialNumber $serials): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        $uploadId = $this->resolveUploadId($search, $serials);
        $logs = ActivityLog::query()
            ->with(['upload:id,upload_type_id,serial_number', 'upload.uploadType:id,name,workflow'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($pattern, $uploadId): void {
                if ($uploadId !== null) {
                    $query->orWhere('receiving_upload_id', $uploadId)
                        ->orWhereHas('upload', fn ($u) => $u->where('serial_number', $uploadId));
                }

                $query
                    ->orWhereRaw("LOWER(COALESCE(user_email, '')) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(action) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(message) LIKE ? ESCAPE '!'", [$pattern]);
            }))
            ->when(isset($validated['module']), fn (Builder $query) => $query->where('module', $validated['module']))
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->when(isset($validated['start_date']), fn (Builder $query) => $query->whereDate('created_at', '>=', $validated['start_date']))
            ->when(isset($validated['end_date']), fn (Builder $query) => $query->whereDate('created_at', '<=', $validated['end_date']))
            ->latest('id')
            ->paginate(10)
            ->withQueryString();
        $uploads = new EloquentCollection(
            $logs->getCollection()
                ->map(function (ActivityLog $log): ?ReceivingUpload {
                    $upload = $log->upload;

                    return $upload instanceof ReceivingUpload ? $upload : null;
                })
                ->filter()
                ->values()
                ->all(),
        );
        $serialNumbers = $serials->numbersFor($uploads);
        $logs = $logs->through(function (ActivityLog $log) use ($serials, $serialNumbers): array {
            $upload = $log->upload;

            return [
                ...$log->only([
                    'id', 'receiving_upload_id', 'user_email', 'role', 'module', 'action', 'status',
                    'message', 'error_details', 'ip_address', 'created_at',
                ]),
                'created_at' => $log->created_at->toISOString(),
                'upload' => ! $upload instanceof ReceivingUpload ? null : [
                    'id' => $upload->getKey(),
                    'serial_number' => $serialNumbers[$upload->getKey()] ?? $upload->getKey(),
                    'serial_prefix' => $serials->prefix($upload->uploadType),
                    'upload_type' => ['name' => $upload->uploadType->name],
                ],
            ];
        });

        return Inertia::render('admin/activity/index', [
            'logs' => $logs,
            'filters' => [
                'search' => $search,
                'module' => (string) ($validated['module'] ?? ''),
                'status' => (string) ($validated['status'] ?? ''),
                'start_date' => (string) ($validated['start_date'] ?? ''),
                'end_date' => (string) ($validated['end_date'] ?? ''),
            ],
            'filterOptions' => fn (): array => [
                'modules' => ActivityLog::query()->distinct()->orderBy('module')->pluck('module'),
                'statuses' => ActivityLog::query()->distinct()->orderBy('status')->pluck('status'),
            ],
        ]);
    }

    private function resolveUploadId(string $search, UploadSerialNumber $serials): ?int
    {
        if (preg_match('/^posn[\s-]*(\d+)$/i', $search, $matches) === 1) {
            $purchaseOrderType = UploadType::query()
                ->where('workflow', UploadWorkflow::PurchaseOrder)
                ->first(['id', 'workflow']);

            return $purchaseOrderType === null
                ? null
                : $serials->resolve($purchaseOrderType, (int) $matches[1]);
        }

        return preg_match('/^(?:sn[\s-]*)?(\d+)$/i', $search, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
