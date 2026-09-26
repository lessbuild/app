# Plan: BuildPusher Platform v2 — one Cloudflare-style platform, rebuilt from scratch

## Context

BuildPusher is three apps (Deployer, Monitor, Analytics) that are being merged into one Laravel codebase (`lessbuild/app`, branch `feature/unified-platform`). That merge kept three product modules and three databases, joined by a Core layer of mapping tables, outboxes, receipts and fences. That layer is where most of the complexity and bugs sit. Billing is also a separate subscription per product.

The user now wants a **rewrite** that integrates everything the way Cloudflare does:
- One dashboard and one account.
- One resource model, with services you switch on per project.
- One customisable bill where customers pick the services and features they use.
- SOLID, modern Laravel code.
- The Signal design theme kept.

Decisions (26 Sep 2026):

| Topic | Decision |
|---|---|
| Approach | **Greenfield** codebase; port features; import existing data at migration |
| Location | New orphan branch **`platform-v2`** in `lessbuild/app` |
| Main object | **Account → Projects → Environments**; services are enabled per project; a project holds any number of domains |
| Billing | **Per-service tiers + add-ons + metered usage**, on one monthly invoice |
| Data | **One database**, tables grouped by service |
| Launch bar | New sign-ups can use v2 once core services work; **existing customers move only when every feature they use exists in v2** (checked against `docs/feature-parity-matrix.md`) |

This supersedes `docs/core-boundary-and-integration-plan-2026-09-26.md` and the unified-module plan. The old branch stays for reference, data import, and serving customers until they migrate.

## Product model (the "Cloudflare" shape)

- **Account** (today's workspace/organization): members, roles, SSO, API tokens, audit log, billing, settings.
- **Project**: the main object, like a Cloudflare zone. It has environments (production, staging, previews), domains, and **enabled services**.
- **Services**, each with a sidebar section inside the project and an account-level overview:
  - **Deploy**: repos, builds, releases, previews, variables/secrets, processes, schedules, recipes (D04–D08, D17, D18)
  - **Infrastructure**: providers, servers, websites, domains/DNS/TLS, databases, backups, load balancers, costs (D09–D16)
  - **Monitoring**: uptime/HTTP/heartbeat/queue checks, telemetry ingest, events, issues, traces, metrics, dashboards, SLOs, maintenance (M02–M16, D13, D19)
  - **Analytics**: sites/tracker, collection, reports, goals, exports (A03–A08, A10)
- **Shared platform features used by every service** (built once, not per service):
  - Alerts and destinations (merges Deployer and Monitor alert destinations, M11/M18)
  - Incidents (M13, D19 findings)
  - Status pages (merges D20 and M16)
  - Notifications inbox, activity feed, search/command palette (D21)
  - API tokens with per-service scopes and OpenAPI (D22)
  - Audit log and data export
- **Integration is native, not bolted on.** A deploy automatically annotates Monitoring and Analytics, and incidents link to the release that caused them. This works through domain events inside one app and one database, with no cross-database outbox.
- **Admin panel** (platform operators): system health, business analytics, access requests, customer lookup, feature flags. Access needs an admin flag, MFA, and re-confirmation every 15 minutes (the design started on the old branch: `EnsurePlatformAdmin`, `PlatformAdminAccess`, `platform:admin`).

## Billing design (pick and choose)

- **Catalogue as code** (`app/Billing/Catalog/*`): each service declares its **tiers** (Free/Pro/Business), the **features and limits** each tier grants (entitlement keys such as `monitoring.checks.max`, `analytics.retention.days`), **add-ons** (extra checks, longer retention, backups, seats) and **meters** (events, deploy minutes). Stripe price IDs come from config per environment.
- **One Stripe subscription per account**, with one subscription item per chosen service tier, add-on and metered price (Cashier supports multi-item subscriptions). Changing one service updates only its item, with proration.
- **`Entitlements`** service: `Entitlements::for($account)->allows('monitoring.checks.max', $count)` returns a decision object. Every write that consumes a limit checks it, and the billing page shows the same numbers.
- **Usage metering**: usage records roll up hourly and are reported to Stripe meters. The billing page shows current usage against allowances.
- **Billing page**: one card per service (tier picker, add-ons, usage), a live monthly total, invoices, payment method (Stripe portal), and cancel/resume per service.
- **Migration**: every existing paid plan maps to a v2 tier plus add-ons at the **same price** (grandfathered legacy price items where needed), so no customer's bill changes at cutover without agreement.

## Code architecture (SOLID, modern Laravel)

- **Stack**: Laravel 13, PHP 8.5, Livewire 4, Tailwind 4, Alpine, Vite, Fortify, Cashier, Sanctum, Horizon (Redis queues), and the database engine chosen in Phase 0 (default **PostgreSQL**).
- **Layout**, one directory per bounded context:
  `app/Domain/{Accounts,Identity,Projects,Billing,Deploy,Infrastructure,Monitoring,Analytics,Alerts,Incidents,StatusPages,Notifications,Api,Admin}`. Each has `Models`, `Actions` (one public `handle()`, one use case), `Data` (readonly DTOs), `Enums`, `Events`, `Listeners`, `Jobs`, `Policies`, `Queries` (read models), `Contracts`.
- **Other homes**: `app/Http` holds thin controllers, Form Requests and Livewire components that only call Actions and Queries. `app/Services/*` holds wrappers for external systems (Stripe, GitHub, cloud providers, SSH, DNS), each behind an interface so tests use fakes.
- **Service registry**: each service implements `PlatformService` (key, name, nav items, project panels, catalogue entries, onboarding step, event subscriptions). The shell, billing and onboarding read the registry, so adding a service needs no changes elsewhere.
- **Rules**, enforced by architecture tests:
  - Controllers never touch another context's models; contexts talk through Actions, Queries and events.
  - Policies authorise every action.
  - Nothing mass-assigns sensitive fields.
  - Encrypted casts for secrets.
- **Quality gates**: Pint, PHPStan/Larastan at a high level, and architecture tests in CI from the first commit.

## UI (Signal theme)

- Port the Signal component library from the old branch (`resources/views/components/signal/{ui,layouts,overlays,blocks}`, `resources/css/signal`, `resources/js/signal-*.js`), keeping its tokens and variants. Add a component gallery page used as the visual reference.
- **Shell**:
  - Topbar with account switcher, project switcher, search/command palette, notifications and user menu.
  - Project **sidebar listing enabled services**.
  - Account-level sidebar for Members, Billing, API tokens, Audit log and Settings.
  - Services not yet enabled show an **enable page** with a tier picker.
- **Journeys designed first**, each written as a short flow before building:
  - Sign up → first project → add a domain → pick services
  - Enable a service from inside a project
  - Invite a teammate
  - Upgrade one service
  - Incident → release → traffic impact
- Page families follow the rules in `docs/ui-usability-plan-2026-09-18.md`: results before filters, decision-first dashboards, consistent forms.

## Phases

0. **Safeguard and foundation**
   - Commit the in-progress admin-access files on the old branch as WIP so a container reset can't lose them.
   - Create the `platform-v2` orphan branch with a Laravel 13 skeleton, CI (Pint, PHPStan, tests), architecture tests, the Signal port and gallery, and the database choice.
   - Push after every commit.
1. **Identity and accounts**: sign-up/in, email verification, password reset, 2FA, passkeys, social login, sessions, sign-in history, account export/deletion, accounts, members, roles, invitations, per-service member access, audit log, API tokens (D01, D02, D23, M01, A01, A02, C02).
2. **Projects and shell**: projects, environments, domains, service registry, navigation, onboarding, activity, notifications, search.
3. **Billing**: catalogue, entitlements, Stripe multi-item subscription, metering, billing page, invoices, webhooks (D03, M17, A11).
4. **Services**, each a vertical slice with its parity rows ticked off: **Analytics** first (smallest; proves the pattern), then **Monitoring**, **Deploy** and **Infrastructure**. Shared features (alerts, incidents, status pages) are built alongside Monitoring.
5. **Admin panel** and platform operations: health, business analytics, access requests, retention/pruning jobs, queue monitoring (D20, D25, M20, A09).
6. **Public compatibility**: every existing public contract keeps working unchanged:
   - Tracker `/tracker/v1.js` and `/api/v1/collect/{publicId}`
   - Monitor ingest, OTLP, heartbeat and queue endpoints
   - Deployer API v1
   - GitHub and Stripe webhooks
   - Status page URLs
   Also marketing and pricing pages (D24).
7. **Migration and cutover**:
   - Import commands from the three old databases. Each is idempotent, has a dry run and a reconciliation report, keeps old IDs in `legacy_*` columns, re-encrypts secrets, and preserves tracker IDs, ingest tokens and Stripe customers/subscriptions. Ambiguous records are held for review, as the existing importers do.
   - Rehearse on a copy of production.
   - Migrate customers in cohorts once their features are at parity.
   - Legacy hosts redirect to the v2 dashboard.
   - Old apps go read-only, then retire.

## Testing approach (change from the current rule)

`AGENTS.md` currently says to author tests but not run them until the whole plan's source is complete. For a greenfield rewrite, that leaves months of unverified code. **Proposed**: run each slice's feature tests and the CI gates as the slice lands. Approving this plan approves that change for `platform-v2` only; the old branch keeps its rule.

## Verification

- Every slice ships with:
  - Feature tests (policies, entitlement limits, billing item changes affecting one service only)
  - Architecture tests
  - A Playwright check of its main journey at mobile and desktop widths
- Parity: each row of `docs/feature-parity-matrix.md` is marked with its v2 location and test evidence before any customer who uses it is migrated.
- Migration: dry-run import against a production copy, reconciliation counts and ID maps, decryptability checks, Stripe reconciliation with no charges, and one full rehearsal including rollback.
