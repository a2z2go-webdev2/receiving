<?php

namespace App\Console\Commands;

use App\Jobs\SyncLegacyFilesToR2Job;
use App\Models\UploadedFile;
use Illuminate\Console\Command;

class SyncLegacyR2Command extends Command
{
    protected $signature = 'legacy:sync-r2
                            {--limit=100 : Maximum number of files to dispatch}';

    protected $description = 'Dispatch background sync jobs to transfer legacy files from Google Drive to Cloudflare R2';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $files = UploadedFile::query()
            ->where('r2_object_key', 'LIKE', 'receiving/%')
            ->orWhere('r2_object_key', 'LIKE', 'pingcon/%')
            ->orWhere('r2_object_key', 'LIKE', 'bonita/%')
            ->limit($limit)
            ->get();

        $this->info("Found {$files->count()} legacy files to check for R2 sync.");

        $dispatched = 0;
        foreach ($files as $file) {
            // Extract drive ID if stored in file name or metadata
            if (preg_match('/1[a-zA-Z0-9_-]{25,}/', $file->stored_file_name, $m)) {
                SyncLegacyFilesToR2Job::dispatch($file->getKey(), $m[0], $file->r2_object_key);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} R2 sync jobs to the queue.");

        return self::SUCCESS;
    }
}
