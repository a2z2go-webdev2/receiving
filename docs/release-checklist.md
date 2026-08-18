# Release Checklist

Before deploy:

- Tests pass.
- `composer analyse` passes.
- `composer lint` passes.
- `npm run check` passes.
- `npm run typecheck` passes.
- `npm run build` passes.
- Migrations are reviewed.
- Environment variables are updated.
- `php artisan receiving:check-production` passes after configuration is cached.
- Object storage is configured for persistent files.
- Authentication checklist in `docs/authentication.md` is still accurate for any changed auth behavior.

After deploy:

- `php artisan migrate --force` has run.
- Queue workers are running if needed.
- Scheduler is enabled if needed.
- Smoke test login, dashboard redirects, and critical workflows.
