# API Auth

Inertia web screens use normal session authentication.

If you later expose external APIs, add token authentication deliberately instead of mixing token auth into the Inertia screens by default. Common options are Laravel Sanctum for first-party APIs or Passport/OAuth for third-party integrations.
