# UI modernization progress

## Purpose

This ledger records the BuildPusher interface modernization. Each slice must
preserve routes, response status codes, validation keys, named error bags,
flash messages, authorization behavior, Livewire contracts and persisted
values. A slice is complete only after its focused checks pass, the result is
documented, and its cohesive commit is pushed to `origin/main`.

## Baseline — 2026-09-17

- Checkout: isolated implementation checkout on `main`.
- Baseline commit: `2a5b129` (`docs: record final verification results`).
- Working tree: clean before this ledger was added.
- Required PHP: `/root/.local/share/buildpusher/php-8.5.10/bin/php` (PHP 8.5.10).
- Frontend runtime: Node 22.12.0, npm 10.9.0, locked `node_modules` present.
- Existing frontend foundation: shared Blade layouts, Tailwind 4 semantic
  theme utilities, Alpine navigation/command palette, Livewire status views,
  and existing asset/accessibility/visual browser suites.
- Fresh PHP baseline: `artisan test --fail-on-warning --fail-on-risky
  --fail-on-deprecation --fail-on-phpunit-deprecation
  --do-not-record-test-run-history` — **1,556 passed, 13,004 assertions**;
  no warnings, risky tests, deprecations or PHPUnit deprecations.
- Pint baseline: `vendor/bin/pint --test` — passed.
- Asset baseline: `npm run build` — passed with Vite 8.2.2.
- Browser asset baseline: `npm run test:assets` — **9 passed**.
- Browser accessibility and visual baseline: `npx playwright test
  tests/Browser/accessibility.spec.js tests/Browser/visual-audit.spec.js` —
  **6 passed** in 8.7 minutes.
- Disposable browser runtime: `127.0.0.1:8014`, seeded SQLite database at
  `/tmp/buildpusher-ui-baseline-20260917/database.sqlite`, array cache/queue,
  isolated local application key and no production credentials. The temporary
  server was stopped after the run.
- No live or paid-cloud acceptance was performed. The known tablet Search/
  focus expectation and the broader mobile Settings-link visual-audit issue
  were not reproduced as failures in this fresh baseline; they remain tracked
  as historical observations until the relevant journeys are explicitly
  rechecked after UI changes.

## Page inventory

### Public, documentation and authentication

Landing, pricing, access request, privacy, terms, product guide, API docs,
platform status, public status pages, login, registration, password reset,
email verification, password confirmation and two-factor challenge.

### Workspace and application delivery

Dashboard, search, projects, project creation/detail, environments,
configuration authoring/review/receipt, builds/deployments, deployment detail
and comparison, repositories, GitHub App selection, impact preview, websites,
website creation/detail, health checks, runtime logs and provisioning logs.

### Infrastructure and recovery

Servers, server creation/edit/detail, server import/assessment/review,
commands, command output, troubleshooting, providers, provider creation/edit/
detail/connection checks, databases, domains and TLS, load balancers, backups,
backup destinations, schedules, restore and verification.

### Operations and automation

Observability, environment context, investigations, metric rules, alert
destinations, incidents, status pages, system health, notifications, saved
filters, activity, command history, automation, workflow configuration,
deployment/scaling schedules, scheduled tasks and API/token operations.

### Templates, account and administration

Recipes, recipe detail/create/edit, gallery, gallery detail/compare, ratings,
favorites, reports, feedback, workspace membership, billing, costs, profile,
password, sessions, sign-in history, two-factor settings, admin analytics and
access requests.

## Initial findings

1. Desktop and mobile navigation are maintained as separate definitions. The
   mobile menu exposes almost every destination as an equal tile, while the
   desktop sidebar has a long flat list.
2. Applications, Sites, Domains, Builds and Repositories are related delivery
   concepts but are presented as unrelated primary destinations.
3. Workspace, Billing, Costs, Account and Settings are split across several
   links; Account and Settings currently lead to the same account surface.
4. Observability, Notifications, Activity, Commands and System health have no
   clear operations grouping.
5. Recipes and Gallery form one template/community area but are separate in
   the primary navigation.
6. The heading and breadcrumb partials do not provide one consistent page
   hierarchy. The breadcrumb is currently a single back link.
7. Button variants, radii, spacing, status colors and typography vary widely.
   The existing `.ternary` variant is the blue action treatment, while
   `.primary` is often a neutral surface treatment.
8. Several large views combine multiple workflows: dashboard, repository
   detail, user account, server detail, deployment status, observability,
   backups and notifications.
9. Dense inline forms and mixed list/form pages need progressive disclosure on
   mobile, especially backups, domains, load balancers and observability.
10. Existing browser coverage already protects assets, focus, mobile menu,
    visual layout and broad route rendering; new UI work must extend those
    contracts rather than weaken them.

## Proposed navigation model

The first implementation will consolidate the navigation visually without
removing or renaming existing routes.

| Group | Destinations |
| --- | --- |
| Overview | Dashboard |
| Build and release | Applications, Deployments, Repositories |
| Infrastructure | Sites, Servers, Providers |
| Data and recovery | Databases, Backups |
| Traffic | Domains and TLS, High availability |
| Health and operations | Observability, Commands, Activity, Notifications |
| Automation | Automation and API |
| Templates | Recipes and Gallery |
| Workspace | Workspace, Billing and usage, Account and security |
| Help | Product guide, Feedback |
| Administration | System health, Analytics, Access requests |

## Slice ledger

| Slice | Status | Tests | Commit / push | Next task |
| --- | --- | --- | --- | --- |
| Phase 0: inventory and fresh baseline | Complete | 1,556 PHP tests / 13,004 assertions; Pint; asset build; 9 asset-layout tests; 6 accessibility/visual tests | `2c43276` pushed to `origin/main` | Add semantic design tokens and shared Blade UI primitives |
| Phase 1: shared visual system | Complete | Blade view cache; Pint; diff check; Vite build; 9 asset-layout tests including mobile, tablet, desktop, dark mode and no-JS provider submission | `a5045f0` pushed to `origin/main` | Consolidate desktop/mobile navigation into grouped workspace IA |
| Phase 2: application shell and navigation | Complete | 3 dedicated navigation tests across mobile/tablet/desktop; 9 asset-layout tests; 36 focused feature tests / 808 assertions; mobile and desktop broad visual audit pass; tablet broad audit timed out at 15 minutes on `/providers/3/edit` | `1661f21` plus compatibility correction `5943ad8`, both pushed to `origin/main` | Modernize dashboard and applications/deployments page family |
| Phase 3A: dashboard hierarchy | Complete | Dashboard, infrastructure-filter and UI asset feature coverage: 44 tests / 808 assertions; view cache; Pint; Vite build; 9 asset-layout tests with PHP 8.5.10; diff check | `e6eb63f` pushed to `origin/main` | Modernize applications and project pages |
| Phase 3B: applications and environments | Complete | Project creation, environment, configuration, preview and tenancy coverage: 45 tests / 406 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `df6a56c` pushed to `origin/main` | Modernize builds and deployment pages |
| Phase 3C: builds and deployment status | Complete | Build history, deployment, approval, cancellation, comparison, observation, log, webhook and repository coverage: 78 tests / 621 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `3d3f24a` pushed to `origin/main` | Modernize infrastructure resource pages |
| Phase 4A: infrastructure inventories | Complete | Website, server and provider coverage: 307 tests / 2,605 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `4aa8f64` pushed to `origin/main` | Modernize databases, domains, high availability and backups |
| Phase 4B: database and traffic operations | Complete | Database, domain and load-balancer coverage: 24 tests / 147 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `681a57c` pushed to `origin/main` | Modernize backup and restore operations |
| Phase 4C: backup and recovery workflows | Complete | Backup destination, verification and recovery coverage: 17 tests / 129 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `6726ff1` pushed to `origin/main` | Modernize operations and automation pages |
| Phase 5A: reporting and automation | Complete | Activity, command lifecycle and automation coverage: 69 tests / 466 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `4233ad9` and `763223d` pushed to `origin/main` | Modernize observability and environment investigation |
| Phase 5B: observability and investigation | Complete | Observability, incident, status and environment-context coverage: 40 tests / 291 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `1e60a90` pushed to `origin/main` | Modernize notifications, cost visibility and account administration |
| Phase 5C: notifications and cost visibility | Complete | Notification, bulk-action, inbox, incident, recipe-notification and cost coverage: 47 tests / 356 assertions; view cache; Pint; Vite build; 9 responsive asset fixtures with PHP 8.5.10; diff check | `a961903` and `91b7e7e` pushed to `origin/main` | Modernize account, workspace and administration pages |
| Phase 6A: workspace, account and administration surfaces | Complete | Workspace, billing, access-request, analytics and account security matrix: 140 tests / 891 assertions; view cache; Pint; Vite build; 7/9 responsive asset fixtures passed, with the two 390px screenshots ending in browser target/artifact crashes; diff check | `ef63629` pushed to `origin/main` | Modernize templates, gallery, reports, feedback and remaining product pages |
| Phase 6B: templates and community workflows | Complete | Recipe, gallery, report, feedback and inventory matrix: 111 tests / 1,031 assertions; view cache; Pint; Vite build; 9 responsive/no-JS asset fixtures; diff check | `f94c862` pushed to `origin/main` | Audit remaining product, public, documentation and account-adjacent pages |
| Phase 6C: public, status and authentication surfaces | Complete | Public/auth/status/search/security matrix: 87 tests / 650 assertions; LocalUiAssetTest: 14 tests / 363 assertions; view cache; Pint; Vite build; 9 responsive/no-JS asset fixtures; diff check | `9d46445` pushed to `origin/main` | Modernize remaining operational detail and form surfaces |

## Phase 1 record — shared visual system

### Responsibility problem

Pages were independently choosing headings, breadcrumbs, cards, empty states,
alerts, buttons, radii and focus behavior. That made the interface feel like a
collection of feature-specific screens and made responsive/accessibility fixes
hard to apply consistently.

### Boundaries and benefit

- Semantic color, surface, border, text, status and focus tokens live in the
  theme, while the established `bg-*`, `text-*` and border aliases remain
  available for incremental migration.
- Presentation-only `x-ui` components provide page headers, buttons, cards,
  badges and empty states. They do not contain authorization, persistence or
  business rules, keeping the view layer focused on presentation.
- Shared heading, breadcrumb, stat, alert, form-section and empty-list partials
  now consume the same visual primitives. Destructive dialogs use the semantic
  button component, and the applications index uses the shared badge.
- Buttons have visible keyboard focus, 40px minimum touch height, disabled
  treatment and reduced-motion-safe transitions. Existing button class names
  remain compatible while their visual migration is staged.

### Preserved contracts

Routes, controllers, Livewire behavior, authorization, validation, flash text,
named error bags, response formats, persisted values and queued operations were
not changed. No production runtime, credentials or cloud resources were used.

### Verification

- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run test:assets` — **9 passed**, including four light and four dark
  responsive fixture widths plus provider submission with JavaScript disabled.
- `npm run build` — passed with the updated Vite bundle.
- `git diff --check` — passed.

## Phase 6A record — workspace, account and administration surfaces

### Responsibility problem

Workspace membership, security policy, billing, account recovery, sign-in
history and platform administration were visually inconsistent with the newer
operational pages. Dense inline security forms also made destructive actions,
credential prompts and workspace boundaries harder to scan on smaller screens.

### Boundaries and benefit

- Existing controllers, policies, Form Requests, named error bags, session
  flows and billing/provider boundaries remain unchanged; the work stays in the
  presentation layer.
- Workspace administration now separates members, security policy, SSO,
  notification preferences, invitations, workspace switching and deletion
  into clearly labeled responsive sections.
- Account security uses shared semantic alerts, cards, badges and action
  variants for verification, passwords, 2FA, sessions, connected providers,
  sign-in history, export and deletion. Sensitive inputs remain password or
  one-time-code fields and are not copied into new UI state.
- Billing, access-request review, analytics and full sign-in history now share
  the same metric/card/status vocabulary. A reusable `x-ui.alert` component
  was added for consistent semantic feedback without adding business logic.

### Preserved contracts

All routes, response behavior, validation names, named error bags, flash text,
authorization and entitlement decisions, invitation/member pivot behavior,
Stripe interactions, account/session/2FA semantics and secret-safe rendering
remain unchanged. No database writes, queue dispatches, remote calls or
production credentials were introduced.

### Verification

- Workspace, billing, access-request and platform analytics matrix — **45
  passed, 234 assertions**.
- Account, security activity, sign-in, session, social, 2FA, verification and
  password-confirmation matrix — **95 passed, 657 assertions**.
- Combined focused evidence — **140 passed, 891 assertions**.
- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Browser asset-layout suite — **7 passed, 2 incomplete due browser target or
  artifact crashes at the 390px screenshot step**; 320px, 768px, 1440px and
  the no-JavaScript provider submission passed. This is recorded as incomplete
  browser evidence, not a passing claim or an application assertion failure.
- `git diff --check` — passed.
- Commit `ef63629` pushed to `origin/main`.

## Phase 3C record — builds and deployment status

### Responsibility problem

Deployment history and detail already had the required operational data, but
the visual hierarchy treated filters, evidence, timeline milestones, recovery
actions and raw logs as a sequence of similarly weighted bordered blocks. The
mobile list and desktop table also used separate status treatments.

### Boundaries and benefit

- Query, authorization, Livewire polling, callback handling, log bounds and
  deployment state remain in their existing controllers, actions, jobs and
  Livewire component.
- Shared cards, badges, alerts and buttons now express the same status and
  action vocabulary in history, comparison, detail, approval and recovery
  surfaces.
- Deployment detail presents identity and approval context before the timeline,
  then recovery/rollback choices, health evidence and the bounded log. This
  improves incident scanning without changing execution order.
- Desktop and mobile history retain their existing pagination, filters, export
  route and links; only the presentation wrapper and status treatment changed.

### Preserved contracts

Revision attestation, webhook metadata, approval/rejection, cancellation,
redeployment, rollback, observation leases, stale callback protection, log
escaping/downloads, query scoping and all response behavior remain unchanged.
No remote calls or database writes were added to the views.

### Verification

- Focused build/deployment matrix — **78 passed, 621 assertions**.
- artisan view:cache — passed.
- vendor/bin/pint --test — passed.
- npm run build — passed.
- Browser asset-layout suite with the mandated PHP 8.5.10 binary — **9 passed**.
- git diff --check — passed.

## Phase 4A record — infrastructure inventories

### Responsibility problem

The highest-use infrastructure inventory pages independently repeated filter
surfaces, metric cards, action links, table containers and provisioning or
connection status colors. The visual differences made it harder to scan
capacity and health across Websites, Servers and Providers, especially at
mobile widths.

### Boundaries and benefit

- `x-ui.stat` now provides one semantic metric presentation for definition-list
  summaries without moving query or metric calculation into views.
- Websites, Servers and Providers use shared page-header icons, button variants,
  filter cards, status badges and table shells. Existing filters, exports,
  pagination, links and empty states remain in their original view boundaries.
- Status badges use the shared semantic tones so active, failed, pending,
  healthy, failed and unchecked states are recognizable across resource types.
- The presentation changes remain intentionally view-only: controllers,
  policies, queries, encrypted attributes, jobs and provider contracts were not
  changed.

### Preserved contracts

Organization scoping, filter normalization, pagination, ordering, CSV export,
provider connection feedback, provisioning states, health monitoring,
authorization, secret handling and all resource lifecycle behavior remain
unchanged. No database writes, remote calls or queue dispatches were added.

### Verification

- Focused infrastructure matrix — **307 passed, 2,605 assertions**.
- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Browser asset-layout suite with the mandated PHP 8.5.10 binary — **9 passed**.
- `git diff --check` — passed.

## Phase 4B record — database and traffic operations

### Responsibility problem

Database, domain and high-availability pages combined resource identity,
operational status, credentials, destructive workflows and setup forms in
dense one-line templates. Labels and section hierarchy varied, and destructive
or recovery actions did not share the same visual language as the rest of the
application.

### Boundaries and benefit

- Existing controllers, policies, actions, jobs, query scopes and remote
  integrations remain the behavior boundary; the change is limited to Blade
  presentation and shared UI primitives.
- Database operations now separate inspection metrics, credential issuance,
  credential revocation, clone confirmation and clone history into readable
  sections. The password notice retains its one-time session behavior.
- Domains and TLS use consistent website group cards, domain status metadata,
  labeled setup forms and explicit temporary-domain guidance.
- High availability uses a clear route creation section, node count/status
  summary and responsive node management forms. Shared badges distinguish
  active, pending and failed states without inventing new workflow states.

### Preserved contracts

All original field names, defaults, routes, CSRF/method fields, plan checks,
organization scoping, confirmation inputs, disabled temporary-domain behavior,
primary-domain safety, clone restrictions, dispatch timing and response/flash
behavior remain unchanged. No new writes, queries, remote calls or queue jobs
were introduced.

### Verification

- Focused database/domain/load-balancer matrix — **24 passed, 147 assertions**.
- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Browser asset-layout suite with the mandated PHP 8.5.10 binary — **9 passed**.
- `git diff --check` — passed.

## Phase 4C record — backup and recovery workflows

### Responsibility problem

Backups carried several different risk levels—encrypted destination setup,
connection verification, scheduled runs, isolated verification and live
restore—but presented them as dense, similarly weighted rows and forms. This
made recovery readiness and destructive consequences harder to scan, especially
on small screens.

### Boundaries and benefit

- Existing backup controllers, actions, jobs, encrypted models, verification
  state machine and provider adapters remain the workflow boundary.
- Recovery readiness is presented as a shared metric strip; destinations and
  schedules use grouped cards with progressive disclosure for setup/editing.
- Backup history keeps a responsive overflow table, but separates completion,
  verification, cleanup and restore states with semantic badges and bounded
  confirmation fields.
- Destination setup keeps provider presets, endpoint derivation guidance,
  encrypted credential handling and temporary-object verification guidance in
  the existing partial. The UI does not add a server dependency or remote call.

### Preserved contracts

All backup, restore, verification and destination routes, field names, default
values, CSRF/method fields, one-time password behavior, credential redaction,
recovery confirmations, authorization, snapshot ownership, cleanup state and
retry behavior remain unchanged. No new database writes, jobs or integrations
were introduced.

### Verification

- Focused backup matrix — **17 passed, 129 assertions**.
- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Browser asset-layout suite with the mandated PHP 8.5.10 binary — **9 passed**.
- `git diff --check` — passed.

## Phase 5A record — reporting and automation

### Responsibility problem

Activity, Command Center and Automation were operationally useful but visually
fragmented. Filter forms, metric summaries, token controls, scheduled
workflows, environment runtime controls and command history used different
surfaces and action treatments. Automation also placed a large number of
destructive or credential-sensitive controls into dense inline markup.

### Boundaries and benefit

- Existing reporting queries, command authorization and encrypted-output
  boundaries remain unchanged; shared UI components only improve their
  presentation.
- Activity and Command Center now use the shared filter card, metric component,
  table shell, status badges and empty-state hierarchy.
- Automation presents API token creation, least-privilege abilities, YAML
  workflow editing, environment runtime/scaling controls, deployment schedules
  and encrypted scheduled tasks as distinct sections with explicit labels.
- Sensitive token values remain in the existing one-time session message, and
  command text/output remain outside the reporting view.

### Preserved contracts

Existing filters, pagination, CSV entitlement behavior, command refresh links,
token abilities/expiry/rotation/revocation, YAML defaults and validation,
schedule/task field names, overlap flags, hibernation/scaling controls,
authorization ordering, dispatch semantics and flash/error behavior remain
unchanged. No business logic, writes or queue timing moved into the views.

### Verification

- Activity and command matrix — **35 passed, 302 assertions**.
- Automation matrix — **34 passed, 164 assertions**.
- Combined focused evidence — **69 passed, 466 assertions**.
- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Browser asset-layout suite with the mandated PHP 8.5.10 binary — **9 passed**.
- `git diff --check` — passed.

## Phase 5B record — observability and investigation

### Responsibility problem

Observability combined telemetry, metric rules, operational incidents, alert
destinations, public status pages, maintenance updates and investigation
context. These were presented as dense, similarly weighted blocks, making it
hard to distinguish private response actions from public communication and
read-only evidence.

### Boundaries and benefit

- Existing observability queries, bounded evidence collections, policies,
  incident transitions, notification fan-out, encrypted destinations and
  shareable investigation links remain unchanged.
- The overview now separates telemetry, correlation signals, environment
  context, alert integrations, public status pages and incident communication
  into semantic cards with clear headings and empty states.
- Operational incidents expose status/severity consistently and place timeline,
  assignment, notes and resolution controls behind progressive disclosure.
- Environment investigation uses shared cards, badges and actions while still
  showing that its links are read-only, bounded evidence and not proof of
  causation.

### Preserved contracts

Tenant scoping, policy checks, saved investigation expiry, share-link
authorization rechecks, evidence windows, service/deployment/severity filters,
log-body exclusion, alert destination fields, status-page membership,
incident/status/severity values and notification timing remain unchanged. No
new telemetry queries, writes, remote calls or queue jobs were introduced.

### Verification

- Observability and incident matrix — **40 passed, 291 assertions**.
- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Browser asset-layout suite with the mandated PHP 8.5.10 binary — **9 passed**.
- `git diff --check` — passed.

## Phase 5C record — notifications and cost visibility

### Responsibility problem

Notifications mixed filtering, saved views, bulk selection, read state and
destructive deletion in one dense screen. Cost visibility mixed estimates,
telemetry, budget thresholds, preview quotas and provider-billing caveats with
different card and alert treatments.

### Boundaries and benefit

- Existing inbox queries, Alpine selection state, policies, bulk operations,
  saved-filter persistence and notification destinations remain unchanged.
- Notifications now lead with filter and saved-view controls, summarize the
  current result set, and clearly distinguish unread severity from read state.
  Destructive actions use the shared danger treatment while bulk controls stay
  sticky and keyboard accessible.
- Cost visibility now uses the shared metric, card, alert, badge and button
  primitives. Estimate, measured utilization, unknown price, budget and
  preview-lifetime information remain visibly distinct.

### Preserved contracts

Notification filters, pagination, exports, bulk limits, Alpine field names,
read/unread/delete behavior, saved filter routes, destination links, cost
estimation semantics, budget validation/authorization, preview quota wording
and provider-invoice caveats remain unchanged. No business logic or persisted
values were changed.

### Verification

- Notification and related inbox matrix — **39 passed, 316 assertions**.
- Cost visibility matrix — **8 passed, 40 assertions**.
- Combined focused evidence — **47 passed, 356 assertions**.
- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Browser asset-layout suite with the mandated PHP 8.5.10 binary — **9 passed**.
- `git diff --check` — passed.

## Phase 2 record — application shell and navigation

### Responsibility problem

Desktop and mobile navigation each owned a separate list of destinations,
active-route rules and permission conditionals. The mobile surface presented
nearly every destination as an equal tile, while the desktop sidebar used a
long flat list. That duplication made grouping and accessibility changes easy
to miss on one surface.

### Boundaries and benefit

- `App\View\Navigation\WorkspaceNavigation` now produces one presentation
  model for grouped workspace, support, profile and administration links.
- A shared navigation-link component owns active state, route anchors, icons,
  badge rendering and responsive link treatment.
- The desktop sidebar renders grouped sections; the mobile drawer uses the same
  items with native, keyboard-accessible disclosure groups. Overview and build
  navigation remain open by default, while less frequent groups collapse to
  reduce scanning length.
- Authorization visibility remains based on the existing organization manage
  permission and platform-admin check. The navigation does not become an
  authorization boundary; routes and policies continue to enforce access.
- The unread notification count is computed once by the navigation model and
  reused by the desktop link and mobile quick action.
- Existing route names, URLs, Settings compatibility link, command palette,
  menu Escape/focus behavior, bottom quick actions and non-JavaScript forms are
  preserved.

### Verification and limitation

- `php artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run test:assets` — **9 passed**.
- `tests/Browser/navigation.spec.js` — **3 passed** at 390px, 768px and
  1440px, covering grouped access, Settings visibility, active state and
  overflow.
- `tests/Browser/visual-audit.spec.js` — mobile and desktop passed. The tablet
  crawl reached `/providers/3/edit` and exceeded its existing 900-second test
  timeout; it ended with a page crash during teardown. This is recorded as
  incomplete browser evidence, not as a pass or an application defect.

## Phase 3A record — dashboard hierarchy

### Responsibility problem

The dashboard contained many repeated, feature-specific surface treatments:
neutral cards, colored alerts, status counters and action links each used
different borders, radii, text colors and interaction affordances. That made
the most important operational signals compete visually and caused dark-mode
and responsive improvements to require dashboard-specific CSS decisions.

### Boundaries and benefit

- The dashboard remains a read-only composition of the existing server-side
  summaries; no query or business responsibility moved into the view.
- Shared cards, badges, alerts and buttons now provide the visual hierarchy for
  deployment, provisioning, provider, webhook, command, recipe and failure
  summaries.
- Interactive summary rows use the shared interactive-card treatment, while
  status panels use semantic alert tones and links retain existing destinations.
- Setup progress uses the shared surface and accent vocabulary without changing
  its dependency order, widget preferences or completion behavior.

### Preserved contracts

Dashboard queries, organization scoping, widget persistence, route parameters,
flash behavior, authorization and all displayed labels remained unchanged.
The update is presentational only; no credentials or operational data are
loaded into new components.

### Verification

- `DashboardTest`, `InfrastructureListFilterTest` and `LocalUiAssetTest` —
  **44 passed, 808 assertions**.
- `artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php
  npm run test:assets` — **9 passed**.
- `git diff --check` — passed.

## Phase 6B record — templates and community workflows

### Responsibility problem

Recipe management, the public gallery, report history and product feedback
used separate generations of cards, filters, status labels, forms and action
buttons. The resulting visual language made ownership, publication state and
review actions harder to scan, especially on narrow screens. Dynamic export
links also exposed a shared button-component escaping defect when query
parameters were present.

### Boundaries and benefit

- Recipe, gallery and feedback views remain read-only or HTTP-composition
  surfaces; existing controllers, queries, policies and actions keep their
  responsibilities.
- Shared page headers, cards, stats, badges, alerts, buttons and form sections
  now provide consistent hierarchy across recipe creation/editing, inventory,
  gallery comparison, report review and feedback inbox workflows.
- The button primitive normalizes already-encoded route query values before
  its single HTML escape, preserving safe output while preventing `&amp;amp;`
  export URLs.
- Existing legacy color classes remain on report reason badges where feature
  tests and downstream styling rely on those semantic hooks.

### Preserved contracts

Recipe ownership, publication and installation rules, report privacy and
notification behavior, feedback encryption, filters, pagination, ordering,
CSV routes, validation messages, redirects and authorization were unchanged.
No script contents, credentials or private feedback were introduced into new
visual surfaces.

### Verification

- Recipe, gallery, report, feedback, inventory and usage matrix — **111 passed,
  1,031 assertions**.
- `artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php
  npm run test:assets` — **9 passed**.
- `git diff --check` — passed.

Commit `f94c862` was pushed to `origin/main`. The next slice audits the
remaining product, public, documentation and account-adjacent pages for
unmodernized templates and safely reusable UI primitives.

## Phase 6C record — public, status and authentication surfaces

### Responsibility problem

Public entry points, authentication forms and status pages had drifted into
separate visual systems: legacy button variants, compact labels without
explicit associations, repeated alerts, and standalone status markup. This
made the most sensitive journeys—sign-in, invitation registration, password
recovery, access requests and service degradation—less consistent and harder
to use at small widths.

### Boundaries and benefit

- The authentication layout now owns the shared responsive split shell,
  heading hierarchy, error summary and recovery-focused card surface. Auth
  views retain their existing routes, fields, invitation values and named
  validation behavior.
- Public access, pricing, API reference, documentation and landing actions
  reuse the semantic button/card primitives while keeping the existing public
  content and destinations.
- Platform and workspace status views use shared alerts, badges and cards;
  they remain disclosure-safe read-only projections of the existing status
  services.
- The button stylesheet now respects responsive `hidden`/`sm:inline-flex`/
  `lg:hidden` utilities. This fixes the shared primitive’s tendency to render
  hidden navigation controls and removes 320px landing-page overflow.

### Preserved contracts

Access-request encryption, honeypot no-op behavior, registration gating,
invitation binding, password-reset privacy, social-provider visibility,
two-factor flows, status cache headers, public diagnostic redaction,
status-component query aggregation, search ownership and security headers
were unchanged. No production credentials, diagnostic details or secret form
values were added to public or auth markup.

### Verification

- Public/auth/status/search/security matrix — **87 passed, 650 assertions**.
- `LocalUiAssetTest` — **14 passed, 363 assertions**.
- `artisan view:cache` — passed.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php
  npm run test:assets` — **9 passed** after fixing the 320px overflow.
- `git diff --check` — passed.

Commit `9d46445` was pushed to `origin/main`. The next slice modernizes
remaining operational detail views and shared resource forms, starting with
repository, website, provider and server journeys.

## Remaining external scope

UI verification is local/dev evidence. Production release, live acceptance,
provider acceptance, billing and external monitoring remain separate gates.
