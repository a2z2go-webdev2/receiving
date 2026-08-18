# Security

- Set `APP_DEBUG=false` in production.
- Generate `APP_KEY` once per environment and do not rotate it casually.
- Never commit `.env`.
- Keep default seed accounts out of production.
- Use Form Requests for validation.
- Use policies and Spatie permissions for authorization.
- Rate-limit login and sensitive API endpoints.
- Use HTTPS everywhere.
- Use real mail credentials in production.
- Store persistent files in object storage on Laravel Cloud.
- Follow `docs/authentication.md` before changing guards, login, recovery, MFA, sessions, or account-state behavior.
