# Authentication

This project treats `docs/authentication.md` as the local authentication source of truth. When changing authentication behavior, inspect this file before changing guards, login flows, sessions, tokens, account state, MFA, or recovery behavior.

## Project Auth Defaults

Laravel version: 13

Auth stack:

- Breeze: no
- Jetstream: no
- Fortify: yes
- Sanctum: no
- Passport: no
- Custom auth: only app-specific Fortify callbacks and middleware

Main browser guard: `web`

Main API guard: none. Add Sanctum or Passport deliberately before exposing authenticated APIs.

User model: `App\Models\User`

User table: `users`

Login identifier: `email`

Registration enabled: yes

Email verification required: yes

MFA required: yes for privileged/admin access; optional for non-privileged accounts

Remember me enabled: yes for normal browser login; do not rely on it as recent authentication

Password reset enabled: yes

Public API tokens enabled: no

OAuth login enabled: no

SSO enabled: no

Impersonation enabled: no

Tenant or team auth enabled: no

Admin panel protected by MFA: yes

Sensitive system type:

- finance: no default assumption
- payroll: no default assumption
- HR: no default assumption
- medical: no default assumption
- legal: no default assumption
- school: no default assumption
- ecommerce: no default assumption
- SaaS: possible starter use case; add tenant/team rules before enabling multi-tenant features

## Implementation Rules

- Browser authentication uses Fortify, the `web` guard, database-backed sessions, CSRF protection, and Laravel's password broker.
- Password login and passkey login both deny inactive, suspended, banned, and deactivated users.
- `email_verified_at` is required for protected app routes because `App\Models\User` implements `MustVerifyEmail`.
- Privileged access is permission-based and requires confirmed two-factor authentication.
- Authentication-sensitive events are recorded in `auth_audit_logs` without storing passwords, reset tokens, MFA codes, recovery codes, plain API tokens, or raw session IDs.
- Frontend permissions only hide or show UI. Backend routes, controllers, policies, middleware, and permissions remain the security boundary.
- Add Sanctum or Passport only when authenticated API behavior is required. Token abilities must not replace user permissions.

## Required Tests For Auth Changes

Every auth change should keep or add tests for:

- successful login and logout
- generic failed-login behavior
- login throttling
- inactive, suspended, banned, and deactivated login denial
- unverified user blocking when verification is required
- password reset request and completion
- password change requiring current password and regenerating the session
- sensitive profile changes requiring recent password confirmation
- MFA challenge behavior
- privileged access requiring MFA
- audit records for sensitive authentication events
