# UI usability progress

## Purpose

This ledger records the implementation of `docs/ui-usability-plan-2026-09-18.md`.
Each slice must preserve routes, response formats, authorization, validation
keys and error bags, flash messages, Livewire behavior, no-JavaScript paths,
queued-work contracts and persisted values. A slice is complete only after its
focused checks pass, the result is documented and the cohesive commit is pushed
to `origin/main`.

## Phase 0 — inventory and fresh baseline

### Scope and isolation

- Repository branch: isolated checkout on `main` at the start of this work.
- Implementation checkout:
  `/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-preview-cleanup-Amr47o`
- Dev runtime checkout:
  `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`
- Required PHP: `/root/.local/share/buildpusher/php-8.5.10/bin/php`.
- The implementation checkout uses its own locked dependencies/assets. The
  served runtime has an independent local database, storage, cache, key and
  queue. No production or acceptance-drill checkout is used for this work.
- The runtime paths were verified before Artisan checks. No credentials or
  cloud resources were changed.

### Current page-family inventory

The route/state inventory covers public/authentication, dashboard and project
delivery, environments/configuration, deployments, repositories, websites,
servers and provisioning, providers, databases/domains/load balancers,
backups/restores, observability/incidents/status, notifications/activity/
commands/automation, templates/gallery/reports/feedback, workspace/billing/
costs, account/security, search, documentation and administration. The plan's
full family matrix and evidence are in `docs/ui-usability-plan-2026-09-18.md`.

The current audit rendered 67 route URLs at 390x844 and 1440x1000 (134 visits),
with HTTP responses below 400 and no page-level horizontal overflow. This is
route-rendering evidence, not complete role, modal, mutation or physical-device
coverage. Empty, typical, long-history, pending, failure, entitlement and
multi-role states remain explicit follow-up fixtures.

### Fresh baseline observations

Measured against the isolated development deployment on 2026-09-18:

| Surface | Mobile height | First useful-content problem |
| --- | ---: | --- |
| Observability | 12,629px | Server telemetry starts near 5,560px; alert destinations near 9,484px. |
| Notifications | 9,569px | First notification starts near 1,824px. |
| Dashboard | 7,280px | Needs attention starts near 4,469px. |
| Failed deployment | 6,492px | Recovery guidance starts near 5,542px; logs near 6,007px. |
| Active server detail | 5,002px | Multiple secondary panels precede diagnostics and history. |
| Account | 4,791px | Profile, security, sessions, connections and deletion share one page. |
| Repository detail | 4,446px | Deployment history starts near 3,955px. |
| Deployment history | 3,246px | Eleven filter controls precede statistics and results. |
| Provider creation | 2,207px | Seven stacked provider choices push the token field near 1,055px. |
| Backups | 2,105px | Summary cards consume most of the first screen before history. |

Additional baseline findings:

- Dark secondary text and input text, light secondary text, dark primary button
  text and the footer need a contrast correction. The measured examples were
  approximately 3.04:1, 2.13:1, 2.54:1 and black text on a dark surface.
- The desktop sidebar is internally scrollable and contains about 1,548px of
  content in a 1,000px viewport. The mobile menu intentionally remains the
  restored flat direct-link layout and must not lose its links.
- The command palette arrow-key state does not currently move focus or expose
  an active result. This is a concrete shared-shell accessibility defect.
- Sampled application pages commonly use the generic `BuildPusher` browser
  title. Workspace nesting, duplicate detail sections and large metric blocks
  are documented in the plan for later slices.

### Verification status

The fresh quality baseline completed before the first implementation commit:

- Strict PHP 8.5.10 suite: **1,566 tests / 12,908 assertions**, with no
  failures, warnings, risky tests or deprecations.
- `vendor/bin/pint --test` and `git diff --check`: passed.
- `npm run test:assets`: **9 passed**, including light/dark responsive layouts
  and the no-JavaScript provider submission.
- The authenticated dev-domain browser run completed all three accessibility
  and all three navigation cases. The broad visual crawl was interrupted after
  14.3 minutes while the restored flat mobile menu test waited for the stale
  `Account and security` link; it did not reach the route crawl. This is a
  browser-spec mismatch, not evidence that the mobile menu should be changed.
  The navigation spec's direct `Account`/`Settings` contract is the chosen
  behavior and will be reconciled before the next broad crawl.
- `npm run build` passed. The served dev runtime remained HTTP 200 and was not
  mutated by the baseline.

Historical UI-modernization totals are context only and are not reused as this
baseline.

| Slice | Status | Tests / evidence | Commit / push | Exact next task |
| --- | --- | --- | --- | --- |
| Phase 0: inventory and fresh baseline | Complete | 1,566 PHP tests / 12,908 assertions; Pint; diff check; 9 asset tests; 6 accessibility/navigation tests; broad crawl limitation documented above | `26dccf3` pushed to `origin/main` | Shared readability and keyboard-navigation slice |

## Phase 1 — shared readability and keyboard navigation

### Responsibility problem

Global readability and browser metadata were split between legacy color aliases,
semantic tokens and the shared layouts. The command palette updated an internal
index without moving focus or exposing the active result, and the visual audit
still expected the retired grouped mobile label. These were shared-shell
problems, so the fix belongs at the theme/layout and browser-contract boundary,
not in individual feature pages.

### Boundaries and preserved behavior

- `PageTitle` owns presentation-only route-to-title mapping. It does not load
  resources, authorize actors or change response bodies beyond the browser title.
- The authenticated layout resolves an explicit title first and otherwise uses
  the current named route. Public, authentication and legal layouts keep their
  existing explicit titles.
- Theme aliases now provide readable primary/secondary text and placeholders in
  both color schemes. Semantic dark buttons use a sufficiently dark blue with
  white text; routes, forms, flash messages, persisted values and workflow
  behavior are unchanged.
- The command palette now cycles visible results with Arrow Up/Down, Home and
  End, resets selection when filtering, exposes `role="option"` and
  `aria-selected`, and reports an empty quick-action match. Enter still follows
  the focused link or submits the existing full-resource search.
- The mobile menu remains the intentionally restored flat direct-link layout;
  the stale visual test was updated to assert its direct `Account` and
  `Settings` destinations.

### Verification

- Focused PHP: `UiPageTitleTest` passed; `LocalUiAssetTest` passed (18 tests,
  384 assertions).
- `vendor/bin/pint --test`, `git diff --check` and Blade cache compilation
  passed.
- `npm run test:assets` passed with the required PHP override: 9 responsive,
  dark/light and no-JavaScript checks.
- Authenticated browser smoke passed: 6/6 accessibility and navigation cases
  across mobile, tablet and desktop.
- The corrected mobile visual crawl passed: 1/1 test, 3.2 minutes, with the
  full product-page traversal completing past the former stale-link blocker.
- Commit `f9e7488` (`feat: improve shared UI accessibility`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, rebuilt,
  cache-refreshed and confirmed healthy.

| Phase 1: shared readability and keyboard navigation | Complete | Focused PHP, Pint, diff check, 9 asset tests, 6 browser smoke tests and 1 mobile visual crawl passed | `f9e7488` pushed to `origin/main` | Build the deployment-history results-before-filters slice |

## Phase 2 — deployment history results before advanced filters

### Responsibility problem

The deployment-history controller already supplied a validated filter contract
and a filter-aware inventory/metrics query. The usability problem was entirely
presentational: eleven controls rendered expanded before the first outcome,
which pushed the first deployment link to roughly 1,857px on the mobile
baseline.

### Boundaries and preserved behavior

- The reusable `ui.filter-panel` component owns only native disclosure
  presentation, an active-count badge and keyboard-friendly summary markup.
- `BuildIndexRequest`, `BuildInventoryQuery`, metrics, pagination, CSV export,
  organization scoping and all existing query names remain unchanged.
- The default deployment-history view collapses the advanced controls. Any
  normalized filter opens the panel and shows its count, so a filtered result
  remains self-explanatory and editable.
- Existing validation, selected values, empty states, result ordering and
  active/latest semantics are preserved. No writes, jobs or authorization
  decisions are involved.

### Verification

- `BuildHistoryInsightsTest` and `BuildHistoryFilterTest` passed: 15 tests,
  143 assertions, including collapsed default state and active-filter reopen.
- Pint, Blade cache compilation, Vite build and `git diff --check` passed.
- The authenticated mobile visual crawl passed: 1/1 test, 2.5 minutes.
- A real mobile runtime measurement after the change recorded 2,392px total
  page height, filter summary at about 181px, metrics at 268px and the first
  build link at 1,061px. The first useful result moved up about 796px and the
  full page shortened about 854px against the baseline.
- Commit `9f3b611` (`feat: streamline deployment history filters`) was pushed
  to `origin/main`; the isolated HTTPS runtime was fast-forwarded, rebuilt,
  cache-refreshed and confirmed healthy.

| Phase 2: deployment history results before advanced filters | Complete | 15 focused feature tests / 143 assertions, Pint, build, diff check and mobile visual crawl passed; first result moved from ~1,857px to ~1,061px | `9f3b611` pushed to `origin/main` | Audit dashboard priority and move secondary sections behind purposeful disclosures |

## Phase 3 — dashboard priority and attention context

### Responsibility problem

The dashboard assembled the attention summary after provisioning, active
deployments, webhook deliveries, command activity, gallery reports and recipe
updates. That made the most actionable failure context appear around 4,469px
down the mobile page even though the controller had already computed it.

### Boundaries and preserved behavior

- The attention panel is now a presentation-only `dashboard._attention` view
  partial placed directly after the operational overview.
- `DashboardController` remains responsible for workspace-scoped queries,
  counts, limits and eager loading. No query, authorization, notification,
  link target or sensitive-field selection changed.
- Existing empty-state wording, failure categories, “view notifications” flow,
  owner isolation and secondary panels remain intact; only their visual order
  changed.

### Verification

- `DashboardTest` and `LocalUiAssetTest` passed, including the new five-
  assertion ordering test proving operational overview → attention → setup.
- Pint, Blade cache compilation, Vite build and `git diff --check` passed.
- Authenticated mobile visual crawl passed: 1/1 test, 3.6 minutes.
- A real mobile runtime measurement placed the attention heading at about
  1,681px, down from the baseline ~4,469px; total page height remains ~7,280px
  because this slice reorders existing panels without hiding data.
- Commit `ebca982` (`feat: prioritize dashboard attention summary`) was pushed
  to `origin/main`; the isolated HTTPS runtime was fast-forwarded, rebuilt,
  cache-refreshed and confirmed healthy.

| Phase 3: dashboard priority and attention context | Complete | Dashboard and UI feature suites, ordering assertion, Pint, build, diff check and mobile visual crawl passed; attention moved from ~4,469px to ~1,681px | `ebca982` pushed to `origin/main` | Improve long resource-detail pages with summary-first sections and purposeful disclosures |

## Phase 4 — server operations summary and disclosure

### Responsibility problem

The server detail page placed log snapshots, log output and setup progress in a
long lower grid even when an active server had no immediate operational work.
This made the page approximately 5,002px tall on mobile and buried the
summary-first server information, metrics and diagnostics.

### Boundaries and preserved behavior

- The existing Livewire server view now groups log snapshot overview, selected
  log output and setup progress inside a native `Logs and setup` disclosure.
- Active servers keep the disclosure closed by default; provisioning servers
  and failed selected log snapshots open it automatically so recovery context
  is not hidden.
- The server information card, metrics, diagnostics action, attached websites,
  log refresh controls, Livewire polling, fixed log allowlist, bounded output,
  stale-attempt behavior and authorization remain unchanged.
- No controller, query, job, provider contract, persisted value or response
  route changed.

### Verification

- Server log snapshot, diagnostic and provisioning-log suites passed: 30 tests,
  186 assertions; the new disclosure-state test passed separately with four
  assertions.
- Pint, Blade cache compilation, Vite build and `git diff --check` passed.
- A real mobile browser check confirmed the active server starts closed and
  clicking its summary opens the log overview, log output and setup headings.
- A real mobile runtime measurement recorded about 3,322px page height versus
  the ~5,002px baseline, a reduction of about 1,680px.
- Commit `735160f` (`feat: streamline server operations details`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, rebuilt,
  cache-refreshed and confirmed healthy.

| Phase 4: server operations summary and disclosure | Complete | 30 focused tests / 186 assertions, disclosure-state test, Pint, build, diff check and real mobile interaction passed; page shortened ~1,680px | `735160f` pushed to `origin/main` | Apply the same summary-first treatment to website runtime logs and health history |

## Phase 5 — website health and runtime evidence disclosure

### Responsibility problem

The website detail page rendered the latest twenty health rows and runtime log
controls immediately after the health summary. On mobile this made a normal
website page approximately 3,531px tall before users reached attached
repositories and setup context.

### Boundaries and preserved behavior

- Health metrics and the existing “View all health checks” and export actions
  remain visible. The latest twenty-row table is behind a native disclosure.
- Runtime log snapshots are grouped behind a summary that describes bounded
  retention. Queued, refreshing or failed snapshots automatically open the
  disclosure so operational work remains visible.
- Runtime log tabs, refresh forms, retention updates, Livewire provisioning
  output, health-check escaping and tenant-scoped routes are unchanged.
- No controller, query, authorization rule, persisted value or API contract
  changed.

### Verification

- `WebsiteHealthHistoryTest`, `WebsiteHealthInsightsTest` and
  `ObservabilityTest` passed: 30 tests, 262 assertions; the new four-assertion
  disclosure-state test passed separately.
- Pint, Blade cache compilation, Vite build and `git diff --check` passed.
- A real mobile browser check opened both health and runtime disclosures and
  confirmed 3 health rows and 2 runtime panels remain available.
- A real mobile runtime measurement recorded about 2,719px page height versus
  the ~3,531px baseline, a reduction of about 812px.
- Commit `a26a970` (`feat: streamline website runtime details`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, rebuilt,
  cache-refreshed and confirmed healthy.

| Phase 5: website health and runtime evidence disclosure | Complete | 30 focused tests / 262 assertions, disclosure-state test, Pint, build, diff check and real mobile interaction passed; page shortened ~812px | `a26a970` pushed to `origin/main` | Improve repository/build detail pages so release context and recovery actions precede long history and logs |

Known limitations retained from earlier work: the separate live acceptance
drill, production release gates, physical-phone checks and any external
provider acceptance remain outside this UI implementation.
