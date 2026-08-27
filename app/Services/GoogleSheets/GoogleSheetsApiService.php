<?php

namespace App\Services\GoogleSheets;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleSheetsApiService
{
    /**
     * Extract clean spreadsheet ID from full URL or raw ID.
     */
    public function extractSpreadsheetId(?string $input): string
    {
        if (! $input) {
            return '';
        }

        $trimmed = trim($input);
        if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $trimmed, $matches)) {
            return $matches[1];
        }

        return $trimmed;
    }

    /**
     * Fetch values from a specific sheet range using Google Sheets API v4.
     * Supports both Google Service Account (OAuth access token or API Key from env).
     *
     * @return array<int, array<int, mixed>>
     */
    public function fetchRange(string $spreadsheetId, string $range): array
    {
        $cleanId = $this->extractSpreadsheetId($spreadsheetId);
        if ($cleanId === '') {
            throw new RuntimeException('Spreadsheet ID is missing or empty.');
        }

        $apiKey = config('services.google.sheets_api_key');
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$cleanId}/values/".rawurlencode($range);

        $params = [];
        if ($apiKey) {
            $params['key'] = $apiKey;
        }

        $headers = [];
        $token = $this->resolveAccessToken();
        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->get($url, $params);

        if (! $response->successful()) {
            $errorMsg = $response->json('error.message') ?? $response->body();
            Log::error("Google Sheets API error on {$range}: {$errorMsg}");
            throw new RuntimeException("Google Sheets API error on '{$range}': {$errorMsg}");
        }

        return $response->json('values') ?? [];
    }

    /**
     * Fetch all 3 tabs (Receiving_Log, receive_files, ai_extraction) for a given spreadsheet.
     *
     * @return array{logs: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>, extractions: array<int, array<string, mixed>>}
     */
    public function fetchAllTabs(string $spreadsheetId): array
    {
        $cleanId = $this->extractSpreadsheetId($spreadsheetId);
        if ($cleanId === '') {
            throw new RuntimeException('Spreadsheet ID is not configured.');
        }

        // Fetch Receiving_Log
        $rawLogs = $this->fetchRange($cleanId, 'Receiving_Log!A1:Z2000');
        // Fetch receive_files
        $rawFiles = $this->fetchRange($cleanId, 'receive_files!A1:Z5000');
        // Fetch ai_extraction
        $rawExtractions = $this->fetchRange($cleanId, 'ai_extraction!A1:Z2000');

        return [
            'logs' => $this->mapRows($rawLogs),
            'files' => $this->mapFileRows($rawFiles),
            'extractions' => $this->mapExtractionRows($rawExtractions),
        ];
    }

    /**
     * Map raw row matrices into key-value associative arrays by headers.
     *
     * @param  array<int, array<int, mixed>>  $rawValues
     * @return array<int, array<string, mixed>>
     */
    public function mapRows(array $rawValues): array
    {
        if (count($rawValues) < 2) {
            return [];
        }

        $headers = array_map(fn ($h) => trim(preg_replace('/\[\d+\]$/', '', (string) ($h ?? ''))), $rawValues[0]);
        $result = [];

        for ($i = 1; $i < count($rawValues); $i++) {
            $row = $rawValues[$i];
            if (empty($row) || array_filter($row, fn ($c) => $c !== '' && $c !== null) === []) {
                continue;
            }

            $obj = ['_rowIndex' => $i + 1];
            foreach ($headers as $idx => $h) {
                if ($h !== '') {
                    $obj[$h] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
                }
            }

            $result[] = $obj;
        }

        return $result;
    }

    /**
     * Map raw rows from receive_files tab.
     *
     * @param  array<int, array<int, mixed>>  $rawValues
     * @return array<int, array<string, mixed>>
     */
    public function mapFileRows(array $rawValues): array
    {
        if (count($rawValues) < 2) {
            return [];
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) ($h ?? ''))), $rawValues[0]);

        $snCol = $this->findHeaderIndex($headers, ['serial number', 'serial_number', 'sn']);
        $noCol = $this->findHeaderIndex($headers, ['file no.', 'file no', 'file_no']);
        $nameCol = $this->findHeaderIndex($headers, ['file name', 'file_name', 'name']);
        $idCol = $this->findHeaderIndex($headers, ['file id', 'file_id', 'id']);
        $urlCol = $this->findHeaderIndex($headers, ['file url', 'file_url', 'drive link', 'drive url', 'url']);
        $mimeCol = $this->findHeaderIndex($headers, ['mime type', 'mime_type', 'mime', 'type']);
        $r2Col = $this->findHeaderIndex($headers, ['r2 cloudflare url', 'r2 url', 'cloudflare url', 'r2']);

        $result = [];

        for ($i = 1; $i < count($rawValues); $i++) {
            $row = $rawValues[$i];
            if (empty($row) || array_filter($row, fn ($c) => $c !== '' && $c !== null) === []) {
                continue;
            }

            $rawSn = $snCol !== -1 ? ($row[$snCol] ?? null) : ($row[0] ?? null);
            $sn = (int) preg_replace('/[^\d]/', '', (string) ($rawSn ?? ''));

            $fileNo = $noCol !== -1 ? ($row[$noCol] ?? '') : '';
            $fileName = $nameCol !== -1 ? ($row[$nameCol] ?? '') : ($row[2] ?? '');
            $fileId = $idCol !== -1 ? ($row[$idCol] ?? '') : ($row[3] ?? '');
            $fileUrl = $urlCol !== -1 ? ($row[$urlCol] ?? '') : ($row[4] ?? '');
            $mimeType = $mimeCol !== -1 ? ($row[$mimeCol] ?? 'image/jpeg') : ($row[5] ?? 'image/jpeg');
            $r2Url = $r2Col !== -1 ? ($row[$r2Col] ?? '') : ($row[6] ?? '');

            if ($sn > 0 && ($fileName !== '' || $fileId !== '')) {
                $result[] = [
                    '_rowIndex' => $i + 1,
                    'serial_number' => $sn,
                    'file_no' => trim((string) $fileNo),
                    'file_name' => trim((string) $fileName),
                    'file_id' => trim((string) $fileId),
                    'file_url' => trim((string) $fileUrl),
                    'mime_type' => trim((string) $mimeType) ?: 'image/jpeg',
                    'r2_url' => trim((string) $r2Url),
                ];
            }
        }

        return $result;
    }

    /**
     * Map raw rows from ai_extraction tab.
     *
     * @param  array<int, array<int, mixed>>  $rawValues
     * @return array<int, array<string, mixed>>
     */
    public function mapExtractionRows(array $rawValues): array
    {
        if (count($rawValues) < 2) {
            return [];
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) ($h ?? ''))), $rawValues[0]);

        $snCol = $this->findHeaderIndex($headers, ['serial number', 'serial_number', 'sn']);
        $statusCol = $this->findHeaderIndex($headers, ['ai status', 'ai_status', 'status']);
        $rawJsonCol = $this->findHeaderIndex($headers, ['raw ai json', 'raw_ai_json', 'raw json']);
        $corrJsonCol = $this->findHeaderIndex($headers, ['corrected json', 'corrected_json']);
        $extractedCol = $this->findHeaderIndex($headers, ['extracted at', 'extracted_at']);
        $errorCol = $this->findHeaderIndex($headers, ['error message', 'error_message', 'error']);

        $result = [];

        for ($i = 1; $i < count($rawValues); $i++) {
            $row = $rawValues[$i];
            if (empty($row) || array_filter($row, fn ($c) => $c !== '' && $c !== null) === []) {
                continue;
            }

            $rawSn = $snCol !== -1 ? ($row[$snCol] ?? null) : ($row[0] ?? null);
            $sn = (int) preg_replace('/[^\d]/', '', (string) ($rawSn ?? ''));

            if ($sn > 0) {
                $result[] = [
                    'serial_number' => $sn,
                    'ai_status' => $statusCol !== -1 ? trim((string) ($row[$statusCol] ?? '')) : '',
                    'raw_ai_json' => $rawJsonCol !== -1 ? trim((string) ($row[$rawJsonCol] ?? '')) : '',
                    'corrected_json' => $corrJsonCol !== -1 ? trim((string) ($row[$corrJsonCol] ?? '')) : '',
                    'extracted_at' => $extractedCol !== -1 ? trim((string) ($row[$extractedCol] ?? '')) : '',
                    'error_message' => $errorCol !== -1 ? trim((string) ($row[$errorCol] ?? '')) : '',
                ];
            }
        }

        return $result;
    }

    private function findHeaderIndex(array $headers, array $candidates): int
    {
        foreach ($headers as $idx => $h) {
            foreach ($candidates as $cand) {
                if (str_contains(strtolower((string) $h), strtolower((string) $cand))) {
                    return $idx;
                }
            }
        }

        return -1;
    }

    /**
     * Resolve Google OAuth2 Bearer access token from Service Account JSON if provided.
     */
    private function resolveAccessToken(): ?string
    {
        $serviceAccountJson = config('services.google.service_account_json');
        if (! $serviceAccountJson) {
            return null;
        }

        try {
            $credentials = json_decode(trim($serviceAccountJson), true);
            if (! is_array($credentials) && file_exists($serviceAccountJson)) {
                $credentials = json_decode(file_get_contents($serviceAccountJson), true);
            }

            if (! isset($credentials['client_email'], $credentials['private_key'])) {
                return null;
            }

            $cacheKey = 'google_sheets_token_'.md5($credentials['client_email']);
            if ($cached = cache()->get($cacheKey)) {
                return (string) $cached;
            }

            $now = time();
            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = base64_encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]));

            $signingInput = "{$header}.{$claim}";
            $signature = '';
            openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = "{$signingInput}.".base64_encode($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful() && $token = $response->json('access_token')) {
                cache()->put($cacheKey, $token, now()->addMinutes(50));

                return $token;
            }

            Log::error('Google Service Account token exchange error: '.$response->body());
        } catch (\Throwable $e) {
            Log::error('Google Service Account authentication exception: '.$e->getMessage());
        }

        return null;
    }
}
