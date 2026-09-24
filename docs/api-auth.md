# API Auth

Inertia web screens use normal session authentication.

If you later expose external APIs, add token authentication deliberately instead of mixing token auth into the Inertia screens by default. Common options are Laravel Sanctum for first-party APIs or Passport/OAuth for third-party integrations.

## Google Sheets Ingestion Webhooks

The Google Sheets ingestion webhook endpoints (`/api/webhooks/google-sheets/{source}`) accept automated payloads from Google Apps Scripts across the satellite sources (`a2z2go`, `bonita`, `keysys`, and `pingcon`).

Authentication requires a shared secret configured per-source in `GoogleSheetConfig` (`webhook_secret`). Incoming requests must provide the secret either via the `X-Webhook-Secret` HTTP header or a `secret` query parameter. Requests are verified using timing-safe comparison (`hash_equals`). Webhooks without a configured valid secret are strictly rejected (`401 Unauthorized`).
