<?php

namespace App\Console\Commands;

use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Models\ReceivingUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupReceivingStaging extends Command
{
    protected $signature = 'receiving:cleanup-staging {--dry-run : Report abandoned staging objects without deleting them}';

    protected $description = 'Remove expired, abandoned receiving staging objects.';

    public function handle(ReceivingSettings $settings, ActivityLogger $activity): int
    {
        $cutoff = now()->subHours((int) $settings->get('staging_cleanup_hours'));
        $disk = Storage::disk((string) config('receiving.disk'));
        $count = 0;

        ReceivingUpload::query()
            ->where('processing_status', UploadProcessingStatus::Staging)
            ->where('created_at', '<', $cutoff)
            ->with('files')
            ->chunkById(100, function ($uploads) use ($disk, $activity, &$count): void {
                foreach ($uploads as $upload) {
                    foreach ($upload->files as $file) {
                        if (! $this->option('dry-run')) {
                            $disk->delete($file->r2_staging_object_key);
                        }
                        $count++;
                    }

                    if (! $this->option('dry-run')) {
                        $upload->forceFill([
                            'processing_status' => UploadProcessingStatus::Failed,
                            'failure_reason' => 'Staging upload expired before completion.',
                        ])->save();
                        $activity->record('system', 'staging_upload_expired', 'warning', 'Expired abandoned staging upload was cleaned up.', null, $upload);
                    }
                }
            });

        $this->info(($this->option('dry-run') ? 'Would remove' : 'Removed')." {$count} staging objects.");

        return self::SUCCESS;
    }
}
