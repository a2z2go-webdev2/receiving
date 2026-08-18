<?php

namespace App\Features\Receiving\Services;

use App\Enums\VirusScanStatus;
use App\Features\Receiving\Contracts\FileScanner;
use App\Features\Receiving\Data\FileScanResult;
use App\Features\Receiving\Exceptions\MalwareScanDeferred;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudmersiveFileScanner implements FileScanner
{
    public function __construct(private readonly CloudmersiveScanGuard $guard) {}

    public function scan(string $absolutePath): FileScanResult
    {
        $apiKey = (string) config('services.cloudmersive.key');
        $baseUrl = rtrim((string) config('services.cloudmersive.base_url'), '/');
        $maxBytes = max(
            1,
            (int) config('receiving.scanner.cloudmersive.max_file_kilobytes', 3584),
        ) * 1024;

        if ($apiKey === '' || ! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('Malware scanner configuration is incomplete.');
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Unable to open the staged file for malware scanning.');
        }

        $size = filesize($absolutePath);
        if ($size === false || $size < 1) {
            throw new RuntimeException('Unable to open the staged file for malware scanning.');
        }

        if ($size > $maxBytes) {
            throw new RuntimeException('The file exceeds the configured malware scanner size limit.');
        }

        return $this->guard->run(function () use ($absolutePath, $apiKey, $baseUrl): FileScanResult {
            $stream = fopen($absolutePath, 'rb');

            if (! is_resource($stream)) {
                throw new RuntimeException('Unable to open the staged file for malware scanning.');
            }

            try {
                $response = Http::acceptJson()
                    ->withHeaders(['Apikey' => $apiKey])
                    ->connectTimeout(max(1, (int) config('services.cloudmersive.connect_timeout_seconds', 10)))
                    ->timeout(max(1, (int) config('services.cloudmersive.timeout_seconds', 30)))
                    ->attach('inputFile', $stream, 'receiving-upload.bin')
                    ->post("{$baseUrl}/virus/scan/file");
            } catch (ConnectionException) {
                throw new RuntimeException('Malware scanner is temporarily unavailable.');
            } finally {
                fclose($stream);
            }

            return $this->resultFrom($response);
        });
    }

    private function resultFrom(Response $response): FileScanResult
    {
        if ($response->status() === 429) {
            throw new MalwareScanDeferred(
                'Malware scanning was rate limited by the provider and will retry automatically.',
                $this->retryAfterSeconds($response),
            );
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('Malware scanner credentials were rejected.');
        }

        if ($response->status() === 413) {
            throw new RuntimeException('The provider rejected the file because it exceeds the malware scanner plan limit.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Malware scanner returned an unsuccessful response.');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! array_key_exists('CleanResult', $payload) || ! is_bool($payload['CleanResult'])) {
            throw new RuntimeException('Malware scanner returned an invalid response.');
        }

        $foundViruses = $payload['FoundViruses'] ?? null;
        if ($foundViruses !== null && ! is_array($foundViruses)) {
            throw new RuntimeException('Malware scanner returned an invalid response.');
        }

        if ($payload['CleanResult'] === false) {
            return new FileScanResult(
                VirusScanStatus::Infected,
                'The malware scanner detected a threat in this file.',
            );
        }

        if (is_array($foundViruses) && $foundViruses !== []) {
            return new FileScanResult(
                VirusScanStatus::Suspicious,
                'The malware scanner returned inconsistent threat details.',
            );
        }

        return new FileScanResult(VirusScanStatus::Clean);
    }

    private function retryAfterSeconds(Response $response): int
    {
        $header = trim((string) $response->header('Retry-After'));

        if (ctype_digit($header)) {
            return min(3600, max(1, (int) $header));
        }

        if ($header !== '') {
            try {
                return min(3600, max(1, (int) now()->diffInSeconds(CarbonImmutable::parse($header))));
            } catch (\Throwable) {
                // Use the conservative default below for invalid provider headers.
            }
        }

        return max(1, (int) config('receiving.scanner.cloudmersive.rate_limit_retry_seconds', 60));
    }
}
