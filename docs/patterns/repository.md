# Repository Pattern

Skip repositories by default. Eloquent already gives Laravel projects a strong data access layer.

Consider repositories only when:

- Multiple services need the same complex query boundary.
- You must swap data sources.
- You need isolated unit tests around data access.

Prefer query objects under `app/Features/*/Queries` for focused read models.
