<?php

namespace App\Features\Receiving\Services;

use Illuminate\Support\Str;

class FileNameSanitizer
{
    public function sanitize(string $originalName): string
    {
        $name = str_replace(["\0", '/', '\\'], '-', $originalName);
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = Str::of($base)->ascii()->replaceMatches('/[^A-Za-z0-9._-]+/', '-')->trim('.-_')->limit(100, '')->value();
        $base = $base === '' ? 'document' : $base;

        return $extension === '' ? $base : "{$base}.{$extension}";
    }

    /**
     * Build the uniform name used for objects stored in R2.
     *
     * Pattern: {prefix}-SN{serial}-N{index}.{ext}
     * Example:  bonita-SN42-N1.pdf, a2z2go-SN100-N3.png
     */
    public function storedName(string $r2Prefix, int $serialNumber, int $fileIndex, string $extension): string
    {
        $prefix = Str::of($r2Prefix)->lower()->replaceMatches('/[^a-z0-9]+/', '')->value();
        $prefix = $prefix === '' ? 'upload' : $prefix;
        $ext = Str::lower(ltrim($extension, '.'));

        return $ext === ''
            ? sprintf('%s-SN%d-N%d', $prefix, $serialNumber, $fileIndex)
            : sprintf('%s-SN%d-N%d.%s', $prefix, $serialNumber, $fileIndex, $ext);
    }
}
