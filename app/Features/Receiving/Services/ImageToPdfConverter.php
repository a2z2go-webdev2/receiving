<?php

namespace App\Features\Receiving\Services;

use RuntimeException;

/**
 * Converts raster image files (JPG, PNG) into single-page PDF documents using
 * only the GD extension. The resulting PDF embeds the image using DCTDecode
 * so the original JPEG bytes are reused for JPEG inputs (lossless) and PNG
 * inputs are re-encoded as JPEG before being embedded.
 */
class ImageToPdfConverter
{
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const POINTS_PER_INCH = 72.0;

    private const MAX_LONG_EDGE_INCHES = 17.0;

    public function isSupported(?string $extension): bool
    {
        return $extension !== null && in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Convert raw image bytes to a PDF byte stream without touching disk.
     */
    public function convertBytes(string $imageBytes): string
    {
        return $this->convertToBytes($imageBytes);
    }

    private function convertToBytes(string $bytes): string
    {
        $imageInfo = @getimagesizefromstring($bytes);
        if ($imageInfo === false) {
            throw new RuntimeException('Unsupported image: unable to determine dimensions.');
        }

        [$jpegBytes, $width, $height] = $this->prepareJpegPayload($bytes, $imageInfo);

        return $this->buildPdf($jpegBytes, $width, $height);
    }

    /**
     * @return array{0: string, 1: int, 2: int} JPEG bytes, width, height (pixels)
     */
    private function prepareJpegPayload(string $bytes, array $imageInfo): array
    {
        $mime = (string) ($imageInfo['mime'] ?? '');
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];

        if ($mime === 'image/jpeg') {
            return [$bytes, $width, $height];
        }

        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('GD extension is required to convert PNG attachments.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('Unable to decode image with GD.');
        }

        try {
            $jpeg = $this->reencodeAsJpeg($source, $width, $height);

            return [$jpeg, $width, $height];
        } finally {
            imagedestroy($source);
        }
    }

    private function reencodeAsJpeg(\GdImage $source, int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new RuntimeException('Unable to allocate GD canvas.');
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();

        imagedestroy($canvas);

        if ($jpeg === false || $jpeg === '') {
            throw new RuntimeException('Unable to encode image as JPEG.');
        }

        return $jpeg;
    }

    private function buildPdf(string $jpegBytes, int $pixelWidth, int $pixelHeight): string
    {
        [$pageWidth, $pageHeight] = $this->fitToPage($pixelWidth, $pixelHeight);

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>',
            $this->pdfNumber($pageWidth),
            $this->pdfNumber($pageHeight),
        );

        $imageHeader = sprintf(
            '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
            $pixelWidth,
            $pixelHeight,
            strlen($jpegBytes),
        );
        $imageObject = $imageHeader."\nstream\n".$jpegBytes."\nendstream";

        $contentStream = sprintf(
            "q\n%s 0 0 %s 0 0 cm\n/Im1 Do\nQ",
            $this->pdfNumber($pageWidth),
            $this->pdfNumber($pageHeight),
        );

        $objects[] = $imageObject;
        $objects[] = sprintf('<< /Length %d >>', strlen($contentStream))."\nstream\n".$contentStream."\nendstream";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

        $offsets = [0];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", count($objects) + 1);
        $pdf .= "0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            count($objects) + 1,
            $xrefOffset,
        );

        return $pdf;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function fitToPage(int $pixelWidth, int $pixelHeight): array
    {
        $longEdge = max($pixelWidth, $pixelHeight);
        $scale = $longEdge > 0
            ? min(1.0, (self::MAX_LONG_EDGE_INCHES * self::POINTS_PER_INCH) / $longEdge)
            : 1.0;

        return [
            round($pixelWidth * $scale, 2),
            round($pixelHeight * $scale, 2),
        ];
    }

    private function pdfNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }
}
