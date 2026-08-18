<?php

namespace App\Http\Controllers\Receiving;

use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Actions\InitiateReceivingUpload;
use App\Features\Receiving\Jobs\ProcessReceivingUpload;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\StagingUploadUrlFactory;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receiving\InitiateUploadRequest;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;

class UploadTransactionController extends Controller
{
    public function store(
        InitiateUploadRequest $request,
        UploadType $uploadType,
        InitiateReceivingUpload $action,
        StagingUploadUrlFactory $urls,
        UploadSerialNumber $serials,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        /** @var array<int, array{name: string, size: int, content_type: string, extension: string}> $files */
        $files = $request->validated('files');
        /** @var array{latitude: float|int|string, longitude: float|int|string, accuracy: float|int|string, captured_at: string}|null $location */
        $location = $request->validated('location');
        $upload = $action->handle(
            $user,
            $uploadType,
            $files,
            $request->ip(),
            $request->string('submission_id')->toString(),
            $location,
        );

        return response()->json([
            'upload_id' => $upload->getKey(),
            'serial_number' => $serials->number($upload),
            'serial_prefix' => $serials->prefix($upload->uploadType),
            'files' => $upload->files->map(fn ($file): array => [
                'id' => $file->getKey(),
                'name' => $file->original_file_name,
                ...$urls->for($file),
            ])->values(),
        ], 201);
    }

    public function complete(
        Request $request,
        UploadType $uploadType,
        ReceivingUpload $upload,
        ActivityLogger $activity,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User
            && $upload->uploader_user_id === $user->getKey()
            && $upload->upload_type_id === $uploadType->getKey()
            && $user->canAccessUploadType($uploadType), 403);

        if ($upload->processing_status !== UploadProcessingStatus::Staging) {
            return response()->json(['message' => 'This upload was already submitted.', 'upload_id' => $upload->getKey()]);
        }

        $disk = Storage::disk((string) config('receiving.disk'));
        $upload->load('files');
        $errors = [];
        foreach ($upload->files as $file) {
            try {
                $uploadedSize = $disk->size($file->r2_staging_object_key);
            } catch (UnableToRetrieveMetadata) {
                $errors["files.{$file->getKey()}"][] = "{$file->original_file_name} did not finish uploading or could not be confirmed.";

                continue;
            }

            if ($uploadedSize !== $file->original_file_size) {
                $errors["files.{$file->getKey()}"][] = "{$file->original_file_name} does not match the declared size.";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $submitted = DB::transaction(function () use ($upload): bool {
            $submittedAt = now();
            $claimed = ReceivingUpload::query()
                ->whereKey($upload->getKey())
                ->where('processing_status', UploadProcessingStatus::Staging)
                ->update([
                    'processing_status' => UploadProcessingStatus::Queued,
                    'upload_completed_at' => $submittedAt,
                    'updated_at' => $submittedAt,
                ]);

            if ($claimed !== 1) {
                return false;
            }

            $upload->files()->update(['uploaded_at' => $submittedAt]);
            ProcessReceivingUpload::dispatch($upload->getKey())->afterCommit();

            return true;
        });

        if (! $submitted) {
            return response()->json(['message' => 'This upload was already submitted.', 'upload_id' => $upload->getKey()]);
        }

        $upload->refresh();
        $activity->record(
            'upload',
            'files_uploaded',
            'success',
            "{$user->email} uploaded {$upload->file_count} ".str('file')->plural($upload->file_count).'. The files were received and queued for processing.',
            $user,
            $upload,
            $request,
        );

        return response()->json([
            'message' => $uploadType->workflow->requiresReview()
                ? 'Upload successful. Your files have been received and are now being processed. You will receive a review email once AI extraction is completed.'
                : 'Upload successful. Your purchase order has been received and queued for AI extraction.',
            'upload_id' => $upload->getKey(),
        ]);
    }
}
