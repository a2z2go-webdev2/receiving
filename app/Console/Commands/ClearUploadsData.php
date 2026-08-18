<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadOtp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ClearUploadsData extends Command
{
    protected $signature = 'receiving:clear-uploads
        {--dry-run : Count records and objects without deleting anything}
        {--clear-storage : Also delete stored files from the storage disk (R2)}';

    protected $description = 'Delete all upload transactional data to start fresh. Keeps configuration (upload types, email recipients, authorized access, system settings, users).';

    public function handle(): int
    {
        $this->components->twoColumnDetail('Scope', $this->option('dry-run') ? 'Dry run (no deletions)' : 'Live');

        // Collect counts before deletion
        $uploadCount = ReceivingUpload::query()->count();
        $fileCount = UploadedFile::query()->count();
        $otpCount = UploadOtp::query()->count();
        $activityLogCount = ActivityLog::query()->whereNotNull('receiving_upload_id')->count();

        $this->components->twoColumnDetail('Receiving uploads to clear', (string) $uploadCount);
        $this->components->twoColumnDetail('Uploaded files to clear', (string) $fileCount);
        $this->components->twoColumnDetail('Upload OTPs to clear', (string) $otpCount);
        $this->components->twoColumnDetail('Upload-related activity logs to clear', (string) $activityLogCount);

        if ($uploadCount === 0 && $otpCount === 0 && $activityLogCount === 0) {
            $this->components->success('No upload data to clear.');

            return self::SUCCESS;
        }

        // Skip confirmation for dry-run since nothing will be modified.
        if (! $this->option('dry-run') && ! $this->confirm('This will permanently delete all upload records and related data. Are you sure?', true)) {
            $this->components->warn('Command cancelled.');

            return self::SUCCESS;
        }

        $storageCount = 0;

        if ($this->option('clear-storage')) {
            // Count storage objects even in dry-run mode.
            $pendingObjectKeys = UploadedFile::query()
                ->whereNotNull('r2_staging_object_key')
                ->orWhereNotNull('r2_object_key')
                ->count();

            $this->components->twoColumnDetail('Storage objects to delete', (string) $pendingObjectKeys);

            if (! $this->option('dry-run')) {
                $disk = Storage::disk((string) config('receiving.disk'));

                UploadedFile::query()->chunkById(200, function ($files) use ($disk, &$storageCount): void {
                    foreach ($files as $file) {
                        $paths = array_filter([
                            $file->r2_staging_object_key,
                            $file->r2_object_key,
                        ]);

                        foreach ($paths as $path) {
                            if ($disk->exists($path)) {
                                $disk->delete($path);
                                $storageCount++;
                            }
                        }
                    }
                });
            }
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Delete upload-related activity logs FIRST (nullOnDelete on the FK would
        // nullify the reference when receiving_uploads are deleted, making them
        // unfindable by receiving_upload_id).
        ActivityLog::query()->whereNotNull('receiving_upload_id')->delete();

        // receiving_uploads cascade-deletes: uploaded_files, ai_extractions, review_links.
        ReceivingUpload::query()->delete();
        UploadOtp::query()->delete();

        $this->components->twoColumnDetail('Receiving uploads cleared', (string) $uploadCount);
        $this->components->twoColumnDetail('Upload OTPs cleared', (string) $otpCount);
        $this->components->twoColumnDetail('Upload activity logs cleared', (string) $activityLogCount);

        if ($this->option('clear-storage')) {
            $this->components->twoColumnDetail('Storage objects deleted', (string) $storageCount);
        }

        $this->components->success('Upload data cleared. Configuration is untouched.');

        return self::SUCCESS;
    }
}
