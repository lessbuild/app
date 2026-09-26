# Plan: slimmer Core, integrated products, admin panel, and UI/flow overhaul

## Context

The unified BuildPusher app (`lessbuild/app`, branch `feature/unified-platform`) has one Laravel codebase with Core plus three product modules (Deployer, Monitor, Analytics). So far, the plan grew Core into the admin surface for every product: Monitor alerts/SLOs/maintenance/checks, Analytics sites/goals/exports, Deployer environment configuration and project blueprints all have Core pages. Meanwhile, each product still has its own account, security, billing and settings pages, and operator tools (system health, business analytics, access requests) live in Deployer.

The user now wants:
- The products to stay **somewhat separate but integrated**.
- Core limited to shared concerns: **auth, account/security, settings, billing, projects, environments, websites**.
- **All user settings, account/security and billing pages** extracted from every product into Core.
- **System health, business analytics and access requests** in a platform **admin panel**.
- Better flow and UI across all apps and Core.

Decisions made (26 Sep 2026):
1. Product-specific admin pages move **back into their products**. Core links in with project/environment context.
2. Core owns **one shared Website record** that Deployer, Monitor and Analytics all attach to.
3. The admin panel uses a **Core platform-admin role that requires MFA**, replacing Deployer's email allowlist.
4. UI work goes **shell and cross-app flows first**, then page families.

Constraints carried over: preserve every existing feature and entitlement (release gate), keep legacy URLs working via redirects, keep each product's database, no data loss. Per `AGENTS.md`, author tests but don't run suites until source implementation is complete.

## Target architecture

**Core owns (shared, cross-product):**
- Identity and sign-in (SSO across hosts), account profile, security (password, 2FA, passkeys, sessions, sign-in history, social logins), data export, account deletion
- Workspaces, members, invitations, roles, per-product access grants
- Workspace settings (security policy, notification preferences, SSO/enterprise)
- Billing: one page with an independent plan per product, plus checkout, portal, cancel and invoices
- Canonical Projects → Environments → **Websites** (new), with product attachments
- Platform admin panel (`/admin`)
- Integration layer (read-only, cross-product): app switcher, command search, notification inbox, activity feed, delivery history, connections

**Products own:** all product-specific configuration and operations, including Monitor alerts/SLOs/maintenance/checks/apps/environments, Analytics sites/goals/reports/exports, Deployer servers/deployments/variables/recipes/infrastructure, and blueprint steps.

**Integration contract between them** (how they stay separate but connected):
- **Shared shell**: the same Signal topbar in every app, with workspace switcher, app switcher, and a project/environment context that carries across apps (`?project=…&environment=…` resolved through existing `ProjectResource` mappings).
- **Cross-product glance panels**: small typed read contracts, so for example a Deployer environment page shows Monitor health and Analytics traffic for the same environment/website, each with a link-out. Reuse the existing provider-registry pattern (`app/Core/Services/*ProviderRegistry.php`).
- **Events, not shared tables**: the existing connection outbox (`ProjectConnection*`, `ProjectConnectionOutbox*Registry`) carries deploy → Monitor annotations, website created → Analytics site suggestion, and so on.
- **Shared Website record**: "add a domain once" offers to host it (Deployer), check it (Monitor) and track it (Analytics).

## Workstreams

### A. Return product admin from Core to products
For each Core product-admin screen, confirm the product's native page covers the same behaviour (parity matrix), make the native page accept Core project/environment context, then replace the Core screen with a context-aware link and redirect the old Core URL.
- Core screens to retire: `app/Core/Routes/monitor-administration.php`, `monitor-configuration.php`, `analytics-administration.php`, `deployer-configuration.php`, the Deployer deployment-controls routes, and the Monitor maintenance/SLO/status-page controllers under `app/Core/Http/Controllers/WorkspaceMonitor*`, `WorkspaceAnalyticsAdministration*` and `WorkspaceDeployer*`, plus their views in `app/Core/Views/workspaces/{monitor,…}` and `resources/views/core/workspaces/deployer/`.
- Keep the typed module providers (`app/Modules/*/Services/Core/*Provider.php`) wherever the integration layer (glance panels, search, activity) still uses them; delete the write-only ones.
- Change `WorkspaceAdministrationCatalog` so it becomes a directory of product links, not Core pages.
- Project blueprints: Core keeps the blueprint and run record (a cross-product orchestration); the per-product steps already live in modules.
- Record the reversal in `docs/unified-application-plan.md` and the coverage audit.
- Work done this session (Core Monitor check create/archive, the Deployer variables link) moves back with the rest; the Deployer alert-delivery outbox stays in Deployer, and its read-only projection stays on Core's delivery history page (integration layer).

### B. Account, security and settings into Core
| Source page | Core destination |
|---|---|
| Deployer `account` (profile, password, 2FA, social logins, sessions, sign-ins + export, data export, delete) | `/account`, `/account/security` (exists: `PlatformAccountSecurityController`), `/account/export` (exists), deletions (exists) |
| Analytics `/account` (`ProfileController`, Fortify profile/password/2FA/passkeys) | same Core pages |
| Monitor `/settings/notifications` (personal digest preferences) | `/account/notifications` |
| Deployer `organization/security-policy`, `organization/notification-preferences`, `organization/sso/*` | `/workspaces/{w}/settings` (security, notifications, SSO tabs) |
| Monitor `/settings/team`, Analytics `TeamController` and invitations | Core team (exists: `WorkspaceTeamController`) |
| Monitor `/settings/data`, `/settings/audit-log`; Analytics `WorkspaceDataController` | Core workspace data and audit (per-product sections backed by existing providers) |

Missing from Core today: a profile edit page, a sign-in history page, social-login linking, workspace security policy and SSO settings. Build these on Core's `PlatformUser`/`UserIdentity`, reusing each product's actions where the data is product-owned. Each old product route becomes a permanent redirect; product navigation items point to Core.

### C. Billing into Core
- One `/workspaces/{w}/billing` page (evolves `WorkspaceSubscriptionsController`) with one card per product showing plan, seats, usage and status, plus actions for change plan, manage payment (portal), cancel/resume and invoices.
- Actions go through a single `ProductBillingGateway` contract implemented by each module using its existing code: Deployer `BillingController` / Cashier, Monitor `BillingController` / `ProcessStripeBillingEvent`, Analytics `AnalyticsBillingController` / billing gateway (already behind the authority switch).
- Stripe webhooks stay at their existing URLs (no customer-facing change).
- Replace product billing pages (Deployer `billing`, Monitor `/settings/billing*`, Analytics `/workspaces/{w}/billing*`) with redirects; checkout success/cancel return to Core.
- Invariant: a change to one product never touches another's subscription (existing plan requirement).

### D. Platform admin panel (`/admin`)
- **Access**: add an `is_platform_admin` flag (additive Core migration) on `PlatformUser`, set by `php artisan platform:admin {email} --grant|--revoke` or by an existing admin. The new `platform-admin` gate requires the flag **and** an enrolled second factor (TOTP or passkey) **and** a recent confirmation. Audit every grant. One-time import of `lessbuild.platform_admin_emails`.
- **System health**: aggregate readiness of all products. Reuse Deployer's `SystemHealthController` checks and each module's `ReadinessController`, plus queue depth and failed jobs per worker pool, scheduler heartbeat, and Analytics Horizon (link). Also the downloadable report.
- **Business analytics**: extend Deployer's `AdminAnalyticsController` into platform-wide numbers: sign-ups, active workspaces, per-product adoption, subscriptions/MRR by product (from `ProductSubscription`/`ProductBillingEvent`), churn and trials.
- **Access requests**: already partly in Core (`CoreAccessRequestController`, `/admin/deployer/access-requests`); unify under `/admin/access-requests` for every product.
- Remove the Deployer copies (`admin/analytics`, `admin/access-requests`, `system-health`) and redirect them. The GitHub App setup stays in Deployer (product integration).

### E. Shared Website record
- New Core `websites` table (domain, project, environment, status) plus a `website_attachments` table (product, resource type/id).
- Backfill from Deployer websites (via environment `website_id`), Analytics sites (domain) and Monitor HTTP check targets, matched only where it's unambiguous; otherwise hold for review (same pattern as the existing importers).
- Core project page shows each website with its hosting, monitoring and analytics status and "add to Monitor / Analytics" actions, which call product-owned creation through the existing blueprint step providers.

### F. UI and flow (shell and flows first)
1. **Shell**: finish the Signal Topbar SaaS shell across all four apps (`x-signal.*` components, `docs/signal-component-library.md`). Add a persistent app switcher with context carry-over, one account menu (→ Core account/billing), and consistent empty/loading/error states.
2. **Cross-app journeys**, each with a written before/after flow:
   - Sign up → create workspace → first project → first website → pick products
   - Switching apps without losing the project
   - Upgrading a plan from inside a product (in-product prompt → Core billing → back)
   - Invite a teammate, then grant product access
   - An incident in Monitor → linked deploy in Deployer → traffic impact in Analytics
3. **Page families**: apply `docs/ui-usability-plan-2026-09-18.md` (results before filters, decision-first dashboards, form and settings layout) to Monitor, Analytics and Core, not only Deployer.
4. Use the existing browser route sweeps (Playwright config and the mobile/tablet/desktop crawls noted in `docs/CHAT_HANDOFF.md`) as the regression net for layout.

## Sequencing
1. D (admin panel) and B (account/settings). These are self-contained and unblock removing product account pages.
2. C (billing).
3. F.1–F.2 (shell and journeys), in parallel with E (shared websites; the journeys depend on it).
4. A (return product admin), per product, once native pages accept Core context and parity is recorded.
5. F.3 page families, per product.

Push after every commit (this container can be reset; see earlier loss).

## Verification
- Author feature tests per workstream alongside the code:
  - Redirects from every retired product/Core URL
  - Admin gate denies users without the flag, without MFA, or without a recent confirmation
  - Billing actions affect only one product
  - Website backfill holds ambiguous matches for review
  - Context carry-over across hosts
- When source implementation is complete (per `AGENTS.md`): run the full PHP suite, Pint, the Vite build, route/config/view cache, and the Playwright mobile/tablet/desktop route sweeps; walk each journey in F.2 in a browser; update `docs/feature-parity-matrix.md` for every moved page.
