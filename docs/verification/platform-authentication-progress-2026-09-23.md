# Core authentication and product-principal progress — 2026-09-23

Core now has its own normalized-email session provider, `/platform` login and
logout routes, password-reset broker, imported TOTP/recovery-code challenge,
safe return-target allowlist, and Signal-based login, reset, and challenge
screens. Core workspace routes use the `platform` guard. Product-local
authentication models remain intact.

Each enabled product provider can register a Core `ProductPrincipalAdapter`.
The mapped adapter resolves a module user only when exactly one reconciled
`legacy_identity_maps` row points from the active Core account to that
product's user record. `platform.principal:{product}` exposes that local model
as the request's default authenticated principal while preserving explicit
access to the Core principal. Missing, ambiguous, pending, or cross-product
mappings do not resolve. The middleware clears its request-local module user
and restores the prior default guard after the request.

The route groups have **not** switched to this middleware. Deployer still uses
its existing default `web` guard and login routes. Monitor and Analytics remain
disabled. This bridge is not evidence that a common cookie works across the
real product hostnames, nor does it complete social login, passkeys, email
verification, account-security settings, session revocation, organization SSO,
or product host/context integration. Those workflows and the cookie trust
boundary must be reconciled before route cutover.

Focused verification on PHP 8.5.10:

- `php8.5 artisan test --filter='PlatformAuthenticationTest|ProductPrincipalMiddlewareTest'` — **9 passed, 59 assertions**. This covers rendered Core auth pages, normalized and ambiguous email login, imported recovery-code consumption, Core-only password reset, configured-origin redirects, module principal selection, guard restoration, and rejection of missing, ambiguous, and cross-product identity maps.
- `php8.5 vendor/bin/phpunit tests/Feature/Core --display-warnings --display-deprecations` — **52 passed, 401 assertions**. This includes the Deployer, Monitor, and Analytics migration/import and project-link suites.
- `php8.5 artisan route:list --name=platform` — Core login, logout, password-reset, and two-factor routes register under their `platform.*` names.
- Changed PHP files pass syntax checks and the focused source set passes Pint. `git diff --check` passes.

The broader Core run exposed and fixed three defects in earlier work: preview
mode now counts child projects under eligible workspaces before parent rows
are written, Analytics Eloquent models explicitly extend their module's base
model, and Analytics eager-load constraints accept the `HasMany` relation
Laravel supplies. These corrections preserve the intended module and project
behavior; they do not represent full product regression coverage.

No product route guard has changed, no importer has run against persistent
customer data, and no shared-cookie or production-host behavior has been
claimed or tested.
