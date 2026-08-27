<?php

namespace App\Services\GoogleSheets;

class GoogleSheetsTableParser
{
    /**
     * Parse raw string content (HTML table or CSV/TSV) or file path into structured array.
     *
     * @return array{logs: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>, extractions: array<int, array<string, mixed>>}
     */
    public function parse(string $contentOrPath): array
    {
        $raw = trim($contentOrPath);
        if ($raw === '') {
            return ['logs' => [], 'files' => [], 'extractions' => []];
        }

        if (file_exists($raw)) {
            $raw = (string) file_get_contents($raw);
        }

        $trimmed = trim($raw);
        if (str_contains(strtolower($trimmed), '<table') || str_contains(strtolower($trimmed), '<tr')) {
            return $this->parseHtml($trimmed);
        }

        return $this->parseCsv($trimmed);
    }

    /**
     * Parse HTML table content.
     *
     * @return array{logs: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>, extractions: array<int, array<string, mixed>>}
     */
    public function parseHtml(string $html): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $trMatches);
        $rawRows = [];

        foreach ($trMatches[1] as $tr) {
            preg_match_all('/<(td|th)[^>]*>(.*?)<\/\1>/is', $tr, $cellMatches);
            $cells = array_map(function (string $c): string {
                $text = html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = str_replace("\xc2\xa0", ' ', $text); // Non-breaking space

                return trim(preg_replace('/\s+/', ' ', $text) ?? '');
            }, $cellMatches[2]);

            if (array_filter($cells, fn ($c) => $c !== '') !== []) {
                $rawRows[] = $cells;
            }
        }

        if (count($rawRows) < 2) {
            return ['logs' => [], 'files' => [], 'extractions' => []];
        }

        // Detect header row
        $headerIdx = 0;
        foreach ($rawRows as $i => $row) {
            $lowerRow = array_map(fn ($c) => strtolower(trim((string) $c)), $row);
            if ($this->arrayContainsAny($lowerRow, ['serial number', 'file name', 'file id', 'ai status', 'raw ai json'])) {
                $headerIdx = $i;
                break;
            }
        }

        $headers = $rawRows[$headerIdx];
        $isFilesSheet = $this->arrayContainsAny(array_map('strtolower', $headers), ['file name', 'file id', 'file url', 'r2 cloudflare url', 'mime type']);
        $isExtractionsSheet = $this->arrayContainsAny(array_map('strtolower', $headers), ['raw ai json', 'corrected json', 'extracted at']);
        $isLogsSheet = $this->arrayContainsAny(array_map('strtolower', $headers), ['timestamp', 'upload timestamp', 'drive folder link', 'review token', 'uploader location', 'file count']);

        $logs = [];
        $files = [];
        $extractions = [];

        for ($i = $headerIdx + 1; $i < count($rawRows); $i++) {
            $row = $rawRows[$i];
            $obj = ['_rowIndex' => $i + 1];

            foreach ($headers as $colIdx => $headerName) {
                $cleanHeader = trim((string) $headerName);
                if ($cleanHeader !== '') {
                    // Remove annotation suffixes like [1], [2] from Excel exports
                    $cleanHeader = preg_replace('/\[\d+\]$/', '', $cleanHeader);
                    $obj[trim((string) $cleanHeader)] = $row[$colIdx] ?? '';
                }
            }

            $snRaw = $this->getCaseInsensitive($obj, ['serial number', 'serial_number', 'sn']);
            $sn = (int) preg_replace('/[^\d]/', '', $snRaw);

            if ($sn > 0) {
                if ($isFilesSheet) {
                    $files[] = [
                        '_rowIndex' => $i + 1,
                        'serial_number' => $sn,
                        'file_no' => $this->getCaseInsensitive($obj, ['file no.', 'file no', 'file_no']),
                        'file_name' => $this->getCaseInsensitive($obj, ['file name', 'file_name']),
                        'file_id' => $this->getCaseInsensitive($obj, ['file id', 'file_id']),
                        'file_url' => $this->getCaseInsensitive($obj, ['file url', 'file_url', 'drive url', 'drive link']),
                        'mime_type' => $this->getCaseInsensitive($obj, ['mime type', 'mime_type', 'mime'], 'image/jpeg'),
                        'r2_url' => $this->getCaseInsensitive($obj, ['r2 cloudflare url', 'r2 url', 'cloudflare url']),
                    ];
                } elseif ($isExtractionsSheet) {
                    $extractions[] = [
                        'serial_number' => $sn,
                        'ai_status' => $this->getCaseInsensitive($obj, ['ai status', 'ai_status']),
                        'raw_ai_json' => $this->getCaseInsensitive($obj, ['raw ai json', 'raw_ai_json']),
                        'corrected_json' => $this->getCaseInsensitive($obj, ['corrected json', 'corrected_json']),
                        'extracted_at' => $this->getCaseInsensitive($obj, ['extracted at', 'extracted_at']),
                        'error_message' => $this->getCaseInsensitive($obj, ['error message', 'error_message']),
                    ];
                } else {
                    $logs[] = $obj;
                }
            }
        }

        return ['logs' => $logs, 'files' => $files, 'extractions' => $extractions];
    }

    /**
     * Parse CSV or TSV content.
     *
     * @return array{logs: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>, extractions: array<int, array<string, mixed>>}
     */
    public function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = array_filter($lines, fn ($l) => trim($l) !== '');

        if (count($lines) < 2) {
            return ['logs' => [], 'files' => [], 'extractions' => []];
        }

        $delimiter = str_contains($lines[0], "\t") ? "\t" : ',';
        $rows = array_map(fn ($l) => str_getcsv($l, $delimiter), $lines);

        $headers = array_map(function ($h) {
            $clean = trim((string) $h);

            return preg_replace('/\[\d+\]$/', '', $clean);
        }, $rows[0]);

        $isFilesSheet = $this->arrayContainsAny(array_map('strtolower', $headers), ['file name', 'file id', 'r2 cloudflare url']);
        $isExtractionsSheet = $this->arrayContainsAny(array_map('strtolower', $headers), ['raw ai json', 'corrected json']);

        $logs = [];
        $files = [];
        $extractions = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $obj = ['_rowIndex' => $i + 1];

            foreach ($headers as $colIdx => $h) {
                if ($h !== '') {
                    $obj[$h] = $row[$colIdx] ?? '';
                }
            }

            $snRaw = $this->getCaseInsensitive($obj, ['serial number', 'serial_number', 'sn']);
            $sn = (int) preg_replace('/[^\d]/', '', $snRaw);

            if ($sn > 0) {
                if ($isFilesSheet) {
                    $files[] = [
                        '_rowIndex' => $i + 1,
                        'serial_number' => $sn,
                        'file_no' => $this->getCaseInsensitive($obj, ['file no.', 'file no', 'file_no']),
                        'file_name' => $this->getCaseInsensitive($obj, ['file name', 'file_name']),
                        'file_id' => $this->getCaseInsensitive($obj, ['file id', 'file_id']),
                        'file_url' => $this->getCaseInsensitive($obj, ['file url', 'file_url']),
                        'mime_type' => $this->getCaseInsensitive($obj, ['mime type', 'mime_type'], 'image/jpeg'),
                        'r2_url' => $this->getCaseInsensitive($obj, ['r2 cloudflare url', 'r2 url']),
                    ];
                } elseif ($isExtractionsSheet) {
                    $extractions[] = [
                        'serial_number' => $sn,
                        'ai_status' => $this->getCaseInsensitive($obj, ['ai status', 'ai_status']),
                        'raw_ai_json' => $this->getCaseInsensitive($obj, ['raw ai json', 'raw_ai_json']),
                        'corrected_json' => $this->getCaseInsensitive($obj, ['corrected json', 'corrected_json']),
                        'extracted_at' => $this->getCaseInsensitive($obj, ['extracted at', 'extracted_at']),
                        'error_message' => $this->getCaseInsensitive($obj, ['error message', 'error_message']),
                    ];
                } else {
                    $logs[] = $obj;
                }
            }
        }

        return ['logs' => $logs, 'files' => $files, 'extractions' => $extractions];
    }

    public function getCaseInsensitive(array $arr, array $keys, string $default = ''): string
    {
        foreach ($arr as $k => $v) {
            $cleanK = strtolower(trim((string) $k));
            foreach ($keys as $target) {
                if ($cleanK === strtolower($target)) {
                    return trim((string) $v);
                }
            }
        }

        return $default;
    }

    private function arrayContainsAny(array $haystack, array $needles): bool
    {
        foreach ($haystack as $item) {
            foreach ($needles as $needle) {
                if (str_contains(strtolower((string) $item), strtolower($needle))) {
                    return true;
                }
            }
        }

        return false;
    }
}
