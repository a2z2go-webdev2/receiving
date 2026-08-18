# Queues

Receiving is asynchronous by contract. `QUEUE_CONNECTION=sync` is acceptable only inside automated tests; it is not a valid deployed configuration.

The application uses:

- `otp`: login and upload verification codes; latency-sensitive and isolated from long-running work
- `receiving`: validation, malware scanning, compression, promotion, and upload notification
- `ai`: Gemini extraction batches, aggregate status, and review notification
- `default`: framework notifications and unrelated work

Run a dedicated OTP worker so a long file or AI job cannot delay a login code:

```powershell
php artisan queue:work --queue=otp --tries=3 --timeout=60 --sleep=1
```

Run receiving workloads in a separate worker:

```powershell
php artisan queue:work --queue=receiving,ai,default --tries=3 --timeout=300
```

Set database/Redis `retry_after` above the worker timeout (the provided default is 360 seconds for a 300-second worker). Otherwise a slow job can be leased to a second worker and repeat storage, mail, or paid AI work.

For higher volume, add worker pools per workload. Queue concurrency is an operational worker setting. `ai_batch_size` is also capped at runtime so the provider timeout and configured HTTP attempts fit inside `RECEIVING_WORKER_TIMEOUT_SECONDS` with a safety margin.

Run `php artisan receiving:check-production` after caching production configuration and before enabling traffic. SQS visibility timeouts are provider-side and still require a deployment check.

Use database or Redis for `CACHE_STORE` in every deployed instance. The orchestration jobs use shared cache locks to reject duplicate workflow starts across workers.

Alert on failed jobs, queue depth, and the age of the oldest Pending/Processing upload. Restart workers after deployment using the platform's native mechanism. Laravel Cloud workers belong in the dashboard; do not add Supervisor there.
