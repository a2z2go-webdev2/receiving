<?php

namespace App\Http\Controllers\Receiving;

use App\Http\Controllers\Controller;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

class NotificationViewController extends Controller
{
    public function __invoke(ReceivingUpload $upload): Response
    {
        $upload->load(['uploadType', 'files']);

        return Inertia::render('review/transaction', [
            'upload' => [
                'serial_number' => $upload->getKey(),
                'upload_type' => $upload->uploadType->name,
                'uploader_email' => $upload->uploader_email,
                'created_at' => $upload->created_at->toISOString(),
                'review_email_status' => $upload->review_email_status->value,
                'ai_status' => $upload->ai_status->value,
                'review_status' => $upload->review_status->value,
                'files' => $upload->files->map(fn (UploadedFile $file): array => [
                    'name' => $file->original_file_name,
                    'validation_status' => $file->validation_status->value,
                    'virus_scan_status' => $file->virus_scan_status->value,
                ])->values(),
            ],
        ]);
    }
}
