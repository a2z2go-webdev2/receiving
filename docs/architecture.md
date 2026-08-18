# Architecture

The template starts from Laravel's official React starter kit, then adds business-application conventions:

- `app/Features/*` for domain-oriented code that grows beyond simple controllers.
- `app/Actions` for single-purpose application actions.
- `app/Services` for workflow-oriented business logic.
- `app/Data` for typed DTOs using Spatie Laravel Data.
- `app/Enums` for shared status and permission values.
- `app/Http/Resources` for API/Inertia serialization boundaries.
- `resources/js/types` for frontend mirrors of important backend concepts.

Recommended request flow:

```text
Route -> Controller -> Request -> Service or Action -> Model -> Resource or Inertia response
```

Keep controllers thin. Put validation in Form Requests, durable business rules in actions/services, and authorization in policies or Spatie permissions.
