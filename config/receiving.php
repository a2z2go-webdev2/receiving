<?php

return [
    'initial_admin' => [
        'name' => env('INITIAL_ADMIN_NAME', 'Receiving Administrator'),
        'email' => env('INITIAL_ADMIN_EMAIL'),
        'password' => env('INITIAL_ADMIN_PASSWORD'),
    ],
    'disk' => env('RECEIVING_DISK', 'r2'),
    'bucket' => env('R2_BUCKET_NAME', 'receiving-documents'),
    'proxy_uploads' => env('RECEIVING_PROXY_UPLOADS', false),
    'otp' => [
        'expires_minutes' => (int) env('RECEIVING_OTP_EXPIRES_MINUTES', 5),
        'max_attempts' => (int) env('RECEIVING_OTP_MAX_ATTEMPTS', 5),
        'grant_minutes' => (int) env('RECEIVING_OTP_GRANT_MINUTES', 480),
        'remember_days' => (int) env('RECEIVING_OTP_REMEMBER_DAYS', 30),
    ],
    'uploads' => [
        'max_files' => (int) env('RECEIVING_MAX_FILES', 20),
        'max_file_kilobytes' => (int) env('RECEIVING_MAX_FILE_KILOBYTES', 15360),
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
        'staging_url_minutes' => (int) env('RECEIVING_STAGING_URL_MINUTES', 15),
        'staging_cleanup_hours' => (int) env('RECEIVING_STAGING_CLEANUP_HOURS', 24),
    ],
    'location' => [
        'max_accuracy_meters' => (float) env('RECEIVING_LOCATION_MAX_ACCURACY_METERS', 1000),
        'max_age_seconds' => (int) env('RECEIVING_LOCATION_MAX_AGE_SECONDS', 120),
    ],
    'signed_url_minutes' => (int) env('RECEIVING_SIGNED_URL_MINUTES', 30),
    'review_link_hours' => (int) env('RECEIVING_REVIEW_LINK_HOURS', 24),
    'compression' => [
        'enabled' => env('RECEIVING_COMPRESSION_ENABLED', true),
        'max_width' => (int) env('RECEIVING_MAX_IMAGE_WIDTH', 2400),
        'max_height' => (int) env('RECEIVING_MAX_IMAGE_HEIGHT', 2400),
        'jpeg_quality' => (int) env('RECEIVING_JPEG_QUALITY', 85),
        'allow_original_on_failure' => env('RECEIVING_ALLOW_ORIGINAL_ON_COMPRESSION_FAILURE', false),
    ],
    'scanner' => [
        'driver' => env('RECEIVING_SCANNER_DRIVER', 'cloudmersive'),
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout_seconds' => (int) env('CLAMAV_TIMEOUT_SECONDS', 30),
        'cloudmersive' => [
            'monthly_call_limit' => (int) env('CLOUDMERSIVE_MONTHLY_CALL_LIMIT', 800),
            'minimum_interval_milliseconds' => (int) env('CLOUDMERSIVE_MINIMUM_INTERVAL_MILLISECONDS', 1100),
            'max_file_kilobytes' => (int) env('CLOUDMERSIVE_MAX_FILE_KILOBYTES', 3584),
            'lock_wait_seconds' => (int) env('CLOUDMERSIVE_LOCK_WAIT_SECONDS', 30),
            'busy_retry_seconds' => (int) env('CLOUDMERSIVE_BUSY_RETRY_SECONDS', 5),
            'rate_limit_retry_seconds' => (int) env('CLOUDMERSIVE_RATE_LIMIT_RETRY_SECONDS', 60),
        ],
    ],
    'ai' => [
        'batch_size' => (int) env('RECEIVING_AI_BATCH_SIZE', 1),
        'http_attempts' => (int) env('GEMINI_HTTP_ATTEMPTS', 3),
        'retry_limit' => (int) env('RECEIVING_AI_RETRY_LIMIT', 3),
        'retry_backoff_seconds' => (int) env('RECEIVING_AI_RETRY_BACKOFF_SECONDS', 60),
        'review_recipient_rule' => env('RECEIVING_REVIEW_RECIPIENT_RULE', 'uploader'),
    ],
    'queue' => [
        'workload_timeout_seconds' => (int) env('RECEIVING_WORKER_TIMEOUT_SECONDS', 300),
        'timeout_safety_seconds' => (int) env('RECEIVING_WORKER_TIMEOUT_SAFETY_SECONDS', 30),
    ],
];
