<?php

namespace App\Services\LegacyImport\Parsers;

use DOMDocument;
use DOMXPath;

class HtmlTableParser
{
    /**
     * Parse HTML table content or file path into associative array rows.
     *
     * @return array<int, array<string, string>>
     */
    public function parse(string $contentOrPath): array
    {
        $content = file_exists($contentOrPath)
            ? file_get_contents($contentOrPath)
            : $contentOrPath;

        if ($content === false || trim($content) === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>'.$content, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return $this->fallbackRegexParse($content);
        }

        $xpath = new DOMXPath($doc);
        $trNodes = $xpath->query('//tr');

        if ($trNodes === false || $trNodes->length === 0) {
            return $this->fallbackRegexParse($content);
        }

        $rawRows = [];
        foreach ($trNodes as $tr) {
            $cells = [];
            $cellNodes = $xpath->query('./td | ./th', $tr);
            if ($cellNodes !== false) {
                foreach ($cellNodes as $cell) {
                    $text = html_entity_decode(trim($cell->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $cells[] = $text;
                }
            }
            if (! empty(array_filter($cells, fn ($c) => $c !== ''))) {
                $rawRows[] = $cells;
            }
        }

        return $this->extractHeaderAndRows($rawRows);
    }

    /**
     * Extract header row and convert data rows to associative array.
     *
     * @param  array<int, array<int, string>>  $rawRows
     * @return array<int, array<string, string>>
     */
    private function extractHeaderAndRows(array $rawRows): array
    {
        if (empty($rawRows)) {
            return [];
        }

        // Find header row: row containing known column titles (e.g. 'Serial Number', 'Timestamp', 'File Name', etc.)
        $headerIndex = -1;
        $headers = [];

        foreach ($rawRows as $idx => $row) {
            foreach ($row as $cell) {
                $c = strtolower(trim($cell));
                if (in_array($c, ['serial number', 'timestamp', 'file name', 'ai status', 'raw ai json', 'drive folder link'], true)) {
                    $headerIndex = $idx;
                    $headers = $row;

                    break 2;
                }
            }
        }

        if ($headerIndex === -1) {
            // Assume row 1 or row 0 is header
            $headerIndex = 0;
            $headers = $rawRows[0];
        }

        $result = [];
        for ($i = $headerIndex + 1; $i < count($rawRows); $i++) {
            $dataRow = $rawRows[$i];
            $rowMap = [];
            foreach ($headers as $colIdx => $colName) {
                $colName = trim($colName);
                if ($colName !== '' && ! is_numeric($colName) && strlen($colName) > 1) {
                    $rowMap[$colName] = isset($dataRow[$colIdx]) ? trim($dataRow[$colIdx]) : '';
                }
            }
            if (! empty($rowMap) && $this->rowHasValue($rowMap)) {
                $result[] = $rowMap;
            }
        }

        return $result;
    }

    /**
     * Check if row contains non-empty value.
     *
     * @param  array<string, string>  $rowMap
     */
    private function rowHasValue(array $rowMap): bool
    {
        foreach ($rowMap as $key => $val) {
            if ($val !== '' && strtolower($key) !== 'serial number') {
                return true;
            }
        }

        return false;
    }

    /**
     * Regex fallback parser if DOMDocument fails.
     *
     * @return array<int, array<string, string>>
     */
    private function fallbackRegexParse(string $html): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $trMatches);
        if (empty($trMatches[1])) {
            return [];
        }

        $rawRows = [];
        foreach ($trMatches[1] as $trContent) {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $trContent, $cellMatches);
            $cells = [];
            if (! empty($cellMatches[1])) {
                foreach ($cellMatches[1] as $c) {
                    $text = strip_tags($c);
                    $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $cells[] = $text;
                }
            }
            if (! empty($cells)) {
                $rawRows[] = $cells;
            }
        }

        return $this->extractHeaderAndRows($rawRows);
    }
}
