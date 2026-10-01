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
        $params = [];
        if ($apiKey) {
            $params['key'] = $apiKey;
        }

        $headers = [];
        $token = $this->resolveAccessToken();
        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        // Build list of candidate URL path formats to try if "Unable to parse range" is encountered.
        // Google Sheets API v4 range parser expects delimiters ' ! : to be literal in path,
        // with inner sheet name spaces encoded as %20. Full rawurlencode can turn ! into %21 causing parse errors.
        $candidates = $this->buildCandidateUrlPaths($range);

        $lastError = null;
        foreach ($candidates as $candidatePath) {
            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$cleanId}/values/{$candidatePath}";

            $response = Http::withHeaders($headers)
                ->timeout(6)
                ->get($url, $params);

            if ($response->successful()) {
                if ($candidatePath !== $candidates[0]) {
                    Log::info("Google Sheets range fallback succeeded with format '{$candidatePath}' (original: '{$range}')");
                }

                return $response->json('values') ?? [];
            }

            $errorMsg = $response->json('error.message') ?? $response->body();
            $lastError = "Google Sheets API error on '{$candidatePath}': {$errorMsg}";

            // If not a range parsing error (e.g. auth 401, permission 403, not found 404), fail fast
            if (! str_contains($errorMsg, 'Unable to parse range')) {
                Log::error($lastError);
                throw new RuntimeException($lastError);
            }

            Log::warning("Google Sheets range attempt '{$candidatePath}' failed ({$errorMsg}), trying next candidate...");
        }

        Log::error($lastError);
        throw new RuntimeException($lastError);
    }

    /**
     * Build list of candidate URL path formats for a given range in Google Sheets API v4.
     *
     * @return array<int, string>
     */
    public function buildCandidateUrlPaths(string $range): array
    {
        $candidates = [];

        if (str_contains($range, '!')) {
            [$tabPart, $cellPart] = explode('!', $range, 2);
            $cleanTab = trim($tabPart, "'\"");
            $escapedTab = str_replace("'", "''", $cleanTab);
            $encodedTab = rawurlencode($escapedTab);

            // 1. Quoted sheet with spaces encoded as %20, literal ! and : (Standard RFC / Google Sheets REST)
            $candidates[] = "'{$encodedTab}'!{$cellPart}";
            // 2. Quoted sheet alone (Google Sheets returns entire sheet data)
            $candidates[] = "'{$encodedTab}'";
            // 3. Unquoted sheet with coordinates
            $candidates[] = "{$encodedTab}!{$cellPart}";
            // 4. Unquoted sheet alone
            $candidates[] = $encodedTab;
        } else {
            $clean = trim($range, "'\"");
            $escaped = str_replace("'", "''", $clean);
            $encoded = rawurlencode($escaped);

            // 1. Quoted sheet with A:Z coordinates
            $candidates[] = "'{$encoded}'!A:Z";
            // 2. Quoted sheet name
            $candidates[] = "'{$encoded}'";
            // 3. Unquoted sheet name
            $candidates[] = $encoded;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Fetch list of sheet/tab titles from a spreadsheet.
     *
     * @return array<int, string>
     */
    public function fetchSpreadsheetTabs(string $spreadsheetId): array
    {
        $cleanId = $this->extractSpreadsheetId($spreadsheetId);
        if ($cleanId === '') {
            throw new RuntimeException('Spreadsheet ID is missing or empty.');
        }

        $apiKey = config('services.google.sheets_api_key');
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$cleanId}";

        $headers = [];
        $token = $this->resolveAccessToken();
        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        // Try with field mask first, fall back to full spreadsheet metadata if needed
        $paramOptions = [
            ['fields' => 'sheets(properties(sheetId,title))'],
            [],
        ];

        $lastError = null;
        foreach ($paramOptions as $params) {
            if ($apiKey) {
                $params['key'] = $apiKey;
            }

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->get($url, $params);

            if ($response->successful()) {
                $sheets = $response->json('sheets') ?? [];

                return array_values(array_filter(array_map(
                    fn ($s) => isset($s['properties']['title']) ? (string) $s['properties']['title'] : null,
                    $sheets
                )));
            }

            $lastError = $response->json('error.message') ?? $response->body();
        }

        Log::error("Google Sheets API error on spreadsheet {$cleanId}: {$lastError}");
        throw new RuntimeException("Google Sheets API error on spreadsheet '{$cleanId}': {$lastError}");
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

        $availableTabs = null;
        try {
            $availableTabs = $this->fetchSpreadsheetTabs($cleanId);
        } catch (\Throwable $e) {
            Log::info("Could not fetch spreadsheet tab metadata for {$cleanId}: {$e->getMessage()}");
        }

        $logTab = 'Receiving_Log';
        $filesTab = 'receive_files';
        $extractionsTab = 'ai_extraction';

        if (! empty($availableTabs)) {
            $matchedLogTab = $this->resolveReceivingTabName('Receiving_Log', $availableTabs);
            if ($matchedLogTab !== null) {
                $logTab = $matchedLogTab;
            } else {
                $isPoSheet = collect($availableTabs)->contains(
                    fn ($t): bool => str_contains(strtolower((string) $t), 'purchase order') || str_contains(strtolower((string) $t), 'po')
                );

                $tabListStr = implode("', '", array_slice($availableTabs, 0, 6));
                if ($isPoSheet) {
                    throw new RuntimeException("Spreadsheet '{$cleanId}' does not contain Receiving Log tabs. It appears to be a Purchase Orders sheet (found tabs: '{$tabListStr}'). In Sheet Settings, please ensure the Pingcon Spreadsheet ID points to the Pingcon Receiving Log spreadsheet (expected tab: Receiving_Log).");
                }

                throw new RuntimeException("Unable to find the 'Receiving_Log' tab in spreadsheet '{$cleanId}'. Available tabs in this spreadsheet are: ['{$tabListStr}']. Please ensure the tab is named 'Receiving_Log' or verify the Spreadsheet ID in Settings.");
            }

            $filesTab = $this->resolveReceivingTabName('receive_files', $availableTabs);
            $extractionsTab = $this->resolveReceivingTabName('ai_extraction', $availableTabs);
        }

        // Fetch Receiving_Log
        $rawLogs = $this->fetchRange($cleanId, "'{$logTab}'!A1:Z2000");

        // Fetch receive_files (gracefully fallback to empty array if tab is missing or unparseable)
        $rawFiles = [];
        if ($filesTab !== null) {
            try {
                $rawFiles = $this->fetchRange($cleanId, "'{$filesTab}'!A1:Z5000");
            } catch (\Throwable $e) {
                Log::warning("Could not fetch receive_files tab '{$filesTab}': {$e->getMessage()}");
            }
        }

        // Fetch ai_extraction (gracefully fallback to empty array if tab is missing or unparseable)
        $rawExtractions = [];
        if ($extractionsTab !== null) {
            try {
                $rawExtractions = $this->fetchRange($cleanId, "'{$extractionsTab}'!A1:Z2000");
            } catch (\Throwable $e) {
                Log::warning("Could not fetch ai_extraction tab '{$extractionsTab}': {$e->getMessage()}");
            }
        }

        return [
            'logs' => $this->mapRows($rawLogs),
            'files' => $this->mapFileRows($rawFiles),
            'extractions' => $this->mapExtractionRows($rawExtractions),
        ];
    }

    /**
     * Resolve a flexible match for receiving tabs from available sheet titles.
     *
     * @param  array<int, string>  $availableTabs
     */
    public function resolveReceivingTabName(string $type, array $availableTabs): ?string
    {
        // 1. Exact match
        foreach ($availableTabs as $tab) {
            if ($tab === $type) {
                return $tab;
            }
        }

        // 2. Case-insensitive trimmed match
        $lowerType = strtolower(trim($type));
        foreach ($availableTabs as $tab) {
            if (strtolower(trim($tab)) === $lowerType) {
                return $tab;
            }
        }

        // 3. Alphanumeric match (ignoring spaces, underscores, hyphens)
        $alphaType = (string) preg_replace('/[^a-z0-9]/', '', $lowerType);
        foreach ($availableTabs as $tab) {
            $alphaTab = (string) preg_replace('/[^a-z0-9]/', '', strtolower($tab));
            if ($alphaTab === $alphaType) {
                return $tab;
            }
        }

        // 4. Common synonyms
        $synonyms = match ($type) {
            'Receiving_Log' => ['receiving_log', 'receiving log', 'receive_log', 'receive log', 'receivinglog', 'receivelog', 'receiving'],
            'receive_files' => ['receive_files', 'receive files', 'receive_file', 'receive file', 'received_files', 'received files', 'files'],
            'ai_extraction' => ['ai_extraction', 'ai extraction', 'ai_extractions', 'ai extractions', 'extractions', 'extraction'],
            default => [],
        };

        foreach ($synonyms as $synonym) {
            $alphaSyn = (string) preg_replace('/[^a-z0-9]/', '', $synonym);
            foreach ($availableTabs as $tab) {
                $alphaTab = (string) preg_replace('/[^a-z0-9]/', '', strtolower($tab));
                if ($alphaTab === $alphaSyn) {
                    return $tab;
                }
            }
        }

        return null;
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
