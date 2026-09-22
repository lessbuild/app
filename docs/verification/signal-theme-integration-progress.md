# Signal theme integration progress

## Slice 1 — shared theme and application shell

Status: implemented and pushed on `main` through commit `859e898`; the
follow-up compatibility/provider-control slice is pushed as `5df3f16`, and the
dashboard slice is complete and pushed as `26129ee`.

The application now includes the actual Signal Starter source from:

```text
/root/Documents/Codex/2026-09-21/plan-can-you-create-a-tailwindcss
```

The vendored snapshot contains Signal's theme tokens, component styles,
generated palettes and theme data under `resources/css/signal/`. BuildPusher
defaults to Signal's `graphite` palette, `subtle` corners, comfortable density,
system appearance and the existing persisted user dark-theme preference when it
is available.

The shared Laravel shell now loads Signal's theme entry points through Vite,
uses the Signal control and surface primitives, and exposes a theme toggle in
the authenticated header. Existing BuildPusher utility names remain supported
through an explicit compatibility bridge so route behavior and page migrations
can proceed incrementally without a second palette.

Preserved contracts:

- Routes, controllers, Livewire components, forms and validation behavior.
- Modal, navigation and scroll-lock JavaScript hooks.
- Existing user `preferences.theme` values (`light` and `dark`).
- Existing non-JavaScript and responsive navigation markup.
- Local-only assets and the current locked dependency set.

Evidence:

- `npm run build` — passed.
- `git diff --check` — passed.
- `php artisan view:cache` — passed.
- `php artisan test tests/Feature/LocalUiAssetTest.php --do-not-record-test-run-history` — 23 passed, 475 assertions.

Follow-up work in progress:

- Legacy semantic utility names now resolve to Signal page, surface and ink
  roles instead of colliding with Signal's accent utilities.
- Native modal and filter headers use Signal panel/control primitives.
- Provider choices use Signal's text-based `ui-choice` and `ui-check`
  components; provider tokens remain text-free from the rendered page.

Evidence for the follow-up slice:

- Provider capability, inventory filter/insight, submission feedback and
  connection insight coverage — 28 tests passed, 172 assertions.
- The browser asset runner was started with PHP 8.5.10; its previous run
  exposed and corrected the legacy `text-primary` and login-input color
  collisions. A fresh run is required after the final CSS build.

## Slice 2 — dashboard application surfaces

Status: implemented, verified locally, committed and pushed as `26129ee`.

Responsibility problem addressed:

- The dashboard still mixed the legacy BuildPusher visual vocabulary into the
  Signal shell, which made the highest-traffic page feel like a recolored
  page rather than a Signal composition.
- Operational status, provisioning, active deployments, webhooks and command
  summaries used alert-card layouts that consumed too much mobile space and
  made unrelated operational states look equally urgent.
- Dashboard statistics and trend visualizations did not use Signal's actual
  stat, chart, progress and timeline primitives.

Signal implementation:

- Dashboard hero, setup, overview, provider health and activity surfaces now
  use `ui-panel`, `ui-card`, `ui-eyebrow`, `ui-link`, `text-ink`, `text-muted`
  and Signal's surface tokens.
- Workspace totals use the Signal stat typography with a mobile-specific
  compact geometry that preserves the existing under-170px layout contract.
- Deployment and health trends use Signal's `ui-chart`/`ui-chart-bar`
  primitives; plan and setup capacity use `ui-progress`.
- Active deployments use a Signal `ui-timeline` while retaining every existing
  route, modal trigger, row limit and status count.
- Existing alert variants now retain semantic colored borders on quiet Signal
  surfaces instead of saturated full-card backgrounds.
- Existing modal hooks, dashboard ordering, authorization-scoped data, secret
  redaction and non-JavaScript links remain unchanged.

Evidence:

- `php artisan view:cache` — passed.
- `npm run build` — passed.
- `php artisan test tests/Feature/DashboardTest.php tests/Feature/LocalUiAssetTest.php --do-not-record-test-run-history` — 52 passed, 800 assertions.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npx playwright test tests/Browser/asset-layout.spec.js --grep='light at 320px' --reporter=line` — 1 passed.

The browser evidence is for the isolated local fixture runtime only. It is not
live deployment or cloud acceptance.

## Slice 3 — provider inventory surfaces

Status: implemented, verified locally, committed and pushed as `839db3b`; the
fixture/browser follow-up is committed and pushed as `eb2cc17`.

Responsibility problem addressed:

- The provider inventory still used legacy input, label, text and list-surface
  classes inside the Signal shell, so the page did not visually match the
  dashboard composition on smaller screens.
- Insights and mobile filter headers also mixed legacy semantic utilities with
  Signal controls.

Signal implementation:

- Provider filters use Signal labels and inputs while retaining the existing
  GET keys, selected values, mobile filter dialog and no-JavaScript fallback.
- The inventory uses a Signal panel with quiet hover states, ink/muted text
  roles, Signal links, eyebrows and status badges.
- Shared insights and filter dialog headers now use Signal ink, muted, line and
  focus roles without changing their open state or URL behavior.
- Provider type presentation remains text-based; no provider icon selector was
  introduced.

Preserved contracts:

- Organization scoping, filters, metrics, pagination and CSV/export links.
- Provider detail/modal routes, authorization, connection status and secret
  exclusion.

Evidence:

- Provider inventory, capability, feedback and connection insight coverage —
  28 tests passed, 172 assertions.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- `git diff --check` — passed.
- `AssetLayoutFixtureTest` — 1 test passed, 289 assertions; its detail-page
  data now uses deterministic first records without changing application
  routes.
- Targeted Playwright provider/mobile-filter coverage — 7 tests passed in the
  isolated fixture runtime.
- The webhook workflow assertion now matches the existing enabled-state label
  (`Manage webhook`) and accepts the dialog's two intentional POST forms.

The browser evidence is for the isolated local fixture runtime only. It is not
live deployment or cloud acceptance.

## Canonical dev deployment — 2026-09-21

The isolated runtime at `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`
was fast-forwarded to `00f1ea5`, rebuilt and restarted through
`buildpusher-dev-main.service` and its queue worker. The canonical development
host is `https://deployer.buildpusher.com`; the legacy `buildpusher.com` host
is not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200.
- CSS — `build/assets/app-BrOFR6HG.css`, HTTP 200, containing Signal markers
  including `--ui-page`, `.ui-panel` and `.ui-eyebrow`.
- Theme script — `build/assets/signal-theme-FzFaTKCz.js`.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

## Slice 4 — application branding

Status: implemented, deployed and verified on the canonical dev host.

The public application name is now `Deployer`. `APP_NAME`, the Laravel fallback,
browser/PWA metadata, OpenAPI title/server URL, visible page copy, email copy,
GitHub preview labels and alert payload labels use the configured application
name. Internal compatibility identifiers remain unchanged, including
`buildpusher.yaml`, Artisan command names, cache/storage namespaces, queue
headers, callback markers and provider resource names.

Preserved contracts:

- Routes, persisted values, serialized jobs, webhook signature/header names and
  existing external resource identifiers.
- The canonical dev hostname remains `deployer.buildpusher.com`; this rename
  does not change DNS or production infrastructure.

Evidence:

- Canonical `/login` — HTTP 200 with `Deployer` in the title and rendered
  branding.
- Canonical PWA manifest — HTTP 200 with `Deployer` name and short name.
- Canonical OpenAPI document — HTTP 200 with `Deployer Control Plane API` and
  the `deployer.buildpusher.com` server URL.
- Canonical `/api/health` — HTTP 200, `{"status":"ready"}`.
- Isolated web and queue services — active.

## Slice 5 — provider detail surfaces

Status: implemented, verified locally, committed, pushed and deployed as
1668b4b.

Responsibility problem addressed:

- Provider detail mixed the modern Signal shell with legacy status rows, dense
  resource lists and connection evidence that was difficult to scan on mobile.
- The detail page repeated connection policy values in one wrapping line and
  did not give attached repositories/servers the same interactive surfaces as
  the provider inventory.

Signal implementation:

- Added a quiet Signal connection-overview panel with explicit health,
  monitoring, failure-confirmation and credential-safety cards.
- Added a collapsible Signal overview insight group for provider type,
  attached-resource counts and retained checks.
- Presented retained checks as a responsive Signal timeline while preserving
  the existing 20-item limit, failure-streak expansion and secret-safe
  evidence.
- Updated resource lists and the connection-history fragment to use Signal
  panels, labels, inputs, muted text roles and interactive cards.

Preserved contracts:

- Provider authorization, organization scoping, pagination and query
  collaborators.
- Connection-test forms, flash feedback, exact status/failure copy, modal
  history URLs, filter keys, export URLs and CSRF behavior.
- Credential encryption and exclusion of secrets/response bodies from page
  history.

Evidence:

- Provider-focused PHP coverage — 39 tests passed, 418 assertions.
- Targeted provider/mobile browser coverage — 7 tests passed in the isolated
  fixture runtime.
- php artisan view:cache — passed.
- php vendor/bin/pint --test — passed.
- npm run build — passed.
- git diff --check — passed.

The browser and served-host evidence is for the isolated local/development
runtime only. It is not live deployment or cloud acceptance.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 1668b4b, rebuilt and restarted through buildpusher-dev-main.service and its
queue worker. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- CSS — build/assets/app-XhdAzCPn.css, HTTP 200, containing Signal markers
  including --ui-page, .ui-panel and .ui-eyebrow.
- Theme script — build/assets/signal-theme-FzFaTKCz.js.
- /manifest.webmanifest — Deployer name and short name.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: modernize the standalone provider connection-history page shell,
reuse the shared Signal fragment without changing filter/pagination/export
semantics, then continue through the remaining provider-management surfaces.

## Slice 6 — standalone provider connection history

Status: implemented, verified locally, committed and pushed as `b1894a5`.

Responsibility problem addressed:

- The standalone connection-history route still used a generic page heading and
  loose spacing even though its shared history content had moved into the
  Signal surface used by the provider detail dialog.
- Browser fixtures only represented the modal fragment, so the standalone
  page shell did not have direct responsive coverage.

Signal implementation:

- Added the Provider operations eyebrow and activity icon to the standalone
  page header and tightened the page-to-content spacing.
- Added a distinct full-page fixture alongside the fragment fixture so the
  browser suite verifies both delivery modes without conflating their markup.
- Added a mobile browser assertion for the full page, shared retained-evidence
  insights, default filter state and canonical route.

Preserved contracts:

- Existing provider authorization, organization scoping, filter names,
  pagination, result/source/date semantics and CSV export behavior.
- The fragment route remains available for the contextual provider dialog;
  only the standalone shell and its fixture coverage changed.

Evidence:

- `ProviderConnectionHistoryTest` — 7 tests passed, 103 assertions.
- Standalone provider connection-history browser check — 1 passed in the
  isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `b1894a5`, rebuilt and restarted through
`buildpusher-dev-main.service` and its queue worker. The canonical development
host is https://deployer.buildpusher.com; the legacy buildpusher.com host is
not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/manifest.webmanifest` — `Deployer` name and short name.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the remaining provider create/edit and connection-management
surfaces for the next cohesive Signal modernization slice, preserving the
existing modal and no-JavaScript workflows.

## Slice 7 — provider create/edit form controls

Status: implemented, verified locally, committed and pushed as `d0a6f17`.

Responsibility problem addressed:

- Provider create and edit forms mixed Signal provider choices with legacy
  inputs, labels, select controls, footer surfaces and a saturated validation
  summary, making the modal feel like two visual systems and increasing visual
  noise on mobile.

Signal implementation:

- Standardized token, name, description, monitoring and select controls on
  `ui-input`, `ui-label`, `ui-help` and Signal text roles.
- Kept provider selection as accessible native text radio controls, with the
  existing seven provider options and server-rendered no-JavaScript behavior.
- Kept monitoring secondary and collapsible on mobile while giving it the
  shared Signal card treatment.
- Changed GitHub guidance and validation feedback to quiet surfaces with a
  colored border edge instead of full-background alert cards.
- Added browser assertions for the themed controls and the contained
  monitoring card.

Preserved contracts:

- All form IDs, names, defaults, entitlement-disabled states, validation keys,
  old-input behavior, secret exclusion, routes, modal URLs and flash feedback.
- Native form submission, modal body scrolling and the existing monitoring
  disclosure behavior.

Evidence:

- `ProviderSubmissionFeedbackTest` — 7 tests passed, 37 assertions.
- `CreationDialogTest` — 20 tests passed, 134 assertions.
- `AutomaticMonitoringControlTest` — 4 tests passed, 42 assertions.
- Provider browser form/scroll/no-JavaScript coverage — 4 tests passed.
- The focused themed provider-form browser check — 1 passed.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `d0a6f17`, rebuilt and restarted through
`buildpusher-dev-main.service` and its queue worker. The canonical development
host is https://deployer.buildpusher.com; the legacy buildpusher.com host is
not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/manifest.webmanifest` — `Deployer` name and short name.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect provider connection controls and resource attachment
surfaces, then modernize the next cohesive provider-management workflow.

## Slice 8 — provider detail actions and resource attachments

Status: implemented, verified locally, committed and pushed as `6689a64`.

Responsibility problem addressed:

- The provider detail page still mixed a raw destructive button with shared
  action primitives, and attached-resource cards had no compact count or
  mobile-specific hierarchy.
- Connection feedback and empty resource states did not consistently use the
  quiet colored-edge treatment used elsewhere in the Signal shell.

Signal implementation:

- Added a Provider integration eyebrow and an accessible connection-action
  label to make the page header easier to scan.
- Replaced the raw delete trigger with the shared danger button component.
- Added resource-count badges, shrink-safe headings and compact mobile action
  buttons to attached repository/server panels.
- Hid secondary creation timestamps on narrow screens while preserving them
  at larger widths, and applied the colored-edge alert treatment to feedback
  and empty states.
- Added a focused mobile browser journey for provider actions and attachments.

Preserved contracts:

- Provider authorization, delete dialog behavior, connection-test POST route,
  modal URLs, pagination, resource links and organization-scoped queries.
- Resource data, counts and timestamps remain unchanged; only responsive
  presentation changed.

Evidence:

- `ProviderConnectionTest` plus `CreationDialogTest` — 30 tests passed, 276
  assertions.
- Provider detail mobile browser journey — 1 passed in the isolated fixture
  runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `6689a64`, rebuilt and restarted through
`buildpusher-dev-main.service` and its queue worker. The canonical development
host is https://deployer.buildpusher.com; the legacy buildpusher.com host is
not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: audit the remaining provider inventory/detail links and modal
loading paths for stale legacy labels or background-refresh behavior, then
modernize the next verified workflow.

## Slice 9 — provider legacy-surface cleanup

Status: implemented, verified locally, committed and pushed as `a8f324c`.

Responsibility problem addressed:

- A final provider audit found legacy semantic utility names in the provider
  inventory, provider choices and lazy edit-dialog loading state. They were
  visually bridged by compatibility CSS but made future maintenance and visual
  review harder.

Signal implementation:

- Replaced provider-choice text roles with `text-ink`, inventory dividers with
  `divide-line`, and lazy edit feedback with `text-muted`.
- Added a scoped browser assertion that the provider dialog no longer renders
  legacy `.input.secondary` controls while allowing other pre-rendered global
  dialogs to retain their independent migration state.

Preserved contracts:

- Provider labels, option values, field IDs, dialog loading behavior, filter
  URLs, inventory ordering, authorization and no-JavaScript fallback.

Evidence:

- `ProviderInventoryFilterTest` — 5 tests passed, 40 assertions.
- `ProviderInventoryInsightsTest` — 3 tests passed, 19 assertions.
- `CreationDialogTest` — 20 tests passed, 134 assertions.
- Provider form browser check — 1 passed in the isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `a8f324c` and its caches were rebuilt before restarting
`buildpusher-dev-main.service` and its queue worker. The canonical development
host is https://deployer.buildpusher.com; the legacy buildpusher.com host is
not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inventory the next high-value UI family after providers—websites,
repositories and their deployment/timeline surfaces—before choosing the next
cohesive modernization slice.

## Slice 10 — website inventory surfaces

Status: implemented, verified locally, committed and pushed as `021d3d9`.

Responsibility problem addressed:

- Website inventory filters and cards still mixed legacy inputs, labels,
  checkbox wrappers, dividers and text roles with the Signal insights shell.
- The dense mobile inventory did not give its two boolean filters the same
  choice-card treatment as provider selection.

Signal implementation:

- Standardized search/select controls and labels on `ui-input` and `ui-label`.
- Rendered attention/provisioning filters as accessible native checkboxes inside
  Signal choice cards, preserving their GET names and checked state.
- Moved the inventory to the Signal panel/divider/link/text roles and kept the
  existing status badges, server links and provisioning/health copy.
- Added a mobile fixture journey covering the filter controls and website card
  hierarchy, and extended the bottom-sheet filter contract to websites.

Preserved contracts:

- Filter keys, omitted/null handling, selected/checked rendering, organization
  scoping, pagination, export links, modal creation triggers and inventory
  routes.
- Existing attribute ordering needed by server-rendered compatibility tests.

Evidence:

- `InfrastructureListFilterTest` — 8 tests passed, 68 assertions.
- `WebsiteInventoryExportTest` — 3 tests passed, 44 assertions.
- `CreationDialogTest` — 20 tests passed, 134 assertions.
- Website filter bottom-sheet plus inventory browser journeys — 2 passed in the
  isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `021d3d9` and its caches were rebuilt before restarting
`buildpusher-dev-main.service` and its queue worker. The canonical development
host is https://deployer.buildpusher.com; the legacy buildpusher.com host is
not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: modernize the website detail page’s operations and health sections,
preserving Livewire setup/provisioning logs, health history filters and runtime
log behavior.

## Slice 11 — website operations and health evidence

Status: implemented, verified locally, committed and pushed as `0699d72`.

Responsibility problem addressed:

- The website detail page mixed legacy text/control utilities and a raw delete
  trigger into the page identity, provisioning timeline and health evidence
  path.
- The standalone and modal health-history views did not share the same Signal
  filter, evidence-card and status presentation as provider history.

Signal implementation:

- Added a Delivery target eyebrow and shared danger button for the website
  detail action cluster.
- Converted website notices to quiet colored-edge alerts and standardized the
  overview and provisioning timeline on Signal ink/muted/line roles.
- Composed health evidence as a Signal panel with retained-check insights and
  responsive health-check cards.
- Standardized the full-page/modal health-history filters on Signal inputs and
  labels while retaining the fragment enhancement path.
- Added mobile browser coverage for the page panels and the health modal’s
  four filter controls.

Preserved contracts:

- Provisioning/placement alerts, Livewire setup and provisioning-log mounts,
  health check and export routes, modal history URLs, filter names, pagination,
  retained-sample limits, escaping and authorization.
- Destructive workflow, password notice, retry behavior and open-state rules.

Evidence:

- `WebsiteHealthHistoryTest` — 11 tests passed, 113 assertions.
- `WebsiteHealthInsightsTest` — 3 tests passed, 19 assertions.
- `WebsiteProvisioningRetryTest` — 4 tests passed, 25 assertions.
- `CreationDialogTest` — 20 tests passed, 134 assertions.
- Website health modal and detail-panel browser journeys — 2 passed in the
  isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `0699d72` and its caches were rebuilt before restarting
`buildpusher-dev-main.service` and its queue worker. The canonical development
host is https://deployer.buildpusher.com; the legacy buildpusher.com host is
not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: modernize website runtime-log controls and attached-repository
surfaces, preserving bounded log fetching, Alpine tab behavior, refresh routes,
retention dialogs and repository modal creation.

## Slice 12 — website runtime logs and attached repositories

Status: implemented, verified locally, committed and pushed as `92c2194`.

Responsibility problem addressed:

- The website runtime-log disclosure still mixed legacy cards, controls, text
  roles and borders with the Signal website detail surfaces.
- Runtime search, level and live-refresh controls were visually inconsistent
  and the icon-only/utility treatment made the operational area harder to scan
  on a narrow viewport.
- Attached repositories used the older inventory treatment instead of the same
  compact, link-forward panel hierarchy used by the rest of the website page.

Signal implementation:

- Converted the runtime disclosure and retention divider to Signal panels and
  line borders, with muted supporting copy and compact action buttons.
- Reused the shared button, input, choice and check primitives for log tabs,
  search, level, live refresh and refresh actions; added accessible labels and
  selected-state semantics without changing Alpine behavior.
- Updated the retention dialog to use the shared input/help treatment and
  clarified that the setting applies to future snapshots.
- Reworked the attached-repository panel with Signal eyebrow, ink/muted text,
  line dividers, compact modal action and mobile-safe repository links.
- Added a mobile browser journey covering both runtime controls and attached
  repository navigation.

Preserved contracts:

- Runtime-log snapshot routes, POST refresh behavior, bounded output, live
  polling, application/access tabs, filtering, retention values and dialog
  query parameters.
- Existing repository modal creation URL/content loading, repository detail
  links, pagination, status badges, authorization and empty-state behavior.
- Existing copy, form names, no-JavaScript fallback, secret-safe log handling
  and the open-state rule for pending/failed snapshots.

Evidence:

- `WebsiteHealthHistoryTest`, `ObservabilityTest` and `CreationDialogTest` —
  53 tests passed, 454 assertions.
- Website health, detail, runtime/repository and deployment/retention browser
  journeys — 5 passed in the isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `92c2194` and its configuration, route and view caches were rebuilt before
restarting `buildpusher-dev-main.service` and its queue worker. The canonical
development host is https://deployer.buildpusher.com; the legacy buildpusher.com
host is not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inventory the repository detail page’s deployment, timeline,
webhook and configuration surfaces, then modernize the smallest cohesive slice
without changing deployment idempotency or webhook behavior.

## Slice 13 — repository deployment and webhook surfaces

Status: implemented, verified locally, committed and pushed as `91fc29c`.

Responsibility problem addressed:

- Repository deployment overview, first-launch guidance, source layout and
  deployment history still used the pre-Signal card, input, border and text
  vocabulary.
- Webhook setup, delivery filters and delivery inspection were visually
  inconsistent with the repository timeline and exposed too much density on
  narrow screens.
- The repository delete trigger was the remaining raw danger button on the
  page, making the action cluster inconsistent with shared controls.

Signal implementation:

- Converted deployment summary, first-deployment guidance, layout metadata,
  timeline, insights and recent history to Signal panels, eyebrows, ink/muted
  roles, line dividers and compact action buttons.
- Standardized webhook payload/secret fields and delivery filters on shared
  labels and inputs; kept the delivery history disclosure and its metrics
  visible only when its existing attention rules require it.
- Updated delivery cards and the modal inspector with safe link styling,
  quiet colored-edge feedback and mobile-safe metadata layout.
- Reused the shared danger button for repository deletion and the shared
  primary button for webhook enable/rotation while preserving confirmation
  prompts.
- Hardened the browser fixture’s delivery selector for multiple retained
  deliveries and added a mobile repository deployment/webhook journey.

Preserved contracts:

- Deployment forms, disabled states, preflight and guidance copy, Livewire
  deployment timeline mount, insight links, history open-state rules and
  organization-scoped build data.
- Webhook enable/rotate/disable routes, provider-specific signing behavior,
  secret non-disclosure, filters, CSV export, pagination, delivery modal URLs,
  fragment loading, replay/idempotency behavior and safe payload handling.
- Existing no-JavaScript modal URLs and exact response/redirect behavior.

Evidence:

- `RepositoryDeploymentInsightsTest`, `RepositoryDeploymentTest`,
  `RepositoryWebhookTest`, `RepositoryWebhookDeliveryHistoryTest` and
  `CreationDialogTest` — 55 tests passed, 465 assertions.
- Repository edit/webhook dialogs, delivery inspector, mobile deployment and
  webhook hierarchy, and impact-preview browser journeys — 4 passed in the
  isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

The first focused run caught a Blade parse error introduced while converting
the conditional webhook confirmation button; the conditional component was
corrected and the complete focused suite was rerun successfully before the
commit.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `91fc29c` and its configuration, route and view caches were rebuilt before
restarting `buildpusher-dev-main.service` and its queue worker. The canonical
development host is https://deployer.buildpusher.com; the legacy buildpusher.com
host is not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inventory the build detail and deployment-history surfaces, then
modernize their timeline, evidence, notes and recovery controls as the next
cohesive repository/deployment slice.

## Slice 14 — build deployment evidence and timeline

Status: implemented, verified locally, committed and pushed as `dcd779b`.

Responsibility problem addressed:

- The build detail Livewire surface still mixed legacy cards, borders, text
  roles, fields and alerts across deployment evidence, recovery guidance,
  approval, promotion, observation, health and log sections.
- The deployment timeline was visually subordinate to older execution-oriented
  presentation even though it is the authoritative milestone view.
- Operator notes and milestone entries did not use the shared Signal form and
  evidence treatment.

Signal implementation:

- Standardized build summary metadata, immutable revision links and identity/
  approval context on Signal ink/muted/eyebrow roles.
- Made deployment timeline, recovery guidance, release/commit context,
  promotion history, observation and application-health surfaces use panels,
  line borders and compact actions.
- Kept the deployment log as the bounded dark console, but modernized its
  disclosure, download action, waiting state and surrounding controls.
- Updated timeline milestones and the operator-note modal with shared Signal
  card, label, input and help primitives.
- Preserved the existing single timeline workflow; no execution-checkpoint
  section was reintroduced.
- Hardened build modal browser coverage to resolve the fixture’s canonical
  build-history URL and added Signal panel assertions to the 390px audit.

Preserved contracts:

- Livewire polling, status transitions, bounded/escaped deployment output,
  signed log callbacks, download headers, cancellation, retry/redeploy,
  approval/rejection, rollback, promotion lineage and stale-state behavior.
- Exact modal URLs, comparison and health-history fragments, operator-note
  error bag, named routes, no-JavaScript fallbacks and authorization.
- Existing open-state rules: active/failed work receives attention while
  completed deployments remain concise.

Evidence:

- `DeploymentLogTest`, `DeploymentComparisonTest`, `DeploymentApprovalTest`,
  `DeploymentCancellationTest`, `DeploymentHistoryNavigationTest`,
  `BuildRedeploymentTest`, `BuildPromotionTest` and `CreationDialogTest` —
  63 tests passed, 518 assertions.
- Build comparison and health-history modal journeys plus the light 390px
  layout audit — 3 passed in the isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

The browser fixture’s historical `/builds/2` alias was retained for coverage,
while assertions now follow the rendered build’s canonical modal-history URL;
this avoids treating fixture ID drift as an application navigation failure.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `dcd779b` and its configuration, route and view caches were rebuilt before
restarting `buildpusher-dev-main.service` and its queue worker. The canonical
development host is https://deployer.buildpusher.com; the legacy buildpusher.com
host is not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/assets/app-CiFQClWv.css` — HTTP 200.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: modernize the deployment-history inventory at `/builds`, preserving
its filters, metrics, CSV export, pagination, status semantics and query bounds.

## Slice 15 — deployment-history inventory

Status: implemented and verified locally; code committed and pushed as
`82e7a42`.

Responsibility problem addressed:

- The `/builds` inventory still mixed legacy input, label, checkbox, metric and
  result-list styling with the Signal surfaces used by the adjacent provider,
  website and repository inventories.
- Deployment history is a high-volume operational screen, so its filters,
  metrics and result cards needed a consistent mobile-first hierarchy without
  changing the underlying query or export responsibilities.

Signal implementation:

- Replaced legacy filter controls with shared Signal labels, inputs, choices
  and checkboxes while preserving every field name, value, default, selection
  and filter-sheet behavior.
- Reused the shared `ui.stat` primitive for all six filter-aware deployment
  metrics.
- Changed the history container to the shared panel/divider treatment and
  aligned deployment metadata with Signal ink, muted and eyebrow roles.
- Added a full `/builds` browser fixture and mobile coverage for the filter
  sheet, six insights and deployment cards.

Preserved contracts:

- Filter keys and combinations, latest/active semantics, organization
  scoping, status and trigger labels, CSV export URLs, pagination links,
  status badge tones, duration states, operator-note display and build-card
  routes.
- Existing empty-state behavior, deliberate 404/authorization handling,
  fragment history rendering and query-count/query-bound behavior.

Evidence:

- `BuildHistoryInsightsTest`, `BuildHistoryFilterTest`,
  `BuildHistoryExportTest` and `DeploymentHistoryNavigationTest` — 27 tests
  passed, 240 assertions.
- The focused mobile filter, website inventory regression and deployment
  history browser journeys — 3 passed in the isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

The first browser run exposed only a fixture selector assumption: the shared
filter test derived `builds-filters`, while the existing page contract uses
`deployment-filters`. The test now maps that intentional ID explicitly; the
application behavior was unchanged.

Push status: `82e7a42` is on `origin/main`.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `34048cd` and its application, configuration, route and view caches were
rebuilt before restarting `buildpusher-dev-main.service` and its queue worker.
The canonical development host is https://deployer.buildpusher.com; the
legacy buildpusher.com host is not the verification target for this
application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/manifest.json` — HTTP 200 with the current Deployer asset manifest.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.
- Unauthenticated `/builds` correctly resolves to the existing sign-in
  boundary; no history data is exposed without a session.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is
isolated development evidence, not production or external-provider
acceptance.

Next task: inspect the remaining inventory pages and select the next cohesive
operational surface to modernize, starting with commands or the next
deployment-adjacent page while preserving its existing modal and authorization
contracts.

## Slice 16 — command-center surfaces

Status: implemented and verified locally; code committed and pushed as
`1ce1cca`.

Responsibility problem addressed:

- The global Command Center and its dashboard active-command fragment still
  mixed legacy controls, text roles, borders and card surfaces with the Signal
  primitives used by the surrounding operational inventories.
- Command metadata is intentionally bounded and secret-safe, so the visual
  refresh needed to improve scanability without exposing command text or
  retained output or changing the existing modal flow.

Signal implementation:

- Replaced global command filters with shared Signal labels, inputs, choices
  and checkboxes while preserving filter names, values, defaults and the
  native mobile filter sheet.
- Kept the existing six command insights and aligned them with the shared
  responsive stat surface.
- Converted command history to the shared panel/divider inventory treatment,
  with compact execution metadata and server-history actions.
- Updated the dashboard active-command fragment with the same eyebrow, ink,
  muted and card hierarchy while retaining its lazy, read-only dialog.
- Extended browser coverage to the global command page and the shared mobile
  filter harness.

Preserved contracts:

- Verified-account requirements, organization scoping, filter normalization,
  pagination and export URLs, active-command refresh behavior, execution
  status/output badges, bounded metadata, focused server-history links and
  secret/output non-disclosure.
- Existing dashboard dialog URLs, focus restoration, no-JavaScript links and
  server command-history/output boundaries.

Evidence:

- `CommandCenterTest` and `ServerCommandHistoryInsightsTest` — 13 tests
  passed, 133 assertions.
- Dashboard active-command dialog, mobile filter coverage and full command
  center inventory journey — 3 passed in the isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

Push status: `1ce1cca` is on `origin/main`.

Next task: deploy the command-center slice to the isolated Deployer runtime,
then modernize the server-scoped command history page and retained-output
workflow as its own cohesive slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `cc892e2` and its application, configuration, route and view caches were
rebuilt before restarting `buildpusher-dev-main.service` and its queue worker.
The canonical development host is https://deployer.buildpusher.com; the
legacy buildpusher.com host is not the verification target for this
application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/manifest.json` — HTTP 200 with the current Deployer asset manifest.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is
isolated development evidence, not production or external-provider
acceptance.

Next task: modernize the server-scoped command history page and retained-output
workflow while preserving its focused execution URLs, output modal loading,
download behavior, pagination and authorization boundaries.

## Slice 17 — server-scoped command history

Status: implemented and verified locally; code committed and pushed as
`832fb02`.

Responsibility problem addressed:

- Server command history still used legacy filter controls, metadata roles and
  card surfaces even though it is the source page for the command-center
  workflow and its contextual dialogs.
- The same presentation gap existed in the server-history fragment and the
  lazy retained-output inspector, making mobile operation and output review
  less consistent.

Signal implementation:

- Standardized server-history filters with Signal panels, labels and inputs.
- Converted command records to a panel/divider inventory with readable
  command metadata, compact actions and consistent status hierarchy.
- Preserved a distinct dark console treatment for retained output while
  moving its command context and download affordance to Signal surfaces.
- Updated the read-only server-history fragment with the same card and
  metadata treatment.
- Added mobile browser assertions for the six server insights, four filters,
  history panel, contextual dialog and lazy output inspector.

Preserved contracts:

- Focused execution query parameters, status/output/date filters, pagination,
  CSV/download URLs, cancel/rerun/delete actions and confirmation prompts.
- Server ownership and nested execution scoping, output redaction from
  history, lazy output loading, escaped output rendering, modal URL/history
  behavior, focus restoration and no-JavaScript download links.

Evidence:

- `ServerCommandHistoryInsightsTest` and `ServerCommandLifecycleTest` — 23
  tests passed, 311 assertions.
- Server command-history and retained-output mobile browser journeys — 2
  passed after hardening fixture selectors for multiple retained executions.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

Push status: `832fb02` is on `origin/main`.

Next task: deploy the server-command slice to the isolated Deployer runtime,
then inspect the remaining operational inventories for the next cohesive
Signal modernization boundary.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `7cfdf81` and its application, configuration, route and view caches were
rebuilt before restarting `buildpusher-dev-main.service` and its queue worker.
The canonical development host is https://deployer.buildpusher.com; the
legacy buildpusher.com host is not the verification target for this
application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/manifest.json` — HTTP 200 with the current Deployer asset manifest.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is
isolated development evidence, not production or external-provider
acceptance.

Next task: inspect remaining operational inventory pages and select the next
cohesive Signal modernization slice, preserving each page's existing
authorization, filters, exports and modal contracts.

## Slice 18 — activity and audit surfaces

Status: implemented and verified locally; code committed and pushed as
`2257aaf`.

Responsibility problem addressed:

- The shared activity feed still carried legacy panel, divider, label and text
  roles even though it is reused by the full audit page, dashboard activity
  dialog and account-audit dialog.
- The full activity filter controls were also inconsistent with the Signal
  filter sheets used by the other operational inventories.

Signal implementation:

- Replaced activity search/category/date controls with shared Signal labels
  and inputs without changing their request keys or normalization.
- Modernized the shared event feed with a panel/divider inventory, responsive
  event articles, readable category eyebrows, ink/muted roles and accessible
  activity-feed/event hooks.
- Aligned workspace-activity and account-audit fragments with the same
  heading hierarchy while preserving their read-only modal boundaries.
- Added a full activity fixture and mobile browser coverage for filters,
  seven insights and event history.
- Updated the existing UI contract assertion to recognize both native buttons
  and the established typed `x-ui.button` modal trigger component; it still
  requires an explicit `type="button"` and delete trigger.

Preserved contracts:

- Owner scoping, category/search/date filtering and normalization, pagination,
  CSV export entitlement behavior, event links, deleted-subject readability,
  escaped event text and secret-free command activity.
- Dashboard and account dialog URLs, lazy fragment loading, focus behavior,
  modal content boundaries and current navigation semantics.

Evidence:

- `ActivityInsightsTest` and `ActivityFeedTest` — 16 tests passed, 106
  assertions.
- `LocalUiAssetTest` — 23 tests passed, 470 assertions.
- Account-audit dialog, dashboard activity dialog and full activity mobile
  inventory journeys — 3 passed in the isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

Push status: `2257aaf` is on `origin/main`.

Next task: deploy the activity slice to the isolated Deployer runtime, then
inspect the remaining operations pages for the next cohesive modernization
boundary.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `dafbf77` and its application, configuration, route and view caches were
rebuilt before restarting `buildpusher-dev-main.service` and its queue worker.
The canonical development host is https://deployer.buildpusher.com; the
legacy buildpusher.com host is not the verification target for this
application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/manifest.json` — HTTP 200 with the current Deployer asset manifest.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is
isolated development evidence, not production or external-provider
acceptance.

Next task: inspect the remaining operations pages and select the next cohesive
Signal modernization boundary, preserving authorization, filters, exports,
modal contracts and any secret-safe disclosure behavior.

## Slice 19 — system-health diagnostic surfaces

Status: implemented and verified locally; code committed and pushed as
`ee607b0`.

Responsibility problem addressed:

- The system-health page already received sanitized diagnostic data from
  `OperationalDiagnostics`, but its summary, check cards and operator guidance
  still used legacy panel, heading and text roles.
- The page had no mobile browser assertion protecting the compact diagnostic
  snapshot as the operational surface continues to evolve.

Signal implementation:

- Aligned the current-status summary with the shared eyebrow, ink and muted
  hierarchy and retained the existing success/danger alert semantics.
- Replaced the diagnostic check and failure-guidance wrappers with the shared
  border-on-ink panel treatment without changing the diagnostic payload,
  headings or safe disclosure wording.
- Added a mobile browser assertion covering the summary, four insight stats,
  check panels and operator guidance panel.

Preserved contracts:

- Verified-account and owner/admin authorization, no-store fragment behavior,
  private report downloads, escaped diagnostic text and application-key
  redaction.
- Existing routes, response formats, status wording, check ordering and
  operational guidance remain unchanged.

Evidence:

- `SystemHealthPageTest` — 7 tests passed, 69 assertions.
- `LocalUiAssetTest` — 23 tests passed, 470 assertions.
- Dashboard system-health dialog and mobile diagnostic snapshot journeys — 2
  passed in the isolated fixture runtime.
- `php artisan view:cache` — passed.
- `php vendor/bin/pint --test` — passed.
- `git diff --check` — passed.

Push status: `ee607b0` is on `origin/main`.

Next task: deploy the system-health slice to the isolated Deployer runtime,
then inspect notifications and remaining operations inventories for the next
cohesive Signal modernization boundary.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to `91bf8e7`. Application, configuration, route and view caches were rebuilt,
then `buildpusher-dev-main.service` and its queue worker were restarted. The
canonical development host is https://deployer.buildpusher.com; the legacy
buildpusher.com host is not the verification target for this application.

Served-runtime evidence:

- `/login` — HTTP 200 with title `Sign in to your account · Deployer`.
- `/build/manifest.json` — HTTP 200 with the current Deployer asset manifest,
  including `assets/app-CiFQClWv.css`.
- `/api/health` — HTTP 200, `{"status":"ready"}`.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted `deploy/Caddyfile` change;
the application fast-forward did not overwrite it. This deployment is
isolated development evidence, not production or external-provider
acceptance.

Next task: inspect notifications and the remaining operational inventory
surfaces for the next smallest cohesive Signal modernization slice.

## Slice 20 — notification inbox

Status: implemented and verified locally; code committed and pushed as
'2e5a39b'.

Responsibility problem addressed:

- The notification page already had dedicated query, exporter, destination,
  request, policy and state-action boundaries, but its main inbox still used
  legacy cards, saturated bulk controls and legacy form inputs.
- The mobile page therefore gave alerts, bulk actions and secondary filters
  equal visual weight and required unnecessary scrolling to understand the
  current inbox.

Signal implementation:

- Converted the alert list into one bordered Signal inventory panel with
  compact rows, quiet surfaces, readable ink/muted hierarchy and colored
  status edges only for unread failure, recovery and information alerts.
- Made the bulk toolbar a quiet sticky panel with theme-aware primary edge,
  shared check controls and the existing action names and confirmation copy.
- Replaced filter inputs and select controls with shared Signal labels and
  inputs while preserving every query key, option value and disclosure state.
- Converted filter and saved-filter sections to panels and saved presets to
  compact chips without changing their URLs, deletion forms or dialog
  triggers.
- Added deterministic unread/read fixture notifications and mobile browser
  coverage for the populated inbox instead of testing only the empty state.

Preserved contracts:

- Notification ownership, destination resolution, read/unread/delete actions,
  bulk limits, saved-filter normalization, pagination, CSV export and private
  payload redaction.
- Existing route names, validation keys, flash messages, confirmation text,
  read-notification disclosure behavior and URL-backed save-filter dialog.

Evidence:

- 'NotificationInboxInsightsTest' — 6 tests passed, 31 assertions.
- 'NotificationBulkActionTest' — 6 tests passed, 41 assertions.
- 'LocalUiAssetTest' — 23 tests passed, 470 assertions.
- Notification dialog and populated mobile inbox journeys — 2 passed in the
  isolated fixture runtime.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '2e5a39b' is on 'origin/main'.

Next task: deploy the notification inbox to the isolated Deployer runtime,
then inspect backups and the remaining operational inventory pages for the
next smallest cohesive modernization boundary.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to ac1b3a8. Application, configuration, route and view caches were rebuilt,
then buildpusher-dev-main.service and its queue worker were restarted. The
canonical development host is https://deployer.buildpusher.com; the legacy
buildpusher.com host is not the verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-CiFQClWv.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect backup inventory, destination and schedule surfaces for the
next cohesive Signal modernization slice.

## Slice 21 — backup recovery surfaces

Status: implemented and verified locally; code committed and pushed as
'ff757e9'.

Responsibility problem addressed:

- Backup actions, destination verification, restore requests and recovery
  evidence were already separated in actions and services, but the page
  presentation still mixed legacy cards, colored surfaces and dense setup
  controls.
- The recovery workflow lacked a compact mobile overview tying readiness,
  destinations, schedules and history together.

Signal implementation:

- Added compact local navigation for overview, destinations, schedules and
  history.
- Converted readiness, destination, schedule and history containers to quiet
  Signal panels with ink/muted hierarchy and border-only status emphasis.
- Modernized destination and schedule rows, run-backup controls and the
  responsive recovery card without changing their actions or disclosures.
- Replaced destination and schedule modal form controls with shared labels,
  inputs and helper text while retaining provider guidance, Spaces endpoint
  instructions and secret-safe fields.
- Added a completed fixture snapshot and a mobile browser assertion covering
  recovery evidence, destination inventory and verification/restore controls.

Preserved contracts:

- Encrypted destination credentials, provider preset behavior, endpoint
  derivation, temporary-object HTTPS verification, sanitized failures and
  no-server verification semantics.
- Restore confirmation, isolated verification boundaries, retained snapshot
  safeguards, schedule timing/retention values, modal URLs, validation keys,
  error reopening and existing response/flash behavior.

Evidence:

- Backup recovery, destination, verification and managed-backup coverage — 27
  tests passed, 231 assertions.
- Backup schedule/destination dialog and populated mobile recovery journeys — 2
  passed in the isolated fixture runtime.
- npm run build — passed.
- php artisan view:cache — passed.
- php vendor/bin/pint --test — passed.
- git diff --check — passed.

Push status: 'ff757e9' is on 'origin/main'.

Next task: deploy the backup recovery slice and rebuilt assets to the isolated
Deployer runtime, then inspect servers or domains for the next smallest
cohesive inventory modernization boundary.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 609b5dd. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-CkPnluC5.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect servers and domains inventory surfaces for the next
cohesive Signal modernization boundary.

## Slice 22 — domain management

Status: implemented and verified locally; code committed and pushed as
'44cd3f9'.

Responsibility problem addressed:

- Domain actions were rendered below the overview metrics, forcing a user to
  scan past the page summary before adding or issuing a domain.
- Website/domain inventory rows and both domain dialogs still used legacy
  cards, dividers, labels and inputs.

Signal implementation:

- Moved Add domain and Issue temporary domain into the page header so the
  primary actions are immediately available on desktop and mobile.
- Added compact local navigation for overview and inventory.
- Converted website groups and domain rows to quiet panels, border dividers,
  ink/muted text roles and responsive hover states.
- Updated add-domain and temporary-domain dialog controls to shared Signal
  labels, inputs and border treatment without changing their field contracts.
- Added a mobile browser assertion that checks action placement above the
  overview and the inventory primitive.

Preserved contracts:

- Workspace deploy authorization, scoped website selection, hostname/type/
  redirect validation, Cloudflare provider selection and temporary-domain
  configuration safeguards.
- DNS sync/delete behavior, primary-domain protection, queued proxy updates,
  exact validation/error responses, dialog URLs and existing status/flash
  behavior.

Evidence:

- 'DomainManagementTest' and 'LocalUiAssetTest' — 31 tests passed, 525
  assertions.
- Domain action placement and existing dialog journeys — 1 focused browser
  test passed in the isolated fixture runtime.
- 'npm run build' — passed.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '44cd3f9' is on 'origin/main'.

Next task: deploy the domain modernization and rebuilt assets to the isolated
Deployer runtime, then inspect the server inventory as the next operations
surface.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 619b5c5. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-CkPnluC5.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the server inventory, filtering and provisioning status
surfaces for the next cohesive Signal modernization boundary.

## Slice 23 — server inventory and provisioning form

Status: implemented and verified locally; code committed and pushed as
'74fccba'.

Responsibility problem addressed:

- Server inventory data, filters and export semantics were already isolated in
  query/export collaborators, but the page still used legacy labels, inputs,
  dividers and row text hierarchy.
- The create-server modal used the same legacy controls and a saturated
  recipe selector, making infrastructure setup unnecessarily dense on mobile.

Signal implementation:

- Replaced server search/status controls with shared labels, inputs and a
  semantic provisioning-only choice.
- Converted the inventory to a quiet panel with border dividers, hoverable
  rows, ink/muted details and eyebrow metadata.
- Modernized the shared server provisioning form, recipe choices and modal
  footer while preserving the provider catalog hooks and field prefixes.
- Added a mobile browser assertion for capacity stats, filter-sheet controls
  and provisioning rows.

Preserved contracts:

- Organization-scoped query filters, status values, provisioning flag,
  pagination/export parameters and secret exclusion.
- Provider catalog loading, plan-limit warnings, provider-create dialog
  linking, server form names/defaults, validation reopening and creation
  routes/status behavior.

Evidence:

- Server inventory, export, infrastructure-filter, creation-dialog and
  LocalUiAsset coverage — 57 tests passed, 733 assertions.
- Mobile server inventory journey — 1 focused browser test passed in the
  isolated fixture runtime.
- 'npm run build' — passed.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '74fccba' is on 'origin/main'.

Next task: deploy the server inventory modernization and rebuilt assets to the
isolated Deployer runtime, then inspect the remaining operational pages.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 118e662. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-6vkGfmyt.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the remaining operational page families and select the
next smallest cohesive Signal modernization boundary.

## Slice 24 — product feedback

Status: implemented and verified locally; code committed and pushed as
'8b461fd'.

Responsibility problem addressed:

- Feedback encryption, workspace visibility, review authorization and
  deletion already lived behind dedicated application boundaries, but the
  inbox still used legacy cards, inputs and secondary text roles.
- Reviewers therefore had to scan a visually noisy list and the compose/review
  dialogs did not share the current mobile form controls.

Signal implementation:

- Converted feedback submissions to compact Signal panels with ink/muted
  hierarchy, quiet reproduction/response disclosures and a stable inventory
  hook.
- Replaced status/category filters with shared Signal inputs.
- Updated compose and review dialogs to shared labels, inputs and explicit
  control IDs without changing their forms.
- Added a feature-level presentation contract for the populated inventory.

Preserved contracts:

- Encrypted descriptions and reproduction steps, submitter/workspace
  visibility, admin-only review, denial-before-validation ordering and
  foreign-workspace protection.
- Existing categories, severity/status values, validation keys, pagination,
  modal URLs, review responses, deletion behavior and flash messages.

Evidence:

- 'ProductFeedbackTest' — 7 tests passed, 47 assertions.
- 'LocalUiAssetTest' — 23 tests passed, 470 assertions.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'npm run build' — passed.
- 'git diff --check' — passed.

Push status: '8b461fd' is on 'origin/main'.

Next task: deploy the feedback modernization and rebuilt assets to the
isolated Deployer runtime, then inspect observability and automation surfaces.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to c81af51. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-6vkGfmyt.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect observability alert, incident and environment-context
surfaces for the next cohesive modernization boundary.

## Slice 25 — observability response surfaces

Status: implemented and verified locally; code committed and pushed as
'e7f760c'.

Responsibility problem addressed:

- Observability already kept incident grouping, encrypted evidence, alert
  delivery, status-page workflows and bounded environment reads behind their
  existing policies, controllers and services, but the main surface mixed
  legacy cards, controls and text roles with the Signal shell.
- Dense server telemetry, incident response, alert destination and status
  update rows were consequently harder to scan on a phone than the newer
  inventory surfaces.

Signal implementation:

- Replaced the main observability cards with Signal panels, ink/muted text
  hierarchy, compact response metrics and semantic section hooks.
- Converted operational incident, server, destination, status-page, status
  update and correlated-signal rows to quiet panels and consistent dividers.
- Updated observability dialogs to shared labels, inputs, checkboxes and
  theme-aware focus styles; kept the status-page slug example aligned with
  the Deployer public name.
- Added stable data hooks and a populated presentation contract test without
  changing any form fields or operation boundaries.

Preserved contracts:

- Existing routes, query parameters, modal history URLs, form names, error
  reopening, validation ordering, status values, encrypted endpoint handling,
  incident response actions, tenant scoping, exports and lazy evidence
  fragments.
- Existing responsive disclosure behavior, active-versus-resolved incident
  visibility and bounded telemetry/evidence reads.

Evidence:

- Observability, operational incident, environment context and shared insight
  coverage — 40 tests passed, 405 assertions.
- LocalUiAsset coverage — 23 tests passed, 470 assertions.
- Focused observability browser journeys — 4 Playwright tests passed:
  incident timeline, investigation note, metric-rule dialog and management
  dialogs.
- 'npm run build' — passed; generated asset bundle is ignored by Git as usual.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: 'e7f760c' is on 'origin/main'.

Next task: deploy observability response surfaces, then modernize the bounded
environment evidence context and its saved-filter controls as a separate slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 2f8d593. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-DlzsNDOY.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: modernize the bounded environment evidence context and its
saved-filter controls as the next cohesive observability slice.

## Slice 26 — environment evidence context

Status: implemented and verified locally; code committed and pushed as
'00f30f3'.

Responsibility problem addressed:

- Environment evidence already used a dedicated bounded context reader and
  rechecked authorization on linked health and runtime-log routes, but its
  filters, saved investigations and evidence panels still used legacy controls
  and card hierarchy.
- On a phone, the deployment, health, log and incident evidence therefore
  looked like one long undifferentiated surface even though each section had a
  distinct investigation purpose.

Signal implementation:

- Added compact local navigation for filters, deployments, health, logs and
  incidents.
- Converted the context summary, filter disclosure, saved investigations and
  four evidence sections to Signal panels, labels, inputs, muted metadata and
  stable section/card hooks.
- Updated the lazy incident timeline content and investigation dialog to the
  shared Signal controls and timeline primitive.
- Kept filters collapsed by default on mobile; the browser contract verifies
  that the controls expand intentionally rather than increasing initial page
  height.

Preserved contracts:

- Shareable URLs, window/service/deployment/severity values, omitted versus
  active filters, saved-view expiry and deletion rules, modal history/content
  URLs, tenant authorization, bounded query limits and sensitive-body
  exclusion.
- Existing deployment, health, runtime-log and incident links, including
  route-level authorization rechecks and no-store behavior.

Evidence:

- Environment context, observability, operational incident and shared insight
  coverage — 41 tests passed, 416 assertions.
- Focused environment-context browser journeys — 2 Playwright tests passed:
  health history stays in context and the mobile section/local-navigation
  workflow expands its collapsed filters correctly.
- 'npm run build' — passed; generated asset bundle is ignored by Git as usual.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '00f30f3' is on 'origin/main'.

Next task: deploy the environment evidence context, then inspect automation
and runtime-control surfaces for the next cohesive Signal modernization
boundary.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to cef394b. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-DlzsNDOY.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect automation and runtime-control surfaces for the next
cohesive Signal modernization boundary.

## Slice 27 — automation and runtime controls

Status: implemented and verified locally; code committed and pushed as
'5f7069b'.

Responsibility problem addressed:

- Automation already keeps token management, workflow application, runtime
  transitions, schedules and task execution in their existing controllers,
  requests, actions and jobs, but the page and its reusable dialogs still
  mixed legacy card, text and input classes.
- The resulting hierarchy made API access, application workflows and task-run
  evidence harder to scan on a phone, while status alerts competed visually
  with the actual controls.

Signal implementation:

- Replaced the automation overview cards, token list, quick-start panel,
  application accordions and environment controls with quiet Signal panels,
  muted metadata and shared input/label/check-control styles.
- Added explicit responsive section and item hooks for overview summaries,
  tokens, quick start, applications, environments, schedules, tasks and
  task runs without changing the existing route or dialog identifiers.
- Changed success, token-copy and validation feedback to a restrained
  border-accent treatment; the one-time token remains in a dark code block
  and is not exposed through any new markup or logging path.
- Updated schedule, task, token and task-output dialog fragments to the same
  compact control and evidence language.

Preserved contracts:

- Technical compatibility identifiers such as 'buildpusher.yaml',
  'BUILDPUSHER_TOKEN' and existing API URLs remain unchanged.
- Existing modal query keys, validation error reopening, named form fields,
  token expiry and ability defaults, queue dispatch, runtime state changes,
  schedule/task deletion, task-output authorization and raw-output responses
  remain unchanged.

Evidence:

- Automation, shared insight and local asset coverage — 63 tests passed,
  732 assertions.
- Focused automation browser journeys — 5 Playwright tests passed: context
  evidence, token dialog, schedule/task dialogs and scheduled-task output.
- 'npm run build' — passed; generated asset bundle is ignored by Git as usual.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '5f7069b' is on 'origin/main'.

Next task: deploy the automation modernization, then inspect the next
runtime-control surface for a separate cohesive Signal slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 429d560. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-DlzsNDOY.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the next runtime-control surface for a separate cohesive
Signal modernization boundary.

## Slice 28 — application runtime controls

Status: implemented and verified locally; code committed and pushed as
'fa6766a'.

Responsibility problem addressed:

- The application detail page is the shared runtime-control surface for
  environment topology, deployment safeguards, capacity, encrypted variables,
  workers, attached resources, previews and configuration-as-code dialogs.
- Its existing operations were already delegated to environment policies,
  requests, actions and modal components, but the page still used legacy
  cards, bright alert blocks and inconsistent form controls. On mobile this
  created a long scroll before a user could reach the relevant environment
  control.

Signal implementation:

- Added compact local navigation for environments, adding an environment and
  preview environments.
- Converted environment summaries, readiness guidance, promotion guidance,
  runtime controls, encrypted-variable rows, process/resource lists and
  preview cards to quiet Signal panels with bordered status accents.
- Added stable presentation hooks for environment, runtime, variable,
  process, resource, preview and add-environment sections.
- Updated all application-detail runtime dialog forms to shared Signal
  labels, inputs, help text, checkboxes and muted separators.
- Kept the action locator scoped in the browser contract where local
  navigation intentionally repeats an action label.

Preserved contracts:

- Existing environment routes, policy checks, scoped resources, dialog query
  keys, modal content URLs, validation reopen behavior and flash/error
  semantics remain unchanged.
- Encrypted values, process commands and preview secret values remain absent
  from the rendered overview; only masked/version metadata is shown.
- Deployment readiness, promotion, runtime capacity, settings, deployment
  controls, preview cleanup/retry and configuration-as-code operations were
  not moved or reinterpreted.

Evidence:

- Project/environment, runtime, environment-operation and configuration
  overview coverage — 21 tests passed, 185 assertions.
- Application-detail browser journeys — 2 Playwright tests passed: all
  seven application dialogs and configuration-as-code in context, including
  mobile focus restoration and URL-backed reopening.
- 'npm run build' — passed; generated asset bundle is ignored by Git as usual.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: 'fa6766a' is on 'origin/main'.

Next task: deploy the application runtime-control modernization, then inspect
server and website detail surfaces for the next cohesive Signal slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to d7e27d8. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-DlzsNDOY.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect server and website detail surfaces for the next cohesive
Signal modernization boundary.

## Slice 29 — server detail and runtime evidence

Status: implemented and verified locally; code committed and pushed as
'e21482b'.

Responsibility problem addressed:

- The Livewire server detail component already kept polling, fixed SSH
  diagnostics, bounded log snapshots, provisioning retry state and command
  dialogs in their existing operations. Its remaining presentation mixed
  legacy cards and text roles across overview, metrics, diagnostics and log
  operations, forcing mobile users through a long page.
- The old literal “Setup Information” section remains absent. Existing
  provisioning recipes and bounded logs remain available as read-only context;
  they were not removed or moved into a new business boundary.

Signal implementation:

- Added local navigation for overview, metrics, diagnostics and logs.
- Converted server overview, metrics, diagnostics, attached websites,
  provisioning recipes and operations containers to quiet Signal panels with
  stable data hooks.
- Kept the terminal log output intentionally dark for readability while
  applying the Signal border/link language around its controls.
- Preserved the collapsed operations disclosure so the initial mobile page
  stays compact; the browser contract explicitly opens it before inspecting
  the log console.

Preserved contracts:

- Livewire polling only while snapshots are pending, allowlisted log types,
  fixed remote commands, bounded/sanitized output, no raw non-selected log
  leakage, diagnostic authorization, leases/stale-attempt guards and retry
  behavior remain unchanged.
- Existing server display-name and command-history dialogs, deployment and
  provider links, recipe information, provisioning status text and the
  no-“Setup Information” contract remain intact.

Evidence:

- Server log, diagnostic, type-provisioning, remote-retry and inventory
  coverage — 41 tests passed, 330 assertions.
- Focused server browser journeys — 2 Playwright tests passed: command
  history remains contextual and the server detail evidence sections are
  scannable on mobile after opening the operations disclosure.
- 'npm run build' — passed; generated asset bundle is ignored by Git as usual.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: 'e21482b' is on 'origin/main'.

Next task: deploy the server-detail modernization, then modernize the website
detail and runtime-log surface as a separate cohesive slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 7517cdf. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-DR8AwSl3.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: modernize the website detail and runtime-log surface as a separate
cohesive Signal slice.

## Slice 30 — website detail and runtime evidence

Status: implemented and verified locally; code committed and pushed as
'201d409'.

Responsibility problem addressed:

- The website detail page already delegated deployment, health, runtime-log,
  provisioning and repository operations to their existing controllers,
  Livewire components, jobs and modal fragments. Its remaining presentation
  mixed older alert/card/input conventions, and mobile users had no compact
  way to jump between overview, operations, health, logs and repositories.
- This was a presentation-boundary problem, not a reason to move encrypted
  environment handling, health state transitions or provisioning behavior into
  new abstractions.

Signal implementation:

- Added compact website section navigation with stable overview, operations,
  health, runtime-log and repository anchors.
- Converted website status feedback and empty repository states to quiet
  border-led panels instead of filled alert cards.
- Updated website create/edit modal fields, health settings, help text,
  separators and action footers to the shared Signal input/panel language.
- Kept provisioning output as a deliberately dark, bounded terminal surface
  while aligning its links and metadata with the Signal treatment.
- Added stable presentation hooks for the overview, health and repository
  surfaces and browser assertions for the mobile layout.

Preserved contracts:

- Website routes, dialog query keys, fragment URLs, modal reopen behavior,
  form names, validation errors, flash behavior and no-JavaScript links are
  unchanged.
- Encrypted environment values remain rendered only in the authorized edit
  form and are absent from the overview; health polling, bounded log output,
  provisioning retries, relocation cleanup, deletion and stale-attempt
  protections remain in their existing components and operations.
- The existing collapsed/expanded rules for provisioning, health history and
  runtime snapshots remain unchanged, as does the deliberate absence of the
  old “Setup Information” section.

Evidence:

- Website provisioning, retention, health history, environment encryption,
  placement, relocation, retry, deletion, monitoring, deployment health and
  dialog coverage — 86 tests passed, 801 assertions.
- Focused website browser journeys — 5 Playwright tests passed: website edit
  modal, health-history filtering, detail section navigation, runtime logs and
  repository panel, and deployment-history dialog.
- 'npm run build' — passed; generated asset bundle is ignored by Git as usual.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '201d409' is on 'origin/main'.

Next task: deploy the website-detail modernization to the isolated canonical
development runtime, then inspect the next product surface for a separate
cohesive Signal slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 603c03f. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-CsC1XIki.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the next product surface for a separate cohesive Signal
modernization boundary.

## Slice 34 — recipe inventory and assignment surfaces

Status: implemented and verified locally; code committed and pushed as
'308e799'.

Responsibility problem addressed:

- Recipe inventory, assignment detail and the shared create/edit form already
  used the existing recipe actions, encrypted persistence and modal workflow,
  but their presentation still mixed the older utility vocabulary with Signal
  surfaces and required long mobile scrolling.

Signal implementation:

- Added local navigation and stable anchors for recipe insights/inventory and
  recipe overview/assignments.
- Converted recipe inventory and assignment lists to quiet Signal panels with
  ink/muted metadata, responsive hover states and consistent dividers.
- Updated search/usage controls and the shared recipe create/edit form to the
  common labels, inputs and surface tokens.
- Kept the provisioning-plan snapshot explanation visible as the detail-page
  overview and retained the existing collapsed insights behavior.

Preserved contracts:

- Recipe filtering, pagination, duplicate/delete actions, publishing/category
  fields, modal URLs, validation errors, encrypted scripts, gallery source
  state and immutable server recipe snapshots are unchanged.
- Scripts remain absent from inventory/detail summaries and are still shown
  only through their existing authorized edit/inspection paths.
- No recipe action, controller, policy, job, route, persisted value or queue
  payload was modified.

Evidence:

- Recipe inventory, usage, management, gallery, duplication and activity
  coverage — 34 tests passed, 307 assertions.
- Focused recipe modal/browser journey — 1 Playwright test passed, including
  inventory anchors and shared Signal form controls.
- 'npm run build' — passed.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '308e799' is on 'origin/main'.

Next task: deploy the recipe-surface modernization to the isolated canonical
development runtime, then inspect gallery and feedback surfaces for the next
cohesive Signal slice.

## Provider compatibility correction — 2026-09-22

Status: implemented and verified; code committed and pushed as '536e5a3'.

The provider detail page had changed the established interval copy from
lowercase `every :count hours` to sentence-case `Every :count hours`, while
the existing detail contract and test expected the original wording. Restored
the detail copy only; provider forms and inventory copy remain unchanged.

Evidence:

- ProviderMonitoringIntervalTest — 3 tests passed, 30 assertions.
- No route, validation, monitoring, persistence or authorization behavior
  changed.

Push status: '536e5a3' is on 'origin/main'.

## Slice 33 — provider section navigation

Status: implemented and verified locally; code committed and pushed as
'ad0f81b'.

Responsibility problem addressed:

- Provider inventory and detail pages already reuse dedicated queries, actions,
  policies, connection-history services and safe provider adapters. Their
  remaining issue was presentation hierarchy: filters, retained evidence and
  attached resources required long mobile scrolling and had no page-local
  navigation.

Signal implementation:

- Added provider section navigation to the inventory page for insights and
  inventory.
- Added provider detail navigation for connection overview, retained checks
  and attached resources.
- Added stable section IDs, scroll offsets and provider section hooks without
  changing the existing filter-sheet, pagination, history-dialog or resource
  dialog behavior.

Preserved contracts:

- Organization scoping, provider filtering, pagination, CSV export,
  encrypted-token handling, connection testing, monitoring intervals and
  failure thresholds are unchanged.
- Connection-history ordering, bounded evidence, credential redaction,
  modal URLs, no-JavaScript forms and attached-resource links remain intact.
- No provider adapter, query, action, controller, job, route or persisted value
  was modified.

Evidence:

- Provider capability, connection, history, monitoring, inventory, export,
  submission-feedback and source-provider coverage — 69 tests passed, 697
  assertions.
- Focused provider browser journeys — 4 Playwright tests passed for inventory
  anchors, detail scanning, history filtering and mobile filter locking.
- 'npm run build' — passed.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: 'ad0f81b' is on 'origin/main'.

Next task: deploy the provider compatibility and navigation commits to the
isolated canonical development runtime, then inspect the next product surface
for a separate cohesive Signal slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to '95092f8'. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-CsC1XIki.css and assets/signal-theme-DODJINv7.js.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the next product surface for a separate cohesive Signal
modernization boundary.

## Theme-state bugfix — 2026-09-22

Status: implemented and verified locally; code committed and pushed as
'd93cb9b'.

Responsibility problem addressed:

- The responsive browser contract exercises explicit light/dark overrides by
  adding the opposite theme class. The theme controller allowed both classes
  to remain on the root, so dark-mode pages stayed dark when light was added.
- This was a presentation-state defect, not a page-specific build regression.

Implementation:

- Added a small MutationObserver to the Signal theme controller that keeps
  `dark` and `light` mutually exclusive for direct classList changes.
- The observer remembers and restores the original theme when a temporary
  override class is removed, while leaving the existing appearance preference,
  local-storage behavior and theme toggle unchanged.

Preserved contracts:

- Normal server bootstrap, theme toggles, system-preference changes, palette
  tokens, dark utility classes and page behavior are unchanged.
- No application routes, persisted values, authorization rules or business
  operations were modified.

Evidence:

- Full responsive asset-layout matrix — 8 tests passed across light/dark at
  320px, 390px, 768px and 1440px.
- 'npm run build' — passed; generated CSS and JavaScript bundles are ignored
  by Git as usual.
- 'git diff --check' — passed.

Push status: 'd93cb9b' is on 'origin/main'.

Next task: commit and verify the build/deployment-detail Signal slice.

## Slice 32 — build and deployment detail

Status: implemented and verified locally; code committed and pushed as
'e143621'.

Responsibility problem addressed:

- The Livewire deployment-status component already owned lifecycle rendering
  and polling, but its summary, evidence, timeline and logs had no compact
  build-local navigation. Several approval, recovery and failure states still
  used filled alert blocks that competed with the deployment evidence on small
  screens.

Signal implementation:

- Added a compact build-local navigation for summary, evidence, timeline and
  logs with stable scroll anchors and test hooks.
- Converted build feedback states to quiet border-led panels while preserving
  their status-specific border colors, roles and copy.
- Kept the existing Deployment timeline as the single lifecycle surface; no
  execution-checkpoint section was introduced.

Preserved contracts:

- Livewire polling, deployment status transitions, approval/rejection,
  cancellation, rollback, observation, bounded log rendering/downloads,
  dialogs and recovery actions are unchanged.
- Existing authorization, route/response behavior, timeline ordering, mobile
  disclosure behavior and no-op/terminal handling remain unchanged.
- No controller, job, action, persistence, queue payload or serialized value
  was modified.

Evidence:

- Deployment lifecycle, approvals, cancellation, promotion, redeployment,
  observation, controls, comparison, notes, health checks and logs — 70 tests
  passed, 561 assertions.
- Full responsive asset-layout matrix — 8 tests passed across light/dark at
  320px, 390px, 768px and 1440px, including build summary/timeline anchors.
- 'npm run build' — passed.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: 'e143621' is on 'origin/main'.

Next task: deploy the build/deployment-detail modernization and theme-state
fix to the isolated canonical development runtime, then inspect the next
product surface for a separate cohesive Signal slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to '6075c97'. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-CsC1XIki.css and assets/signal-theme-DODJINv7.js.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the next product surface for a separate cohesive Signal
modernization boundary.

## Slice 31 — repository detail and source settings

Status: implemented and verified locally; code committed and pushed as
'825826f'.

Responsibility problem addressed:

- Repository detail already delegated deployment, preflight, webhook,
  delivery-history, revision and build behavior to existing actions, jobs,
  policies and dialogs. Its remaining presentation had no compact section
  navigation, and source-setting forms still used the older input/card
  language.
- The page also mixed filled alert blocks with the newer bordered evidence
  surfaces, making deployment automation and recovery context harder to scan
  on a phone.

Signal implementation:

- Added repository section navigation for overview, automation, deployment
  timeline, insights and history.
- Added stable overview, information and automation hooks and scroll anchors;
  preserved the existing disclosure behavior for active work and filtered
  webhook delivery history.
- Replaced deployment-readiness, plan and one-time webhook feedback with
  quiet border-led panels.
- Updated repository create/edit fields, monorepo path filters, command
  editors, descriptions, modal footers and delivery outcome feedback to the
  shared Signal controls.

Preserved contracts:

- Deployment actions, preflight checks, approval and entitlement decisions,
  repository scoping, revision links, webhook signatures, branch/path
  filtering, replay/coalescing behavior, delivery pagination/export and
  contextual dialog URLs are unchanged.
- Command and path values remain explicit form fields; no credentials or
  webhook payloads were added to the rendered page or dialog.
- Existing no-JavaScript links, validation behavior, status text, disclosure
  open rules and build/timeline semantics remain intact.

Evidence:

- Repository deployment insights, safety/path filters, webhook behavior and
  delivery history, deployment operations, impact preview and dialog
  coverage — 66 tests passed, 566 assertions.
- Focused repository browser journeys — 3 Playwright tests passed: repository
  edit modal, webhook delivery inspector and mobile deployment/webhook
  scanning.
- 'npm run build' — passed; generated asset bundle is ignored by Git as usual.
- 'php artisan view:cache' — passed.
- 'php vendor/bin/pint --test' — passed.
- 'git diff --check' — passed.

Push status: '825826f' is on 'origin/main'.

Next task: deploy the repository-detail modernization to the isolated canonical
development runtime, then inspect the next product surface for a separate
cohesive Signal slice.

## Canonical dev deployment — 2026-09-22

The isolated runtime at
/root/Documents/Codex/2026-09-15/buildpusher-main-runtime was fast-forwarded
to 91c201c. The runtime asset bundle was rebuilt, application/configuration/
route/view caches were rebuilt, and buildpusher-dev-main.service plus its
queue worker were restarted. The canonical development host is
https://deployer.buildpusher.com; the legacy buildpusher.com host is not the
verification target for this application.

Served-runtime evidence:

- /login — HTTP 200 with title Sign in to your account · Deployer.
- /build/manifest.json — HTTP 200 with the current Deployer asset manifest,
  including assets/app-CsC1XIki.css.
- /api/health — HTTP 200, {"status":"ready"}.
- Web and queue services — active.

The runtime retained its pre-existing uncommitted deploy/Caddyfile change; the
application fast-forward did not overwrite it. This deployment is isolated
development evidence, not production or external-provider acceptance.

Next task: inspect the next product surface for a separate cohesive Signal
modernization boundary.
