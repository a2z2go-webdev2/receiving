<?php

namespace App\Http\Controllers\Receiving;

use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Services\UploadOtpGrant;
use App\Http\Controllers\Controller;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class StagingFileController extends Controller
{
    public function __invoke(Request $request, UploadedFile $file, UploadOtpGrant $grant): Response
    {
        $file->loadMissing('upload.uploadType');
        $user = $request->user();
        $uploadType = $file->upload->uploadType;
        abort_unless($user instanceof User
            && $file->upload->uploader_user_id === $user->getKey()
            && $user->canAccessUploadType($uploadType)
            && $grant->refresh($request, $uploadType), 403);
        abort_unless(
            $file->upload->processing_status === UploadProcessingStatus::Staging,
            409,
            'This upload transaction is no longer accepting files.',
        );
        abort_if((int) $request->header('Content-Length', '0') !== $file->original_file_size, 422, 'Uploaded content length differs from the declared size.');

        $stream = $request->getContent(true);
        abort_unless(is_resource($stream), 422, 'No upload body was received.');
        try {
            Storage::disk((string) config('receiving.disk'))->writeStream(
                $file->r2_staging_object_key,
                $stream,
                ['visibility' => 'private', 'ContentType' => $file->declared_content_type],
            );
        } finally {
            fclose($stream);
        }

        return response()->noContent();
    }
}
