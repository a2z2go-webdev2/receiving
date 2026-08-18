<?php

namespace App\Http\Controllers\Admin;

use App\Features\Receiving\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\UploadType;
use App\Services\LegacyImport\LegacyImportManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LegacyDataImportController extends Controller
{
    public function store(
        Request $request,
        UploadType $uploadType,
        LegacyImportManager $importManager,
        ActivityLogger $activity
    ): RedirectResponse {
        $dirPath = trim((string) $request->input('directory_path', ''));

        if ($dirPath !== '') {
            if (! is_dir($dirPath)) {
                throw ValidationException::withMessages([
                    'directory_path' => ["The directory path '{$dirPath}' does not exist or is not readable on the server."],
                ]);
            }

            $logPath = $this->findFile($dirPath, ['receiving_log', 'receivinglog', 'receiving']);
            $filePath = $this->findFile($dirPath, ['receive_files', 'receivefiles', 'files']);
            $extPath = $this->findFile($dirPath, ['ai_extraction', 'aiextraction', 'extractions']);

            if (! $logPath) {
                throw ValidationException::withMessages([
                    'directory_path' => ["Could not find a Receiving Log file (Receiving_Log.html or Receiving_Log.csv) in folder '{$dirPath}'."],
                ]);
            }

            $results = $importManager->importFromInputs(
                $uploadType,
                [
                    'logs' => $logPath,
                    'files' => $filePath ?? '',
                    'extractions' => $extPath ?? '',
                ]
            );
        } else {
            // Validate PHP upload errors
            $this->checkPhpUploadErrors($request);

            $request->validate([
                'logs_file' => ['required', 'file'],
                'files_file' => ['nullable', 'file'],
                'extractions_file' => ['nullable', 'file'],
            ]);

            $logsContent = $request->file('logs_file')?->get() ?? '';
            $filesContent = $request->file('files_file')?->get() ?? '';
            $extractionsContent = $request->file('extractions_file')?->get() ?? '';

            $results = $importManager->importFromInputs(
                $uploadType,
                [
                    'logs' => $logsContent,
                    'files' => $filesContent,
                    'extractions' => $extractionsContent,
                ]
            );
        }

        $activity->record(
            'admin',
            'legacy_data_import',
            'success',
            "Imported {$results['imported_submissions']} submissions ({$results['imported_files']} files) for {$uploadType->name}.",
            $request->user(),
            null,
            $request
        );

        $msg = "Imported {$results['imported_submissions']} submissions and {$results['imported_files']} files successfully for {$uploadType->name}.";
        if (! empty($results['errors'])) {
            $msg .= ' ('.count($results['errors']).' non-fatal row warnings encountered)';
        }

        return back()->with('status', $msg);
    }

    private function checkPhpUploadErrors(Request $request): void
    {
        $iniLimit = ini_get('upload_max_filesize');
        foreach (['logs_file', 'files_file', 'extractions_file'] as $field) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_INI_SIZE) {
                throw ValidationException::withMessages([
                    $field => ["File exceeds PHP upload limit (upload_max_filesize = {$iniLimit}). Please enter the folder path directly or increase upload_max_filesize in php.ini."],
                ]);
            }
        }
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
