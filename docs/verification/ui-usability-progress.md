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

## Phase 6 — deployment detail recovery and execution disclosures

### Responsibility problem

Deployment detail already had the required revision evidence, lifecycle timeline,
recovery actions and bounded output, but the lower-level setup stages and full
log were visually equivalent to the primary result. On the failed runtime
fixture, recovery guidance appeared around 5,542px and the log around 6,007px
in the original review.

### Boundaries and preserved behavior

- `BuildDeploymentStatus` remains the existing Livewire presentation boundary;
  no deployment query, job, policy, callback, route or persistence behavior
  changed.
- The primary status/evidence/timeline remains available. Script-level progress
  is now a disclosure with a recorded-stage summary and opens automatically for
  active or failed builds; completed builds start concise.
- Failed cause, deterministic recovery guidance and retained-release rollback
  are rendered immediately after the top status block, before evidence and
  execution history. The existing rollback authorization, forms and routes are
  unchanged.
- The bounded deployment log is a separate disclosure. Active builds open it
  for live work; completed and failed builds keep the verbose output closed by
  default while preserving the existing download route and anchor. Failure
  guidance still links directly to the log disclosure.
- Existing escaped output, polling, cancel/retry/approval/rollback actions,
  stale callback behavior, release lineage and health links remain unchanged.

### Verification

- `DeploymentLogTest`, `DeploymentTimelineTest`, `RepositoryDeploymentTest` and
  the timeline unit suite passed: 25 tests / 189 assertions.
- Pint, Blade view compilation and `git diff --check` passed.
- Commits `f00e79b` (execution/log disclosures), `4c1c6d8` (failed recovery
  ordering) and `9250304` (top-level recovery placement) were each pushed to
  `origin/main`.
- The isolated HTTPS runtime was rebuilt/cache-refreshed and both service units
  remained active. A real 390px browser check measured failed recovery at about
  868px instead of the original ~5,542px; the failed page remains about 6,639px
  overall because the diagnostic progress disclosure intentionally stays open.
  The log was closed by default and opened successfully after clicking its
  summary. A completed build measured about 3,700px and started with both
  secondary disclosures closed.

| Phase 6: deployment detail recovery and execution disclosures | Complete | 25 focused tests / 189 assertions, Pint, view compilation, push and real mobile disclosure interaction passed; recovery moved to ~868px, with failed-page total-height limitation documented | `f00e79b`, `4c1c6d8`, `9250304` pushed to `origin/main` | Improve repository detail so latest deployment and core setup actions precede webhook history and secondary configuration |

## Phase 7 — repository overview and secondary deployment history

### Responsibility problem

Repository detail mixed the latest deployment with setup stages and ten recent
build rows near the bottom of the page. The original mobile review measured a
4,446px repository page, with deployment history beginning around 3,955px;
first-deployment data measured about 5,755px.

### Boundaries and preserved behavior

- `RepositoriesController` continues to own the same scoped build, insight,
  preflight and webhook queries. The Blade view uses the already-loaded latest
  build collection; no new query, authorization rule, route or deployment
  operation was introduced.
- A compact latest-deployment overview now appears after the existing alert
  boundary, with status, revision, failure/result context and links to the
  existing detail and history routes.
- Repository setup stages remain rendered by the existing Livewire component,
  but are grouped behind a disclosure. Active and failed latest builds open it
  automatically; completed and not-started repositories remain concise.
- Recent deployment rows remain available behind a disclosure and open for an
  active latest build. The all-deployments route, row links, revision links,
  status badges and empty state are unchanged.

### Verification

- Repository insights, deployment, webhook history and webhook behavior suites
  passed: 32 tests / 293 assertions, including the disclosure-state and
  ordering regression test.
- Pint, Blade view compilation and `git diff --check` passed.
- Commit `7e698be` (`feat: streamline repository deployment overview`) was
  pushed to `origin/main`; the isolated HTTPS runtime was cache-refreshed and
  both service units remained active.
- A real 390px browser check placed the latest deployment card around 280px and
  the webhook section around 824px. The first-deployment fixture shortened from
  about 5,755px to 4,645px; completed repositories started with setup and
  recent-history disclosures closed, while active setup reopened automatically.

| Phase 7: repository overview and secondary deployment history | Complete | 32 focused tests / 293 assertions, Pint, view compilation, push and real mobile state checks passed; first-deployment fixture shortened ~1,110px | `7e698be` pushed to `origin/main` | Put webhook configuration first and make delivery history a filtered, state-aware disclosure |

## Phase 8 — repository webhook configuration and delivery disclosure

### Responsibility problem

Webhook configuration and the paginated delivery table shared one always-open
card. Even when no delivery needed attention, filters, seven delivery metrics
and the table consumed the repository page; active delivery states were not
visually distinguished from historical records.

### Boundaries and preserved behavior

- Webhook URL, provider-specific instructions, one-time secret display,
  signing-token input and enable/rotate/disable forms remain immediately
  visible inside the existing `deployment-webhook` section.
- Delivery history is now a native disclosure with its matching-delivery count.
  It opens for active status/date filters or queued/pending deliveries and is
  closed for ordinary received-only history.
- Existing filter names, GET action and `#webhook-deliveries` anchor,
  pagination page name, CSV export route, status metrics, escaped payload text,
  replay behavior and secret exclusion are unchanged.
- The existing inline session assignment was converted to a block PHP
  statement after the template parser exposed an invalid compiled boundary;
  this changes no value or session behavior.

### Verification

- Repository webhook history, webhook behavior and repository insights suites
  passed: 22 tests / 226 assertions.
- Pint, Blade cache compilation and `git diff --check` passed after correcting
  the parser issue; no failed implementation commit was pushed.
- Commit `b4d35d2` (`feat: streamline repository webhook history`) was pushed to
  `origin/main`; the isolated HTTPS runtime was cache-refreshed and both service
  units remained active.
- A real 390px browser check confirmed the normal repository’s delivery panel
  is closed without pending work and toggles on click. The active fixture kept
  setup open, while webhook configuration began around 824px; the normal page
  measured about 4,165px with delivery history collapsed.

| Phase 8: repository webhook configuration and delivery disclosure | Complete | 22 focused tests / 226 assertions, parser recovery, Pint, view compilation, push and real mobile interaction passed; ordinary history collapses while active/filter states open | `b4d35d2` pushed to `origin/main` | Inspect application/environment detail pages and choose the next summary-first slice |

## Phase 9 — notification inbox results-first layout

### Responsibility problem

The notification inbox placed six filters, saved-filter management and six
metrics before the first result. The original mobile review measured a page
around 9,569px tall with the first notification beginning about 1,824px down;
reviewed alerts also consumed the same vertical space as unread alerts.

### Boundaries and preserved behavior

- The existing `NotificationsController`, `NotificationIndexRequest`, query
  collaborator and actions remain the source of filtering, pagination, export,
  ownership and state transitions. This is a presentation-only slice.
- Matching metrics, bulk selection and the existing unread cards now lead the
  page. Read cards remain selectable and actionable, but their message and
  secondary actions are inside native disclosures.
- Filters and saved filters remain available after the results with explicit
  summaries. The filter disclosure displays an active-filter count and opens
  automatically for an active filtered view; saved-filter validation errors
  reopen their disclosure.
- Filter names, query parameters, pagination, CSV export, bulk operations,
  read/unread transitions, deletion, destination links and no-JavaScript forms
  are unchanged.

### Verification

- Notification insights, failure notifications and local UI asset suites
  passed: 35 tests / 538 assertions.
- Pint, Blade view compilation and `git diff --check` passed.
- Commit `fab18f7` (`feat: streamline notification inbox`) was pushed to
  `origin/main`; the isolated HTTPS runtime was cache-refreshed and both
  service units remained active.
- A real 390px browser check measured the page at about 6,143px, with the
  first result at about 1,070px. Filter and saved-filter disclosures were
  closed by default; a reviewed row opened successfully and exposed its
  actions. Active filters rendered their disclosure open.

| Phase 9: notification inbox results-first layout | Complete | 35 focused tests / 538 assertions, Pint, view compilation, push and real mobile interaction passed; mobile height reduced ~3,426px | `fab18f7` pushed to `origin/main` | Inspect observability incident density and separate active response from historical management |

## Phase 10 — observability response and secondary panels

### Responsibility problem

Observability combined active response, resolved incident history, metric-rule
management, environment navigation, alert integrations, public status pages
and status updates into one always-expanded page. The original 390px review
measured about 12,629px, including an operational-incident block around
5,294px.

### Boundaries and preserved behavior

- `ObservabilityDashboardQuery`, the controller, policies, Form Requests and
  operational actions remain unchanged. The view consumes the same bounded,
  workspace-scoped collections and keeps every existing route and form.
- Active incidents remain visible with status, resource, owner and acknowledge
  controls. Their encrypted summary, event timeline, assignment, notes and
  resolution controls are grouped in one native response disclosure.
- Resolved incidents are separated into a collapsed history disclosure. The
  existing export action, event ordering, recovery state and response routes
  remain unchanged.
- Metric rules, environment evidence, alert destinations, public status pages
  and status-page updates are explicit secondary disclosures. Validation errors
  reopen the management panels; active status updates reopen status history.
- A reusable incident-card partial now keeps response presentation cohesive
  without introducing a business-service abstraction or changing persistence.

### Verification

- Observability and operational-incident suites passed: 29 focused tests / 239
  assertions across the two commits, including active/resolved disclosure
  state, authorization ordering and response-action rendering.
- Pint, Blade view compilation and `git diff --check` passed.
- Commit `98e9e03` (`feat: streamline operational incident history`) and
  commit `a90c36d` (`feat: streamline observability management panels`) were
  pushed to `origin/main`; the isolated HTTPS runtime was cache-refreshed and
  both service units remained active.
- A real 390px browser check measured the page at about 7,507px. The incident
  block measured about 3,796px, resolved history was closed by default and
  opened successfully, and an active response disclosure opened to expose the
  existing resolve controls. Secondary management disclosures were closed by
  default.

| Phase 10: observability response and secondary panels | Complete | 29 focused tests / 239 assertions, Pint, view compilation, push and real mobile interactions passed; page height reduced ~5,122px | `98e9e03`, `a90c36d` pushed to `origin/main` | Inspect project and environment detail pages for the next summary-first workflow slice |

## Phase 11 — environment evidence controls

### Responsibility problem

The environment evidence page placed four filter controls, explanatory text,
save-view forms and saved investigation management before the evidence cards.
The default 390px page measured about 3,095px, with the first deployment
evidence beginning around 1,497px.

### Boundaries and preserved behavior

- `ObservabilityContextRequest`, immutable filter data, authorization, bounded
  query collections, share URLs and investigation actions remain unchanged.
- Environment identity, website/server/evidence-window summary cards remain
  visible. Filters are a native disclosure with a `Filtered` indicator and
  automatically reopen for non-default filters or validation errors.
- Save/manage investigation views are a separate disclosure with the existing
  expiration choices, hidden filter values, validation messages, ownership
  checks, share routes and removal forms unchanged.
- Deployment, health, runtime-log and incident evidence remains rendered with
  the same redaction, limits and authorization boundaries.

### Verification

- Environment context suite passed: 7 tests / 66 assertions, including default
  and active-filter disclosure state; Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `b031af2` (`feat: streamline environment evidence controls`) was
  pushed to `origin/main`; the isolated HTTPS runtime was cache-refreshed and
  both service units remained active.
- Real 390px browser checks measured the default page at about 2,529px, with
  first evidence around 976px. A non-default filter view measured about
  3,010px and reopened the filter disclosure.

| Phase 11: environment evidence controls | Complete | 7 focused tests / 66 assertions, Pint, view compilation, push and real mobile filter-state checks passed; default page height reduced ~566px | `b031af2` pushed to `origin/main` | Inspect project overview environment cards and keep readiness/action context discoverable |

## Phase 12 — account security hierarchy

### Responsibility problem

The account page placed profile editing, password changes, two-factor setup,
security activity, sign-in history, browser sessions, connected accounts and
account deletion in one uninterrupted mobile sequence. The original 390px
review measured about 4,791px, so routine profile and password tasks were
separated from less-frequent security and destructive workflows by excessive
scrolling.

### Boundaries and preserved behavior

- The existing account controllers, requests, policies, actions, named error
  bags and security forms remain unchanged. This is a presentation-only
  hierarchy improvement.
- Profile and password forms remain visible as the primary account tasks.
- Two-factor setup, security activity, sign-in history, browser sessions,
  connected accounts and account-data deletion are native disclosures. They
  reopen when their existing status, validation errors or pending setup state
  requires user attention.
- The reusable forms section component owns only the disclosure markup and
  keeps the desktop layout unchanged for existing callers. No credentials,
  session data, routes or authorization decisions were moved into the view.

### Verification

- Account management, lifecycle, security overview, sign-in history and
  two-factor suites passed: 54 tests / 356 assertions, including the default
  disclosure-state regression. Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `a994d10` (`feat: streamline account security panels`) was pushed to
  `origin/main`; the isolated HTTPS runtime was updated and both service units
  remained active.
- A real 390px browser check measured the default account page at about
  2,373px. Profile and password actions appeared at about 649px and 1,131px;
  the six secondary panels were closed by default. Opening browser sessions
  worked and exposed the existing “Log out other sessions” action, measuring
  about 2,570px while open.

| Phase 12: account security hierarchy | Complete | 54 focused tests / 356 assertions, Pint, view compilation, push and real mobile disclosure interaction passed; page height reduced ~2,418px | `a994d10` pushed to `origin/main` | Inspect backups, organization administration and automation workflows for the next dense secondary-panel slice |

## Phase 13 — workspace administration hierarchy

### Responsibility problem

Workspace administration placed the member list, a long security-policy form,
notification preferences, invitations, workspace switching and destructive
workspace deletion in one uninterrupted mobile flow. The original 390px
review measured about 3,238px even though most visitors only need to inspect
members or change one setting occasionally.

### Boundaries and preserved behavior

- `OrganizationController`, its Form Requests, policies, actions, invitation
  flow, membership protections and named deletion error bag remain unchanged.
  This is a presentation-only hierarchy slice.
- Members remain the first visible workspace task. Security policy,
  notification preferences, invitations and workspace switching are native
  secondary disclosures. Workspace deletion retains its warning styling and
  becomes an explicit disclosure rather than occupying the default flow.
- Relevant validation errors reopen only their associated panel. Existing
  password, two-factor, invitation and membership fields remain in the same
  forms; no secrets, routes, status codes or authorization decisions moved.
- The shared forms-section component forces the form body to remain visible at
  desktop widths when its mobile disclosure is closed, preventing responsive
  content loss.

### Verification

- Organization management and account regression suites passed: 37 tests /
  232 assertions. Coverage includes default panel state, security-policy
  validation reopening, owner protection, invitation behavior, membership
  authorization and named deletion errors. Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `e7b749f` (`feat: streamline workspace administration panels`) was
  pushed to `origin/main`; the isolated HTTPS runtime was updated and both
  service units remained active.
- A real 390px browser check measured the default workspace page at about
  1,307px, a reduction of roughly 1,931px. All five secondary panels were
  closed by default. A follow-up live click-through was limited by the
  isolated host reaching 100% disk usage; the disclosure and validation paths
  remain covered by the feature suite and compiled markup.

| Phase 13: workspace administration hierarchy | Complete | 37 focused tests / 232 assertions, Pint, view compilation, push and real mobile height measurement passed; page height reduced ~1,931px; live click-through deferred by host disk exhaustion | `e7b749f` pushed to `origin/main` | Inspect backups and automation for the next high-value workflow slice |

## Phase 14 — mobile backup recovery workflow

### Responsibility problem

Backup history used a six-column table with verification and restore forms in
wide cells. At the 390px review width the page measured about 2,105px and the
recovery controls required horizontal scrolling, making the most consequential
actions difficult to discover and compare on a phone.

### Boundaries and preserved behavior

- `BackupController`, recovery evidence queries, policies, Form Requests,
  queued restore/verification actions and temporary-object connection probes
  remain unchanged. The slice changes only responsive presentation.
- Desktop users retain the existing table and its column semantics. Mobile
  users receive one native disclosure card per backup with website, status,
  snapshot, verification evidence, safe-verification and restore controls.
- Confirmation fields, CSRF protection, restore confirmation prompts,
  temporary-target wording, failed-verification retry behavior and credential
  redaction remain unchanged. Cards reopen when the existing confirmation
  validation bag contains an error.
- The existing backup table had a nested `@php` expression that could compile
  to invalid PHP when a verification record was rendered. It was rewritten as
  an equivalent explicit `match` block while adding the mobile partial.

### Verification

- Backup destination setup, recovery evidence and restore-verification suites
  passed: 18 tests / 136 assertions. This includes Spaces endpoint guidance,
  encrypted credential handling, temporary PUT/GET/DELETE verification,
  sanitized provider failures, foreign-workspace isolation, retry behavior and
  the new mobile-card/table rendering contract. Pint, Blade view compilation
  and `git diff --check` passed.
- Commit `678c5fc` (`feat: add mobile backup recovery cards`) was pushed to
  `origin/main`; the isolated HTTPS runtime was updated, view-cached and both
  service units remained active.
- The pre-change 390px baseline was about 2,105px. A post-change browser
  measurement was attempted but the shared isolated host was at 100% root
  disk usage and Chromium crashed before evaluation. No post-change height or
  click result is claimed; the responsive branches and error state are covered
  by Blade compilation and feature tests. Re-run the browser measurement after
  reclaiming isolated-host storage.

| Phase 14: mobile backup recovery workflow | Complete with browser follow-up | 18 focused tests / 136 assertions, Pint, view compilation, push and responsive rendering contract passed; post-change browser measurement deferred by host disk exhaustion | `678c5fc` pushed to `origin/main` | Inspect automation/API density and separate token management from per-application workflows |

## Phase 15 — automation token hierarchy

### Responsibility problem

Automation combined API credential issuance, the token inventory, CLI
documentation and per-application workflow editors. The token form and list
were always in the first mobile flow even when a user only needed to inspect
or edit a deployment workflow. The original 390px review measured about
2,067px before the application workflow disclosures were considered.

### Boundaries and preserved behavior

- `AutomationController`, token Form Requests, policies, entitlements,
  Sanctum actions, workflow validation and API response contracts remain
  unchanged. This slice changes presentation only.
- CLI quick-start guidance remains visible beside the token management panel.
  Token management is a native disclosure with a count summary; the one-time
  plaintext-token result and token-specific validation errors reopen it.
- Token abilities, expiry choices, rotate/revoke forms, API documentation,
  feature badges and application workflow details remain unchanged. Desktop
  token content is forced visible when the mobile disclosure is closed.

### Verification

- The complete Automation suite passed: 34 tests / 169 assertions, including
  workflow validation, schedule and task authorization, entitlements, API
  envelopes, token ownership, token rotation and one-time token disclosure
  state. Pint, Blade view compilation and `git diff --check` passed.
- Commit `b58fcb0` (`feat: streamline automation token management`) and the
  follow-up regression commit `406b971` (`test: preserve automation token
  result disclosure`) were pushed to `origin/main`; the isolated HTTPS
  runtime was updated, view-cached and both service units remained active.
- A post-change browser height measurement is deferred because the isolated
  host remains at 100% root disk usage and Chromium crashes before evaluation.
  No post-change height reduction is claimed; the mobile disclosure, desktop
  visibility safeguard and one-time result behavior are covered by feature
  tests and compiled markup.

| Phase 15: automation token hierarchy | Complete with browser follow-up | 34 focused tests / 169 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `b58fcb0`, `406b971` pushed to `origin/main` | Inspect providers, servers and websites for repeated inventory/setup density and the next shared presentation boundary |

## Phase 16 — infrastructure inventory filter hierarchy

### Responsibility problem

Provider, server and website inventory pages placed their full filter forms in
the default mobile flow, even when a user only needed to scan inventory or
open a resource. The three pages repeated the same interaction problem with
different filter counts and the controls also sat above their summary metrics.

### Boundaries and preserved behavior

- The existing provider, server and website controllers, query collaborators,
  exports, pagination and authorization boundaries remain unchanged. This is
  a presentation-only slice using the existing `ui.filter-panel` component.
- Each inventory page now has a labeled native filter disclosure with a
  meaningful id and active-filter count. It opens automatically for a filtered
  request so selected controls remain visible after applying filters, and is
  collapsed by default for an unfiltered inventory view.
- Search normalization, allowed filter values, organization scoping, empty
  states, CSV URLs, pagination query strings and table content remain
  unchanged. No provider credentials or resource data were moved into the
  disclosure.

### Verification

- Provider, server and website inventory filter suites passed: 13 tests / 106
  assertions. Coverage includes active and default disclosure state, combined
  filters, tenancy, invalid values, pagination preservation, provisioning
  drill-downs, exports and provider resource counts. Pint, Blade view
  compilation and `git diff --check` passed.
- Commit `d4e34a1` (`ui: collapse infrastructure inventory filters`) was
  pushed to `origin/main`; the isolated HTTPS runtime was fast-forwarded,
  view-cached and both service units remained active.
- The pre-change 390px measurements were about 2,595px for providers,
  2,054px for servers and 2,090px for websites. A post-change browser
  measurement remains deferred because the isolated host is at 100% root disk
  usage and Chromium crashes before evaluation. No post-change height or
  interaction result is claimed; the default/active states are covered by the
  feature suite and compiled markup.

| Phase 16: infrastructure inventory filter hierarchy | Complete with browser follow-up | 13 focused tests / 106 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `d4e34a1` pushed to `origin/main` | Inspect project/application overviews and detail pages for repeated secondary panels, long timelines and action discoverability |

## Phase 17 — project form validation context

### Responsibility problem

The application detail page keeps environment settings, deployment controls,
variables, processes, resources, environment creation and preview settings in
native disclosures to keep the overview usable. After a failed submission,
however, the redirect returned with the relevant validation message while the
containing disclosure stayed closed. On a long project page this made the
error and the field that needed correction difficult to find.

### Boundaries and preserved behavior

- `ProjectController`, `EnvironmentController`, existing Form Requests,
  policies, actions, validation keys, error bags and persistence remain
  unchanged. This is a presentation-state improvement.
- Each inline form carries a non-persisted marker identifying its environment
  and panel. A failed redirect flashes that marker with the existing input;
  the view uses it to reopen only the submitted panel. The marker is not part
  of `validated()` data and is never stored or sent to a provider.
- Settings, deployment controls, encrypted variables, workers/scheduler and
  attached resources receive stable disclosure ids. Add-environment and
  preview settings also reopen after their own validation failures. Default
  successful page loads remain collapsed, and existing preview cards still
  open when previews exist.
- No routes, HTTP status codes, success messages, authorization decisions,
  secret values, queue payloads or deployment behavior changed.

### Verification

- Project and preview regression suites passed: 29 tests / 287 assertions.
  This includes settings and deployment-control validation reopening only the
  submitted panel, preview validation reopening, protected environments,
  tenancy, encrypted variables, preview lifecycle, trust, secret approvals,
  quotas and cleanup. Pint, Blade view compilation and `git diff --check`
  passed.
- Commit `6e8f42e` (`ui: restore project panel validation context`) was pushed
  to `origin/main`; the isolated HTTPS runtime was fast-forwarded,
  view-cached and both service units remained active.
- The pre-change 390px project detail measurement was about 2,614px. A
  post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; the reopen behavior is
  covered by the feature suite and compiled markup.

| Phase 17: project form validation context | Complete with browser follow-up | 29 focused tests / 287 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `6e8f42e` pushed to `origin/main` | Inspect server, website and provider detail pages for action hierarchy, dense histories and error-state discoverability |

## Phase 18 — provider connection history disclosure

### Responsibility problem

Provider detail pages kept the newest twenty retained connection checks in the
default document flow below the connection metrics and action buttons. The
history is useful evidence, but it is secondary to the current connection
status and the actions to test or edit the provider. On a phone, the repeated
six-column table pushed attached resources farther down than necessary.

### Boundaries and preserved behavior

- `ProviderController`, `ProviderConnectionHistoryQuery`, policies, retained
  history limits, pagination/export routes and credential-safe rendering are
  unchanged. This is a presentation-only disclosure using the same loaded
  checks and metrics.
- The connection summary, failure state, test action, full-history link and
  export action remain visible. The newest checks now sit in a labeled native
  disclosure with a stable id, matching the existing website health-history
  pattern.
- The disclosure opens automatically when the current retained failure streak
  is non-zero, so a failing provider does not hide its evidence. Healthy
  histories remain collapsed by default. Table escaping, endpoint/error
  redaction, ordering and the existing 20/100 retained bounds are unchanged.

### Verification

- Provider connection insight, history and connection regression suites passed:
  20 tests / 255 assertions. Coverage includes healthy collapsed state,
  failure-streak open state, retained limits, filtered history, CSV export,
  tenancy, authorization, rate limits and sanitized failures. Pint, Blade view
  compilation and `git diff --check` passed.
- Commit `8e7a52c` (`ui: collapse provider connection history`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached and
  both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; open/collapsed behavior and
  the preserved evidence contract are covered by feature tests and compiled
  markup.

| Phase 18: provider connection history disclosure | Complete with browser follow-up | 20 focused tests / 255 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `8e7a52c` pushed to `origin/main` | Inspect server and website detail pages for secondary panels, dense metrics and operational error discoverability |

## Phase 19 — server operational panel hierarchy

### Responsibility problem

The server detail page placed the complete metrics card and diagnostic result
grid before attached websites and the existing logs/setup disclosure. Healthy
operational evidence was useful but secondary to the server identity, current
provisioning state and attached-resource actions. The result was a long first
visit, especially when metric history and several diagnostic checks existed.

### Boundaries and preserved behavior

- `ServerShow`, its Livewire actions, policies, polling conditions, diagnostic
  and log jobs, and all remote-operation boundaries remain unchanged. This
  slice changes only the presentation state of the existing component data.
- Metrics now use a stable `server-metrics` disclosure. A server with no sample
  opens it so `Collect now` and the existing empty state remain discoverable;
  completed metric history is available on demand.
- Diagnostics use a stable `server-diagnostics` disclosure. First-use, queued,
  running, failed and attention-required reports open automatically. A
  completed all-clear report is collapsed but its checks, safe details and
  rerun action remain one click away.
- Provisioning failures, retry/resume actions, command access, attached
  websites, log/setup recovery, polling, authorization and secret-safe output
  remain unchanged.

### Verification

- Server diagnostic, log snapshot and provisioning-log suites passed: 32 tests
  / 194 assertions. Coverage includes first-use and completed disclosure state,
  diagnostic queue/lease/stale-attempt behavior, pinned-host failures,
  provisioning recovery, log polling, bounded output, authorization and
  credential non-disclosure. Pint, Blade view compilation and `git diff --check`
  passed.
- Commit `57e1f6c` (`ui: prioritize server operational panels`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached and
  both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; operational open/closed states
  are covered by feature tests and compiled markup.

| Phase 19: server operational panel hierarchy | Complete with browser follow-up | 32 focused tests / 194 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `57e1f6c` pushed to `origin/main` | Inspect website detail layout and provisioning/setup disclosure states |

## Phase 20 — website provisioning and setup hierarchy

### Responsibility problem

Website detail pages already disclosed health history and runtime logs, but
provisioning output and the setup timeline remained in the normal document flow
after those sections. For a normally active website this was completed,
secondary information; for a pending or failed website it was operational
evidence that needed to remain visible. The page also contained two inline
Blade assignments that compiled unreliably under the current runtime.

### Boundaries and preserved behavior

- Website controllers, Livewire provisioning/setup components, policies,
  callbacks, polling, retries, log downloads, retention updates and encrypted
  data handling remain unchanged. The slice changes only presentation grouping
  and normalizes the existing view assignments into compiler-safe blocks.
- Provisioning output and setup are now grouped under the stable
  `website-operations` disclosure. In-progress, failed, canceled/unknown and
  previous-placement-cleanup states open automatically; a normally active
  website collapses the completed operational detail while leaving it one click
  away.
- Health failure alerts, health history/runtime-log attention states, attached
  repositories, setup status text, bounded log output, polling and all existing
  routes/forms remain in place.

### Verification

- Website provisioning, health-history, health-insight and release-retention
  suites passed: 25 tests / 212 assertions. Coverage includes active and
  incomplete disclosure state, callback-scoped output, polling transitions,
  bounded/escaped logs, health export and pagination, monitoring metrics,
  retention safety and authorization. Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `6079a5e` (`ui: collapse website setup operations`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached and
  both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; disclosure state, polling and
  rendering are covered by feature tests and compiled markup.

| Phase 20: website provisioning and setup hierarchy | Complete with browser follow-up | 25 focused tests / 212 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `6079a5e` pushed to `origin/main` | Inspect repository detail for setup/webhook/deployment hierarchy and validation-state context |

## Phase 21 — repository deployment insight hierarchy

### Responsibility problem

Repository detail already led with the latest deployment and kept webhook,
setup and history sections discoverable. Its five-card deployment-insights
grid, however, remained in the default flow between webhook configuration and
deployment history. Those aggregate totals are useful for investigation but
secondary to the latest outcome and current deployment actions.

### Boundaries and preserved behavior

- `RepositoriesController`, deployment insight query logic, build routes,
  webhook routes, policies and deployment operations remain unchanged. This is
  a presentation-only disclosure over the existing metrics.
- `repository-deployment-insights` now has a compact summary showing recorded
  deployment count and completed-run success rate. The full totals and median
  duration cards remain available inside it.
- The summary opens automatically when the latest build is active or failed,
  matching the existing setup/history attention behavior. Healthy completed
  deployments and empty repositories keep aggregate detail collapsed without
  changing any values or links.

### Verification

- Repository deployment-insight, webhook-delivery, deployment and webhook
  suites passed: 33 tests / 298 assertions. Coverage includes healthy/active
  latest-build states, preflight and entitlement behavior, deployment
  idempotency, webhook authentication/replay/path filtering, safe exports,
  pagination, tenancy and secret exclusion. Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `eab8c62` (`ui: collapse repository deployment insights`) was pushed
  to `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; attention states and preserved
  insight links are covered by feature tests and compiled markup.

| Phase 21: repository deployment insight hierarchy | Complete with browser follow-up | 33 focused tests / 298 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `eab8c62` pushed to `origin/main` | Inspect notifications and observability pages for secondary statistics, saved views and filters that still precede primary results |

## Phase 22 — database management workflow hierarchy

### Responsibility problem

Each database inventory card mixed read-only resource evidence with credential
issuance, a list of existing credentials and a destructive clone-confirmation
form. With multiple resources, these management controls dominated the default
inventory and made size, connection and schema information harder to scan.

### Boundaries and preserved behavior

- `DatabaseController`, Form Requests, policies, entitlement checks, database
  actions/jobs and target-safety rules remain unchanged. The view still uses the
  same eager-loaded resources and snapshot data.
- Read-only resource identity, latest size/connections/schema and Inspect stay
  visible. Credential issuance, credential revocation and non-production clone
  confirmation now share a per-resource `database-management-{id}` disclosure.
- A resource with no credential opens the management panel for first-use
  discoverability. Validation errors and the one-time generated password also
  reopen it; a resource with completed setup collapses the optional workflow.
  Credentials remain encrypted/hidden and clone confirmation/production and
  tenancy protections are unchanged.
- The existing inline `@php` snapshot assignment was normalized to a block form
  so the database page compiles consistently with the current Blade runtime.

### Verification

- Database operations and platform-expansion suites passed: 14 tests / 76
  assertions. Coverage includes inspection scoping, credential creation and
  encryption, viewer authorization, unsupported resources, clone confirmation,
  production/foreign target rejection and first-use/completed disclosure state.
  Pint, Blade view compilation and `git diff --check` passed.
- Commit `b942715` (`ui: separate database management actions`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached and
  both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; the resource summary, form
  availability and open-state behavior are covered by feature tests and
  compiled markup.

| Phase 22: database management workflow hierarchy | Complete with browser follow-up | 14 focused tests / 76 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `b942715` pushed to `origin/main` | Inspect domains and load-balancer inventories for side-by-side management forms and error-state discoverability |

## Phase 23 — domain management hierarchy

### Responsibility problem

The Domains & TLS page kept alias/redirect creation and temporary-domain
issuance as two full-height cards beside every website. On a phone, those
forms preceded the domain inventory's secondary actions and made the current
DNS/TLS state harder to scan. Validation failures also returned to a page
where the relevant controls were not visibly grouped or reopened.

### Boundaries and preserved behavior

- `DomainController`, its Form Requests, website update policy, domain actions,
  Cloudflare adapters, queue dispatch and sanitized failure behavior remain
  unchanged. This slice is a presentation and feedback improvement over the
  existing request boundaries.
- The website/domain inventory remains visible first. Add-domain and
  temporary-domain forms now share the stable `domain-management` disclosure;
  the compact summary is mobile-only while the form body remains visible on
  large screens.
- Validation, DNS and operation errors reopen the disclosure and are rendered
  near the affected controls. Non-sensitive old input restores hostname,
  redirect and provider selections after validation without exposing tokens.
- Routes, HTTP methods, authorization ordering, provider scoping, temporary
  domain configuration checks, TLS/DNS status rendering and queued proxy
  synchronization are unchanged.

### Verification

- `DomainManagementTest` passed: 8 tests / 49 assertions, including alias
  creation, temporary-domain configuration, viewer denial before validation,
  sanitized DNS failures, primary-domain safeguards, queued deletion, routing,
  and collapsed/reopened management state. Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `ee39d5a` (`Improve domain management hierarchy`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; disclosure state and rendered
  validation feedback are covered by feature tests and compiled markup.

| Phase 23: domain management hierarchy | Complete with browser follow-up | 8 focused tests / 49 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `ee39d5a` pushed to `origin/main` | Inspect load-balancer create/node workflows for dense controls and first-use/error-state hierarchy |

## Phase 24 — load-balancer workflow hierarchy

### Responsibility problem

The high-availability page kept route creation, application-node inventory and
node mutation controls in the default document flow. A new workspace therefore
opened with a long setup form, while a configured route continued to show all
node controls even after the healthy topology was complete. Validation feedback
also had no panel-specific context.

### Boundaries and preserved behavior

- `LoadBalancerController`, Form Requests, policies, entitlement checks,
  actions, apply/remove jobs and remote configuration behavior remain
  unchanged. The UI markers used to restore context are ignored by validated
  action data and do not alter persistence.
- Empty workspaces keep route creation open for first-use guidance. Once a
  route exists, its creation form collapses on mobile and reopens only for its
  own validation state; non-sensitive inputs are restored after failure.
- Application-node management is grouped per route. It opens while a route has
  fewer than two nodes or a failed state, then collapses after a healthy
  two-node topology. Status and Apply remain in the always-visible route
  header.
- Node and route actions, authorization/entitlement ordering, validation keys,
  response messages, queue dispatch, self-routing safeguards and remote
  lifecycle semantics are unchanged.

### Verification

- `LoadBalancerOperationsTest` passed: 5 tests / 32 assertions, including
  lifecycle jobs, self-routing rejection, viewer denial before validation,
  entitlement enforcement and empty/incomplete/complete disclosure states.
  Pint, Blade view compilation and `git diff --check` passed.
- Commit `6ad6be9` (`Improve load balancer workflow hierarchy`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; route/node attention states and
  validation context are covered by feature tests and compiled markup.

| Phase 24: load-balancer workflow hierarchy | Complete with browser follow-up | 5 focused tests / 32 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `6ad6be9` pushed to `origin/main` | Audit remaining dense settings and operational pages for primary-result hierarchy and progressive disclosure |

## Phase 25 — recipe and gallery discovery hierarchy

### Responsibility problem

The private recipe inventory and community gallery both placed a multi-field
filter form and a full metric grid before the first recipe card/row. Those
metrics are useful for planning and reporting, but they are secondary to
finding a recipe, opening its script, saving it or publishing a change. The
two surfaces also needed a consistent way to distinguish active filters from
the default browse state.

### Boundaries and preserved behavior

- `RecipesController`, `RecipeGalleryController`, Form Requests, scoped query
  services, metrics, publication/install/favorite/report actions and pagination
  are unchanged. No query is moved into Blade and no result ordering or URL
  parameter changes.
- Both pages now use the existing `x-ui.filter-panel` primitive. It remains
  collapsed for the default browse state and opens automatically when a
  non-default filter is present, with a compact active-filter count.
- The existing metric values now live in `recipe-insights` and
  `gallery-insights` disclosures. Their summaries keep the matching/published
  count visible while the first useful result appears earlier in the document.
- Recipe/gallery cards, script safety warnings, save/install/update/report
  actions, exports, pagination, SQL-wildcard handling and privacy boundaries
  remain unchanged.

### Verification

- Recipe inventory and gallery suites passed: 16 tests / 158 assertions,
  including scope/tenancy, filtering, SQL wildcard safety, install/update and
  publication behavior, script secrecy, pagination and the new default/active
  disclosure states. Pint, Blade view compilation and `git diff --check`
  passed.
- Commit `74b1375` (`Prioritize recipe discovery results`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; result priority, filter state
  and preserved actions are covered by feature tests and compiled markup.

| Phase 25: recipe and gallery discovery hierarchy | Complete with browser follow-up | 16 focused tests / 158 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `74b1375` pushed to `origin/main` | Audit report/feedback, billing/cost and search surfaces for filter and primary-result ordering |

## Phase 26 — recipe feedback result hierarchy

### Responsibility problem

Contributor feedback inboxes and reporter history pages repeated the same
long-form browse pattern: seven or more filters, a full metric grid, and then
the actual report cards. Bulk resolve/reopen controls and report details are
the primary work, while aggregate counts are supporting context.

### Boundaries and preserved behavior

- `RecipeReportsController`, Form Requests, `RecipeReportQuery`, exporters,
  policies, named bulk-validation bags, pagination and all report actions are
  unchanged. No filter normalization, ownership scope, anonymity rule or
  notification behavior moved into the view.
- Contributor inbox filters now use `gallery-report-filters`; reporter history
  uses `gallery-report-history-filters`. Both remain closed for their default
  state and reopen automatically when a non-default filter is active.
- Contributor and reporter metric grids now use separate Insights disclosures
  with compact result summaries. Bulk controls, report cards, status links,
  resolution notes and pagination stay in the normal result flow.
- Filter URLs, CSV exports, report anchors, independent bulk forms and
  `bulkResolve`/`bulkReopen` error bags remain unchanged.

### Verification

- Feedback inbox and reporter-history suites passed: 31 tests / 337
  assertions, including tenant/anonymity boundaries, pagination, filter and
  export preservation, bulk selection/atomicity, notification review and the
  new default/active disclosure states. Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `758b1fd` (`Prioritize gallery feedback results`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; report priority, filter state,
  bulk controls and preserved privacy behavior are covered by feature tests
  and compiled markup.

| Phase 26: recipe feedback result hierarchy | Complete with browser follow-up | 31 focused tests / 337 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `758b1fd` pushed to `origin/main` | Inspect billing/cost, search and system-health pages for secondary statistics and action hierarchy |

## Phase 27 — cost resource priority

### Responsibility problem

Cost visibility opened with four large summary cards before the resource
estimates that users actually need to inspect. The summary duplicated the
resource list and pushed provider/source attribution and cleanup signals down
the page, while a warning state did not explicitly control whether its context
was visible.

### Boundaries and preserved behavior

- `CostController`, `InfrastructureCostQuery`, `PreviewUsageQuery`, budget
  authorization/action boundaries, entitlement checks and provider-catalog
  semantics remain unchanged.
- The four summary values now share `cost-summary`. A healthy priced inventory
  keeps the aggregate grid collapsed while the estimated monthly amount stays
  visible in the summary; idle resources or unknown prices automatically open
  the grid so attention context is not hidden.
- Resource estimates, project attribution, measured CPU wording, preview
  lifetime/quota distinction, budget editing and provider-invoice disclaimer
  remain in the normal workflow with all existing links and messages.

### Verification

- Product-improvement and billing suites passed: 19 tests / 73 assertions,
  including cost scoping, budget authorization, catalog uncertainty, preview
  quota/expiry semantics, entitlement behavior and Stripe checkout/ownership
  behavior. Pint, Blade view compilation and `git diff --check` passed.
- Commit `00c8cc2` (`Prioritize cost resource estimates`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; attention-aware disclosure and
  preserved cost semantics are covered by feature tests and compiled markup.

| Phase 27: cost resource priority | Complete with browser follow-up | 19 focused tests / 73 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `00c8cc2` pushed to `origin/main` | Improve global-search result grouping and context |

## Phase 28 — global-search result navigation

### Responsibility problem

Global search already grouped results by resource type, but a query returning
several groups required continuous scrolling and offered no stable way to jump
to a type. Group headers also did not expose the result count or a semantic
relationship to their result card.

### Boundaries and preserved behavior

- `SearchController` and every workspace-scoped query remain unchanged. Search
  trimming, SQL wildcard escaping, owner metadata limits, secret exclusion,
  group ordering, “view more” URLs and authentication boundaries are intact.
- A result-only in-page index now lists each populated resource group with its
  bounded count and anchor. Each group card has a stable id and labeled
  heading, and the existing per-result links remain ordinary server-rendered
  anchors.
- Blank and no-result guidance remains unchanged; no new client-side search
  state or unbounded result loading was introduced.

### Verification

- `GlobalSearchTest` passed: 9 tests / 82 assertions, covering verification,
  owner scoping, all seven resource groups, bounded “view more” behavior,
  literal wildcard handling, secret exclusion and the new jump-link/group
  context markup. Pint, Blade view compilation and `git diff --check` passed.
- Commit `2064011` (`Improve search result navigation`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or keyboard result is claimed; anchors, labels and
  preserved result semantics are covered by feature tests and compiled markup.

| Phase 28: global-search result navigation | Complete with browser follow-up | 9 focused tests / 82 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `2064011` pushed to `origin/main` | Audit system-health/admin and activity/command histories for first-result and action priority |

## Phase 29 — operational history priority

### Responsibility problem

Activity and Command Center pages both placed a large filter form and a
multi-card metric grid ahead of the event/history list. That made the first
audit event or command difficult to reach on a phone, while active command
state could be separated from the table that required refresh.

### Boundaries and preserved behavior

- `ActivityController`, `ActivityQuery`, `CommandsController`, workspace
  scoping, date normalization, export services, pagination, refresh links and
  secret-safe projections remain unchanged. No command text or output is newly
  rendered.
- Activity filters use `activity-filters`; command filters use
  `command-filters`. Both are closed by default and reopen for active query
  parameters with the existing values and export URLs preserved.
- Aggregate metrics use `activity-insights` and `command-insights`. Active
  command metrics open automatically so queued/running context remains visible;
  an empty filtered activity view opens its zero-state metrics for explanation.
- Event feed content, command table, selected server history links, CSV
  escaping, owner boundaries and verification redirects remain unchanged.

### Verification

- Activity and command suites passed: 12 tests / 109 assertions, including
  owner scoping, filter/date normalization, literal wildcard handling, export
  behavior, command output/secret exclusion, active refresh state, pagination
  and the new default/active disclosure states. Pint, Blade view compilation
  and `git diff --check` passed.
- Commit `0f080c4` (`Prioritize activity and command history`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or keyboard result is claimed; history priority, active
  reopening and preserved metadata contracts are covered by feature tests and
  compiled markup.

| Phase 29: operational history priority | Complete with browser follow-up | 12 focused tests / 109 assertions, Pint, view compilation and push passed; post-change browser measurement deferred by host disk exhaustion | `0f080c4` pushed to `origin/main` | Complete system-health/admin and public documentation/status page hierarchy audit |

## Phase 30 — admin analytics insight priority

### Responsibility problem

Business Analytics placed nine aggregate cards before the signup and
deployment charts and the pending-access context that can require immediate
admin action. On a healthy platform this pushed the useful trend evidence
below a large summary block; on a platform needing attention, the summary did
not communicate why it was expanded.

### Boundaries and preserved behavior

- `AdminAnalyticsController`, `BusinessAnalytics`, platform-admin
  authorization, metric definitions, date windows and privacy-preserving
  aggregates remain unchanged.
- The aggregate cards now share `admin-analytics-summary`. The summary stays
  collapsed when there are no pending access requests or recent limit denials,
  while those attention states open it automatically. User count and pending
  access count remain visible in the summary row.
- Signup/deployment trends, plan distribution, existing admin links and
  private analytics boundaries remain in the same server-rendered response.

### Verification

- Admin analytics and access-request suites passed: 16 tests / 111
  assertions, including platform-admin authorization, privacy-safe denial
  counting, pending-access disclosure and existing request validation,
  invitation, export and pruning behavior. Pint, Blade view compilation and
  `git diff --check` passed.
- Commit `e448fc3` (`Prioritize admin analytics insights`) was pushed to
  `origin/main`; the isolated HTTPS runtime was fast-forwarded, view-cached
  and both service units remained active.
- A post-change browser measurement remains deferred because the isolated host
  is at 100% root disk usage and Chromium crashes before evaluation. No
  post-change height or click result is claimed; attention-aware disclosure,
  platform-admin access and preserved metrics are covered by feature tests and
  compiled markup.

| Phase 30: admin analytics insight priority | Complete with browser follow-up | 16 focused tests / 111 assertions, Pint, view compilation and `git diff --check` passed; post-change browser measurement deferred by host disk exhaustion | `e448fc3` pushed to `origin/main` | Audit public documentation, API documentation, status and authentication page hierarchy |

Known limitations retained from earlier work: the separate live acceptance
drill, production release gates, physical-phone checks and any external
provider acceptance remain outside this UI implementation.
