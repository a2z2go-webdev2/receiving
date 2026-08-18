<?php

namespace App\Jobs;

use App\Models\UploadedFile;
use App\Services\LegacyImport\GoogleDriveStreamer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncLegacyFilesToR2Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $uploadedFileId,
        public string $driveFileId,
        public string $targetR2ObjectKey
    ) {}

    public function handle(GoogleDriveStreamer $streamer): void
    {
        $uploadedFile = UploadedFile::find($this->uploadedFileId);
        if (! $uploadedFile) {
            return;
        }

        $tempPath = $streamer->downloadToTemp($this->driveFileId);
        if (! $tempPath) {
            Log::warning("SyncLegacyFilesToR2Job: Could not download file for UploadedFile ID {$this->uploadedFileId}");

            return;
        }

        try {
            $stream = fopen($tempPath, 'r');
            if ($stream === false) {
                return;
            }

            $r2Disk = Storage::disk('r2');
            $uploaded = $r2Disk->put($this->targetR2ObjectKey, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($uploaded) {
                $fileSize = filesize($tempPath);
                $uploadedFile->update([
                    'r2_bucket' => config('filesystems.disks.r2.bucket', 'receiving-documents'),
                    'r2_object_key' => $this->targetR2ObjectKey,
                    'final_file_size' => $fileSize !== false ? $fileSize : $uploadedFile->original_file_size,
                ]);

                Log::info("Successfully synced legacy file {$uploadedFile->id} to R2 object key {$this->targetR2ObjectKey}");
            }
        } catch (\Throwable $e) {
            Log::error("Failed to upload legacy file {$this->uploadedFileId} to R2: {$e->getMessage()}");
            throw $e;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
