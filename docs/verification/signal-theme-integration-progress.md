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
