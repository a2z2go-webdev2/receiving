<?php

namespace App\Console\Commands;

use App\Models\UploadType;
use App\Services\LegacyImport\LegacyImportManager;
use Illuminate\Console\Command;

class ImportLegacyDataCommand extends Command
{
    protected $signature = 'import:legacy-data
                            {upload-type : The slug of the UploadType (e.g. pingcon, bonita)}
                            {path : Absolute directory path containing legacy export files}
                            {--skip-r2 : Skip asynchronous R2 background file download}';

    protected $description = 'Import legacy receiving data (CSV or HTML exports) into the database for a specific UploadType';

    public function handle(LegacyImportManager $importManager): int
    {
        $typeSlug = (string) $this->argument('upload-type');
        $dirPath = (string) $this->argument('path');
        $skipR2 = (bool) $this->option('skip-r2');

        /** @var UploadType|null $uploadType */
        $uploadType = UploadType::query()->where('slug', strtolower($typeSlug))->first();
        if (! $uploadType) {
            $this->error("UploadType with slug '{$typeSlug}' not found.");

            return self::FAILURE;
        }

        if (! is_dir($dirPath)) {
            $this->error("Directory path '{$dirPath}' does not exist.");

            return self::FAILURE;
        }

        $this->info("Scanning directory '{$dirPath}' for legacy export files...");

        $logPath = $this->findFile($dirPath, ['receiving_log', 'receivinglog', 'receiving']);
        $filePath = $this->findFile($dirPath, ['receive_files', 'receivefiles', 'files']);
        $extPath = $this->findFile($dirPath, ['ai_extraction', 'aiextraction', 'extractions']);

        if (! $logPath) {
            $this->error('Could not locate Receiving Log file (Receiving_Log.html or Receiving_Log.csv).');

            return self::FAILURE;
        }

        $this->info("Found Receiving Log: {$logPath}");
        if ($filePath) {
            $this->info("Found Receive Files: {$filePath}");
        }
        if ($extPath) {
            $this->info("Found AI Extractions: {$extPath}");
        }

        $this->info("Executing legacy data import for UploadType: {$uploadType->name} ({$uploadType->slug})...");

        $results = $importManager->importFromInputs(
            $uploadType,
            [
                'logs' => $logPath,
                'files' => $filePath ?? '',
                'extractions' => $extPath ?? '',
            ],
            ['skip_r2_sync' => $skipR2]
        );

        $this->newLine();
        $this->info('=== IMPORT COMPLETE ===');
        $this->line("Submissions Imported: {$results['imported_submissions']}");
        $this->line("Files Imported:       {$results['imported_files']}");
        $this->line("Extractions Imported: {$results['imported_extractions']}");

        if (! empty($results['errors'])) {
            $this->warn('Errors encountered during import:');
            foreach ($results['errors'] as $err) {
                $this->error(" - {$err}");
            }
        }

        return self::SUCCESS;
    }

    private function findFile(string $dir, array $keywords): ?string
    {
        $files = glob("{$dir}/*");
        if (! $files) {
            return null;
        }

        foreach ($files as $f) {
            $filename = strtolower(basename($f));
            foreach ($keywords as $kw) {
                if (str_contains($filename, strtolower($kw))) {
                    return $f;
                }
            }
        }

        return null;
    }
}
