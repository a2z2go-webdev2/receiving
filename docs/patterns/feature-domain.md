# Feature Domains

Use `app/Features` when a workflow grows beyond a controller and a model.

Example:

```text
app/Features/UserManagement/
  Actions/
  Data/
  Queries/
  Services/
```

Keep feature code cohesive. Shared cross-feature utilities belong in `app/Actions/Shared`, `app/Services/Shared`, or `app/Support`.
