<?php

namespace App\Features\Receiving\Services;

use App\Models\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

class StagingUploadUrlFactory
{
    /**
     * @return array{
     *     url: string,
     *     headers: array<string, string>,
     *     fallback: array{url: string, headers: array<string, string>}|null
     * }
     */
    public function for(UploadedFile $file): array
    {
        $expiresAt = now()->addMinutes((int) config('receiving.uploads.staging_url_minutes', 15));
        $proxy = $this->proxyTarget($file, $expiresAt);

        // Operators can force the same-origin streaming path when an object
        // store cannot accept browser PUTs at all.
        if (config('receiving.proxy_uploads', false)) {
            return [...$proxy, 'fallback' => null];
        }

        try {
            /** @var array{url: string, headers: array<string, mixed>} $result */
            $result = Storage::disk((string) config('receiving.disk'))->temporaryUploadUrl(
                $file->r2_staging_object_key,
                $expiresAt,
                ['ContentType' => $file->declared_content_type],
            );

            $headers = [];
            foreach ($result['headers'] as $name => $value) {
                $headers[$name] = is_array($value)
                    ? implode(', ', array_map('strval', $value))
                    : (string) $value;
            }
            $headers['Content-Type'] = $file->declared_content_type;

            return [
                'url' => $result['url'],
                'headers' => $headers,
                'fallback' => $proxy,
            ];
        } catch (Throwable) {
            return [...$proxy, 'fallback' => null];
        }
    }

    /** @return array{url: string, headers: array<string, string>} */
    private function proxyTarget(UploadedFile $file, \DateTimeInterface $expiresAt): array
    {
        return [
            // A relative signature keeps the fallback same-origin even when
            // localhost and APP_URL use different loopback hostnames.
            'url' => URL::temporarySignedRoute(
                'receiving.staging.put',
                $expiresAt,
                ['file' => $file->getKey()],
                absolute: false,
            ),
            'headers' => ['Content-Type' => $file->declared_content_type],
        ];
    }
}
