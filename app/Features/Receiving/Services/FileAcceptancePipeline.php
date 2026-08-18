<?php

namespace App\Features\Receiving\Services;

use App\Enums\CompressionStatus;
use App\Enums\ValidationStatus;
use App\Enums\VirusScanStatus;
use App\Features\Receiving\Contracts\FileScanner;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FileAcceptancePipeline
{
    public function __construct(
        private readonly FileScanner $scanner,
        private readonly ReceivingSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function process(UploadedFile $file): void
    {
        $file->loadMissing('upload.uploadType');
        $disk = Storage::disk((string) config('receiving.disk'));
        $sourcePath = tempnam(sys_get_temp_dir(), 'receiving-source-');
        $outputPath = tempnam(sys_get_temp_dir(), 'receiving-output-');

        if ($sourcePath === false || $outputPath === false) {
            throw new RuntimeException('Unable to allocate bounded temporary storage.');
        }

        $stage = 'file_retrieval';
        $displayName = $file->sanitized_file_name;
        $this->activity->record('upload', 'file_processing_started', 'info', "Backend processing started for {$displayName}.", null, $file->upload);

        try {
            $source = $disk->readStream($file->r2_staging_object_key);
            $destination = fopen($sourcePath, 'wb');

            if (! is_resource($source) || ! is_resource($destination)) {
                throw new RuntimeException('Unable to read the staged object.');
            }

            stream_copy_to_stream($source, $destination);
            fclose($source);
            fclose($destination);

            $stage = 'file_validation';
            $mime = $this->validate($file, $sourcePath);
            $file->forceFill([
                'content_type' => $mime,
                'file_hash' => hash_file('sha256', $sourcePath),
                'validation_status' => ValidationStatus::Valid,
            ])->save();
            $this->activity->record('upload', 'file_validation_completed', 'success', "{$displayName} passed file validation.", null, $file->upload);

            $stage = 'virus_scan';
            $this->activity->record('security', 'virus_scan_started', 'info', "Virus scanning started for {$displayName}.", null, $file->upload);
            $scan = $this->scanner->scan($sourcePath);
            $file->forceFill(['virus_scan_status' => $scan->status])->save();

            if ($scan->status !== VirusScanStatus::Clean) {
                throw new RuntimeException($scan->message ?? 'This file failed the malware security scan.');
            }
            $this->activity->record('security', 'virus_scan_clean', 'success', "Virus scan found no issues in {$displayName}.", null, $file->upload);

            $stage = 'file_compression';
            $compressionExpected = (bool) $this->settings->get('compression_enabled') && $mime !== 'application/pdf';
            if ($compressionExpected) {
                $this->activity->record('upload', 'file_compression_started', 'info', "Compression started for {$displayName}.", null, $file->upload);
            }
            [$finalPath, $compressionStatus] = $this->compress($sourcePath, $outputPath, $mime);
            $this->activity->record(
                'upload',
                $compressionStatus === CompressionStatus::Compressed ? 'file_compressed' : 'file_compression_skipped',
                'success',
                $compressionStatus === CompressionStatus::Compressed
                    ? "{$displayName} was compressed successfully."
                    : "Compression was not needed for {$displayName}.",
                null,
                $file->upload,
            );

            $stage = 'file_storage';
            $finalKey = $this->finalKey($file);
            $finalStream = fopen($finalPath, 'rb');

            if (! is_resource($finalStream)) {
                throw new RuntimeException('Unable to open the accepted file for final storage.');
            }

            $this->activity->record('storage', 'file_storage_started', 'info', "Secure file storage started for {$displayName}.", null, $file->upload);
            $disk->writeStream($finalKey, $finalStream, ['visibility' => 'private']);
            fclose($finalStream);
            $finalSize = filesize($finalPath);

            $file->forceFill([
                'r2_object_key' => $finalKey,
                'compressed_file_size' => $compressionStatus === CompressionStatus::Compressed ? $finalSize : null,
                'final_file_size' => $finalSize,
                'compression_status' => $compressionStatus,
                'failure_reason' => null,
            ])->save();

            $disk->delete($file->r2_staging_object_key);
            $this->activity->record('storage', 'file_stored', 'success', "{$displayName} was stored successfully in secure file storage.", null, $file->upload);
        } catch (Throwable $error) {
            if ($file->validation_status === ValidationStatus::Pending) {
                $file->validation_status = ValidationStatus::Failed;
            }
            if ($stage === 'virus_scan' && $file->virus_scan_status === VirusScanStatus::Pending) {
                $file->virus_scan_status = VirusScanStatus::Failed;
            }
            if ($file->compression_status === CompressionStatus::Pending && str_contains(strtolower($error->getMessage()), 'compress')) {
                $file->compression_status = CompressionStatus::Failed;
            }
            $file->failure_reason = $error->getMessage();
            $file->save();

            if ($file->validation_status === ValidationStatus::Invalid
                || in_array($file->virus_scan_status, [VirusScanStatus::Infected, VirusScanStatus::Suspicious], true)) {
                $disk->delete($file->r2_staging_object_key);
            }

            [$module, $action, $message] = match ($stage) {
                'virus_scan' => ['security', 'virus_scan_failed', "Virus scanning failed for {$displayName}."],
                'file_compression' => ['upload', 'file_compression_failed', "Compression failed for {$displayName}."],
                'file_storage' => ['storage', 'file_storage_failed', "Saving {$displayName} to secure file storage failed."],
                'file_validation' => ['upload', 'file_validation_failed', "File validation failed for {$displayName}."],
                default => ['storage', 'file_retrieval_failed', "Retrieving {$displayName} from staging storage failed."],
            };
            $this->activity->record($module, $action, 'error', $message, null, $file->upload, null, $error);

            throw $error;
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);
        }
    }

    private function validate(UploadedFile $file, string $path): string
    {
        $size = filesize($path);
        $maxBytes = $this->settings->maxFileSizeKilobytes() * 1024;
        $allowedExtensions = (array) $this->settings->get('allowed_file_types');

        if ($size === false || $size < 1 || $size > $maxBytes || $size !== $file->original_file_size) {
            $file->validation_status = ValidationStatus::Invalid;
            throw new RuntimeException('The uploaded object size is empty, outside the limit, or differs from its declaration.');
        }

        if (! in_array($file->file_extension, $allowedExtensions, true)) {
            $file->validation_status = ValidationStatus::Invalid;
            throw new RuntimeException('This file extension is not allowed.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($mime) || ! in_array($mime, config('receiving.uploads.allowed_mime_types'), true)) {
            $file->validation_status = ValidationStatus::Invalid;
            throw new RuntimeException('The file content type is unsupported.');
        }

        $expectedMime = match ($file->file_extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => null,
        };
        if ($expectedMime !== $mime) {
            $file->validation_status = ValidationStatus::Invalid;
            throw new RuntimeException('The file extension does not match its detected content type.');
        }

        $head = file_get_contents($path, false, null, 0, 8);
        $signatureMatches = match ($mime) {
            'image/jpeg' => is_string($head) && str_starts_with($head, "\xFF\xD8\xFF"),
            'image/png' => $head === "\x89PNG\r\n\x1A\n",
            'application/pdf' => is_string($head) && str_starts_with($head, '%PDF-'),
        };

        if (! $signatureMatches) {
            $file->validation_status = ValidationStatus::Invalid;
            throw new RuntimeException('The file signature does not match its declared type.');
        }

        if (str_starts_with($mime, 'image/') && @getimagesize($path) === false) {
            $file->validation_status = ValidationStatus::Invalid;
            throw new RuntimeException('The image is corrupted or unreadable.');
        }

        return $mime;
    }

    /** @return array{string, CompressionStatus} */
    private function compress(string $source, string $output, string $mime): array
    {
        if (! (bool) $this->settings->get('compression_enabled') || $mime === 'application/pdf') {
            return [$source, CompressionStatus::Skipped];
        }

        $details = getimagesize($source);

        if (! is_array($details)) {
            throw new RuntimeException('Image compression failed because dimensions are unavailable.');
        }

        [$width, $height] = $details;
        if ($width * $height > 60_000_000) {
            throw new RuntimeException('Image dimensions exceed the safe decompression limit.');
        }

        $image = $mime === 'image/jpeg' ? @imagecreatefromjpeg($source) : @imagecreatefrompng($source);
        if (! $image) {
            throw new RuntimeException('Image compression failed during safe decode.');
        }

        $maxWidth = (int) $this->settings->get('max_image_width');
        $maxHeight = (int) $this->settings->get('max_image_height');
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $target) {
            imagedestroy($image);
            throw new RuntimeException('Image compression failed during output allocation.');
        }

        if ($mime === 'image/png') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }

        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $written = $mime === 'image/jpeg'
            ? imagejpeg($target, $output, (int) $this->settings->get('jpeg_quality'))
            : imagepng($target, $output, 6);
        imagedestroy($image);
        imagedestroy($target);

        if (! $written) {
            throw new RuntimeException('Image compression failed while writing the safe representation.');
        }

        return [$output, CompressionStatus::Compressed];
    }

    private function finalKey(UploadedFile $file): string
    {
        $upload = $file->upload;
        $date = $upload->created_at;

        return sprintf(
            'receiving/%s/%s/%s/%s/SN-%d/%s',
            $upload->uploadType->r2_prefix,
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            $upload->getKey(),
            $file->stored_file_name,
        );
    }
}
