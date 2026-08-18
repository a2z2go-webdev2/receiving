# Environment

Use PostgreSQL, encrypted database sessions, database/Redis queues, and the private R2 disk. Secrets remain in environment variables or the deployment secret manager and are never editable through the admin UI.

```env
APP_ENV=production
APP_DEBUG=false
HASH_DRIVER=argon2id
SESSION_DRIVER=database
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
RECEIVING_DISK=r2
RECEIVING_PROXY_UPLOADS=false

R2_BUCKET_NAME=receiving-documents
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_ENDPOINT=https://<account>.r2.cloudflarestorage.com

GEMINI_API_KEY=...
GEMINI_MODEL=...

RECEIVING_SCANNER_DRIVER=cloudmersive
CLOUDMERSIVE_API_KEY=...
CLOUDMERSIVE_MONTHLY_CALL_LIMIT=800
CLOUDMERSIVE_MINIMUM_INTERVAL_MILLISECONDS=1100
CLOUDMERSIVE_MAX_FILE_KILOBYTES=3584
```

The API key belongs in the deployment secret manager and is never returned to the admin UI or copied into logs. The monthly allowance is enforced by the `cloudmersive_scan_usages` table, and a shared database/Redis cache lock serializes scan requests across workers. Keep `CACHE_STORE` shared in production. Use the allowance displayed for the actual Cloudmersive account; current public free-tier pricing may be lower than older 800-call accounts.

`RECEIVING_PROXY_UPLOADS=false` uses a short-lived, object-scoped R2 PUT URL as the efficient primary path and automatically retries a failed browser PUT once through the authenticated same-origin application stream. Before using direct PUTs, configure the private bucket CORS policy for the exact deployed application origin:

```json
[
  {
    "AllowedOrigins": ["https://receiving.example.com"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["Content-Type"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

An origin is the complete scheme, hostname, and port. `http://localhost:8000` and `http://127.0.0.1:8000` are different origins and must be listed separately if both are used.

Use `RECEIVING_PROXY_UPLOADS=true` for local development and as an operational switch when direct PUT probes fail. It skips the failed cross-origin attempt and streams one file at a time with bounded memory through Laravel. After changing the value, rebuild the Laravel configuration cache and restart web and queue processes.

The settings page exposes readiness booleans only. It must never receive or render credential values. Rotate provider credentials through the environment/secret manager and restart workers after configuration changes. Key rotation does not reset the application's monthly usage row, preventing an accidental quota bypass.
