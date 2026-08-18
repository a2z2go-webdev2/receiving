<?php

namespace App\Services\LegacyImport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDriveStreamer
{
    /**
     * Download a Google Drive file by ID or direct URL and return the temporary file path.
     */
    public function downloadToTemp(string $fileIdOrUrl): ?string
    {
        $fileId = $this->extractFileId($fileIdOrUrl);
        if ($fileId === '') {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'gdrive_').'.tmp';

        // Public download URL format for Google Drive files
        $downloadUrl = "https://drive.google.com/uc?export=download&id={$fileId}&confirm=t";

        try {
            $response = Http::withOptions([
                'allow_redirects' => true,
                'sink' => $tempPath,
                'timeout' => 60,
            ])->get($downloadUrl);

            if ($response->successful() && file_exists($tempPath) && filesize($tempPath) > 0) {
                return $tempPath;
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to stream Google Drive file {$fileId}: {$e->getMessage()}");
        }

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        return null;
    }

    /**
     * Extract clean Google Drive File ID from URL or ID string.
     */
    public function extractFileId(string $fileIdOrUrl): string
    {
        $trimmed = trim($fileIdOrUrl);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/[-\w]{25,}/', $trimmed, $matches)) {
            return $matches[0];
        }

        return $trimmed;
    }

    /**
     * Extract clean Google Drive Folder ID from URL.
     */
    public function extractFolderId(string $folderUrl): string
    {
        $trimmed = trim($folderUrl);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/folders\/([-\w]{25,})/', $trimmed, $matches)) {
            return $matches[1];
        }

        if (preg_match('/[-\w]{25,}/', $trimmed, $matches)) {
            return $matches[0];
        }

        return '';
    }
}
