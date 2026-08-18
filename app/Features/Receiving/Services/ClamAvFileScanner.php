<?php

namespace App\Features\Receiving\Services;

use App\Enums\VirusScanStatus;
use App\Features\Receiving\Contracts\FileScanner;
use App\Features\Receiving\Data\FileScanResult;
use RuntimeException;

class ClamAvFileScanner implements FileScanner
{
    public function scan(string $absolutePath): FileScanResult
    {
        $host = (string) config('receiving.scanner.host');
        $port = (int) config('receiving.scanner.port');
        $timeout = (int) config('receiving.scanner.timeout_seconds');
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, $timeout);

        if (! is_resource($socket)) {
            throw new RuntimeException("Malware scanner unavailable ({$errorCode}): {$errorMessage}");
        }

        $file = fopen($absolutePath, 'rb');

        if (! is_resource($file)) {
            fclose($socket);
            throw new RuntimeException('Unable to open the staged file for malware scanning.');
        }

        stream_set_timeout($socket, $timeout);
        fwrite($socket, "zINSTREAM\0");

        while (! feof($file)) {
            $chunk = fread($file, 8192);

            if ($chunk === false) {
                fclose($file);
                fclose($socket);
                throw new RuntimeException('Unable to read the staged file during malware scanning.');
            }

            if ($chunk !== '') {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
        }

        fwrite($socket, pack('N', 0));
        $response = stream_get_contents($socket);
        fclose($file);
        fclose($socket);

        if (! is_string($response) || $response === '') {
            throw new RuntimeException('Malware scanner returned no result.');
        }

        if (str_contains($response, ' FOUND')) {
            return new FileScanResult(VirusScanStatus::Infected, trim($response));
        }

        if (str_contains($response, ' OK')) {
            return new FileScanResult(VirusScanStatus::Clean);
        }

        return new FileScanResult(VirusScanStatus::Suspicious, trim($response));
    }
}
