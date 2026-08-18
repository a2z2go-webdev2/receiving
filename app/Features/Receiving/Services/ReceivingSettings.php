<?php

namespace App\Features\Receiving\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class ReceivingSettings
{
    /** @var array<string, array{default: mixed, type: string}> */
    private const DEFINITIONS = [
        'otp_expiration_minutes' => ['default' => 5, 'type' => 'integer'],
        'review_link_expiration_hours' => ['default' => 24, 'type' => 'integer'],
        'max_file_size_kilobytes' => ['default' => 15360, 'type' => 'integer'],
        'max_files_per_upload' => ['default' => 20, 'type' => 'integer'],
        'allowed_file_types' => ['default' => ['jpg', 'jpeg', 'png', 'pdf'], 'type' => 'array'],
        'compression_enabled' => ['default' => true, 'type' => 'boolean'],
        'max_image_width' => ['default' => 2400, 'type' => 'integer'],
        'max_image_height' => ['default' => 2400, 'type' => 'integer'],
        'jpeg_quality' => ['default' => 85, 'type' => 'integer'],
        'ai_batch_size' => ['default' => 1, 'type' => 'integer'],
        'ai_retry_limit' => ['default' => 3, 'type' => 'integer'],
        'ai_retry_backoff_seconds' => ['default' => 60, 'type' => 'integer'],
        'email_attachments_enabled' => ['default' => false, 'type' => 'boolean'],
        'review_recipient_rule' => ['default' => 'uploader', 'type' => 'string'],
        'staging_cleanup_hours' => ['default' => 24, 'type' => 'integer'],
        'signed_url_expiration_minutes' => ['default' => 30, 'type' => 'integer'],
    ];

    public function get(string $key): mixed
    {
        $definition = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");

        return Cache::remember("receiving.setting.{$key}", 300, function () use ($key, $definition): mixed {
            $stored = SystemSetting::query()->where('key', $key)->value('value');

            if ($stored === null) {
                return $this->configDefault($key, $definition['default']);
            }

            $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

            return is_array($decoded) && array_key_exists('value', $decoded)
                ? $decoded['value']
                : $definition['default'];
        });
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return collect(array_keys(self::DEFINITIONS))
            ->mapWithKeys(fn (string $key): array => [$key => $this->get($key)])
            ->all();
    }

    public function maxFileSizeKilobytes(): int
    {
        $configuredLimit = max(1, (int) $this->get('max_file_size_kilobytes'));

        if (config('receiving.scanner.driver') !== 'cloudmersive') {
            return $configuredLimit;
        }

        return min(
            $configuredLimit,
            max(1, (int) config('receiving.scanner.cloudmersive.max_file_kilobytes', 3584)),
        );
    }

    /** @param array<string, mixed> $values */
    public function update(array $values, User $actor): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::DEFINITIONS[$key])) {
                throw new InvalidArgumentException("Unknown setting [{$key}].");
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value], 'updated_by' => $actor->getKey()],
            );
            Cache::forget("receiving.setting.{$key}");
        }
    }

    /** @return array<string, bool> */
    public function secretReadiness(): array
    {
        return [
            'r2_credentials' => filled(config('filesystems.disks.r2.key'))
                && filled(config('filesystems.disks.r2.secret'))
                && filled(config('filesystems.disks.r2.endpoint')),
            'gemini_api_key' => filled(config('services.gemini.key'))
                && filled(config('services.gemini.model')),
            'email_credentials' => config('mail.default') === 'log'
                || config('mail.default') === 'array'
                || filled(config('mail.mailers.smtp.host')),
            'malware_scanner' => match (config('receiving.scanner.driver')) {
                'cloudmersive' => filled(config('services.cloudmersive.key'))
                    && str_starts_with((string) config('services.cloudmersive.base_url'), 'https://'),
                'clamav' => filled(config('receiving.scanner.host')),
                default => false,
            },
        ];
    }

    private function configDefault(string $key, mixed $fallback): mixed
    {
        return match ($key) {
            'otp_expiration_minutes' => config('receiving.otp.expires_minutes', $fallback),
            'review_link_expiration_hours' => config('receiving.review_link_hours', $fallback),
            'max_file_size_kilobytes' => config('receiving.uploads.max_file_kilobytes', $fallback),
            'max_files_per_upload' => config('receiving.uploads.max_files', $fallback),
            'allowed_file_types' => config('receiving.uploads.allowed_extensions', $fallback),
            'compression_enabled' => config('receiving.compression.enabled', $fallback),
            'max_image_width' => config('receiving.compression.max_width', $fallback),
            'max_image_height' => config('receiving.compression.max_height', $fallback),
            'jpeg_quality' => config('receiving.compression.jpeg_quality', $fallback),
            'ai_batch_size' => config('receiving.ai.batch_size', $fallback),
            'ai_retry_limit' => config('receiving.ai.retry_limit', $fallback),
            'ai_retry_backoff_seconds' => config('receiving.ai.retry_backoff_seconds', $fallback),
            'review_recipient_rule' => config('receiving.ai.review_recipient_rule', $fallback),
            'staging_cleanup_hours' => config('receiving.uploads.staging_cleanup_hours', $fallback),
            'signed_url_expiration_minutes' => config('receiving.signed_url_minutes', $fallback),
            default => $fallback,
        };
    }
}
