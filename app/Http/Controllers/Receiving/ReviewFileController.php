<?php

namespace App\Http\Controllers\Receiving;

use App\Features\Receiving\Services\ReviewLinkService;
use App\Http\Controllers\Controller;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReviewFileController extends Controller
{
    public function __invoke(string $token, UploadedFile $file, ReviewLinkService $links): StreamedResponse
    {
        $link = $links->resolve($token);
        abort_unless($link?->isUsable() && $file->receiving_upload_id === $link->receiving_upload_id, 404);
        $key = $file->resolvedR2ObjectKey();
        abort_if($key === null, 404);

        return Storage::disk((string) config('receiving.disk'))->response(
            $key,
            $file->sanitized_file_name,
            [
                'Content-Type' => $file->content_type,
                'Content-Disposition' => 'inline',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
