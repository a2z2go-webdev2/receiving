<?php

namespace App\Services\LegacyImport\Parsers;

class CsvTableParser
{
    /**
     * Parse CSV string content or file path into associative rows.
     *
     * @return array<int, array<string, string>>
     */
    public function parse(string $contentOrPath): array
    {
        $content = file_exists($contentOrPath)
            ? file_get_content_with_fallback($contentOrPath)
            : $contentOrPath;

        if (trim($content) === '') {
            return [];
        }

        $lines = str_getcsv($content, "\n");
        if (empty($lines)) {
            return [];
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $headers = [];
        $rows = [];
        $isFirst = true;

        while (($data = fgetcsv($stream)) !== false) {
            // Remove BOM if present on first item
            if (! empty($data)) {
                $data[0] = preg_replace('/^\x{EF}\x{BB}\x{BF}/u', '', $data[0]);
            }

            if (empty(array_filter($data, fn ($v) => trim((string) $v) !== ''))) {
                continue;
            }

            if ($isFirst) {
                $headers = array_map(fn ($h) => trim((string) $h), $data);
                $isFirst = false;

                continue;
            }

            $row = [];
            foreach ($headers as $idx => $header) {
                if ($header !== '') {
                    $row[$header] = isset($data[$idx]) ? trim((string) $data[$idx]) : '';
                }
            }

            if (! empty($row)) {
                $rows[] = $row;
            }
        }

        fclose($stream);

        return $rows;
    }
}

function file_get_content_with_fallback(string $path): string
{
    $content = file_get_contents($path);

    return $content !== false ? $content : '';
}
