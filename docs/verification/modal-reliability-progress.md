# Modal reliability progress

This ledger records the contextual-modal implementation slices from the
2026-09-20 audit. Each implementation slice is completed, verified, committed
and pushed before the next slice begins.

## Audit and plan — 2026-09-20

Status: recorded before implementation.

- Plan: `docs/modal-reliability-and-coverage-plan-2026-09-20.md`.
- Coverage record: `docs/verification/modal-audit-2026-09-20.json`.
- Audited served development revision: `aa84baf`.
- Audited implementation revision: `490b21d`.
- Authenticated read-only crawl: 251 mobile page URLs, 83 desktop page
  checks, 8 additional state/configuration checks and 12 public mobile checks.
- Route inventory: 130 application web GET routes classified; 76 directly
  visited; protocol, fragment, download, external-service and state-dependent
  routes explicitly recorded as not directly visited.
- Confirmed first slice: provider filter scroll locking, editor cancellation,
  modal history restoration, desktop active-filter overflow and no-JavaScript
  filter fallback.

## Slice 1 — shared filter and modal lifecycle reliability

Status: complete; committed and pushed as `3e69b7b`.

### Responsibility problem

Filter presentation, modal scroll locking, lazy content loading and browser
history were coupled in the shared layout. That caused desktop inline filters to
lock the page, mobile filters to hide their server-rendered form without
JavaScript, Cancel links to navigate away from the current page, stale lazy
responses to win after a newer request, and Forward navigation to restore a URL
without reopening its dialog.

### Boundaries and benefit

- `filter-panel` remains responsible for the filter form and trigger.
- Shared layout JavaScript remains responsible for modal lifecycle, scroll lock,
  loading and history; it now distinguishes inline filters from blocking sheets.
- Dialog content links explicitly opt into local cancellation through
  `data-modal-cancel`; ordinary links retain normal navigation behavior.
- Existing controllers, requests, policies, actions, fragment endpoints and
  fallback URLs remain unchanged.

This applies single responsibility at the presentation/runtime boundary and
preserves dependency direction: no business operation or provider adapter was
introduced for a UI lifecycle fix.

### Preserved behavior and safety

- GET filter values, query keys, pagination and active summaries are unchanged.
- Dialog content remains same-origin and lazy; stale responses are ignored after
  both fetch completion and response-body parsing.
- Cancel, Escape, X, Back and Forward do not submit forms or change persistence.
- No provider connection call is made while opening an editor.
- No-JavaScript users retain a server-rendered filter form.

### Verification

- Required PHP 8.5.10 focused regression: **26 tests / 184 assertions passed**.
- Vite production asset build: passed.
- Pint: passed.
- Focused browser matrix: **3 tests passed in 1.3 minutes** for cancellation/
  history, no-JavaScript filters and modal scroll behavior.
- `git diff --check`: passed.

### Commit and push

Commit and push: `3e69b7b Fix modal history and filter fallbacks`.

### Exact next task

Implement the first bounded read-only inspector from the route audit: provider
connection history in context, while preserving the full history page and export.

## Slice 3 — provider connection-history inspector

Status: complete; committed and pushed as 0bd0bda.

### Responsibility problem

The provider detail page exposed only a direct navigation link for retained
connection checks. Opening it discarded the provider context and made a common
read-only investigation unnecessarily expensive on mobile. The existing full
history controller already owned the correct organization authorization,
filters, ordering, pagination and export semantics, so duplicating those reads
in the provider page would have created a second source of truth.

### Boundaries and benefit

- `ProviderController::connectionChecks()` remains the single authorized read
  boundary and now renders either the existing full-page shell or a body-only
  `fragment=provider-connection-checks` response.
- The shared connection-history content partial owns the repeated filter,
  insight, result-list and pagination presentation.
- The provider detail view owns only the trigger and modal shell.
- Shared modal JavaScript owns fragment form refreshes and supports a separate
  modal history URL from the full-page fallback link.

This applies single responsibility without introducing a repository or a new
provider abstraction: the existing query collaborator and authorization policy
are reused unchanged.

### Preserved behavior and safety

- The direct history route, no-JavaScript fallback, result/source/date filters,
  pagination query values, ordering and CSV export remain available.
- Fragment requests authorize before querying and use the same normalized
  filters and metrics as the full page.
- Provider secrets and response bodies remain excluded from the inspector.
- Applying filters refreshes only the dialog content; the background document
  and scroll position remain in place.
- Browser history opens and closes the inspector predictably, while a direct
  full-page visit still works when JavaScript is unavailable.
- Export continues to navigate/download from its existing route rather than
  being reimplemented in the modal.

### Verification

- Provider history regression: **7 tests / 103 assertions passed**.
- Pint on changed PHP files: passed.
- Vite production asset build: passed.
- Focused browser test: **1 test passed in 1.3 minutes** using PHP 8.5.10;
  verified modal opening, fragment filter refresh, background path stability,
  Escape and focus restoration.
- `git diff --check`: passed.

### Commit and push

Commit and push: `0bd0bda Open provider connection history in a dialog`.

### Exact next task

After this slice is committed and pushed, inspect the website detail route for
a bounded read-only health/checks inspector. Reuse its existing query and
authorization boundary, and keep setup, provisioning and destructive actions
as pages or explicit workflows.

## Slice 4 — website health-history inspector

Status: complete; committed and pushed as 4a80c23.

### Responsibility problem

The website detail page exposed only a full-page link for retained health
checks, even though the page already summarized current health and recent
results. Moving to the history page interrupted investigation on small screens.
The existing health-history controller and query collaborator already provided
the correct scoped filters, ordering, metrics, pagination and export behavior;
the UI needed a contextual read-only shell rather than a second health system.

### Boundaries and benefit

- `WebsitesController::healthChecks()` remains the single authorized read
  boundary and now renders either the existing full-page shell or a body-only
  `fragment=website-health-checks` response.
- The health-history content partial owns filter, insight, result-list and
  pagination presentation for both surfaces.
- The website detail view owns only the trigger and modal shell.
- The existing health monitor, manual-check action, runtime logs and
  provisioning lifecycle remain outside the inspector.

This mirrors the provider inspector where the data semantics genuinely match,
while keeping website-specific health labels and cards explicit rather than
introducing a generic cross-resource repository.

### Preserved behavior and safety

- The direct health-history route, no-JavaScript fallback, result/source/date
  filters, pagination and spreadsheet-safe export remain available.
- Fragment requests authorize before querying and use the existing normalized
  filters and bounded history query.
- Health checks remain read-only in the modal; “Check health now” continues to
  be an explicit POST workflow with its existing queue and status behavior.
- Website URLs, response errors and retained observation data keep their
  existing escaping and scoped-authorization behavior.
- Applying filters refreshes only the dialog content and keeps the website
  detail page and scroll position in place.

### Verification

- Website health-history regression: **11 tests / 113 assertions passed**.
- Pint on changed PHP files: passed.
- Vite production asset build: passed.
- Focused browser test: **1 test passed in 1.5 minutes** using PHP 8.5.10;
  verified modal opening, fragment filter refresh, background path stability,
  Escape and focus restoration.
- `git diff --check`: passed.

### Commit and push

Commit and push: `5d3e515 Open website health history in a dialog`.

### Exact next task

After this slice is committed and pushed, inspect deployment history and
bounded command/task output links for contextual read-only inspectors. Keep
deployment execution, rollback, cancellation and retry as explicit workflows.

## Slice 5 — website deployment-history timeline

Status: complete; committed and pushed as 027f931.

### Responsibility problem

The website detail page sent users to the full deployment inventory for a
common read-only question: what changed recently and what state is it in? That
transition was especially costly on mobile. Deployment execution, approvals,
rollback and cancellation are state-changing workflows and should not be
collapsed into a read-only modal, so the bounded improvement is a timeline
inspector only.

### Boundaries and benefit

- `BuildsController::index()` remains the workspace-scoped deployment-history
  read boundary and now serves a body-only `fragment=deployment-history` view
  for a website filter.
- `BuildInventoryQuery` remains the source of filtered builds and metrics;
  no website-page-specific query or repository wrapper was introduced.
- The website detail view owns the trigger and modal shell.
- The new timeline partial presents bounded revision, status, trigger, timing
  and full-detail links. Build show pages retain all operational actions.

This keeps HTTP coordination, scoped inventory reads, presentation and
state-changing deployment operations separate while reusing the existing
query and policy boundaries.

### Preserved behavior and safety

- The full `/builds` inventory, all existing filters, pagination, export and
  workspace scoping remain unchanged for normal requests.
- The fragment uses the validated `BuildIndexRequest` filters and the same
  organization-scoped query, so foreign website/repository builds cannot leak.
- The modal is read-only. Links to a deployment detail page deliberately leave
  the modal so approval, retry, rollback, cancellation and logs keep their
  existing page/workflow semantics.
- No deployment, remote command, queue dispatch or database write happens on
  modal open.
- JavaScript-disabled users retain the original filtered builds URL.

### Verification

- Build-history regression: **6 tests / 47 assertions passed**.
- Pint on changed PHP files: passed.
- Vite production asset build: passed.
- Focused browser test: **1 test passed in 1.5 minutes** using PHP 8.5.10;
  verified timeline rendering, contextual URL stability, full-history fallback
  and focus restoration.
- `git diff --check`: passed.

### Commit and push

Commit and push: `8339d5e Show website deployment history in a dialog`.

### Exact next task

After this slice is committed and pushed, inspect bounded server command/task
output and report-status links for the next read-only contextual inspector.
Keep remote execution, retry, provisioning and report mutations explicit.

## Slice 6 — server command-history inspector

Status: complete; committed and pushed as 32fabc1.

### Responsibility problem

The server detail page sent users to the full command center just to inspect
recent execution state or retrieve retained output. That was a costly context
switch, but the command center also contains cancel, rerun and delete actions
that must remain explicit workflows. The right boundary is a read-only
history/output inspector, not a modal copy of the command center.

### Boundaries and benefit

- `ServerCommandsController::index()` remains the authorized, server-scoped
  history read boundary and now serves a body-only
  `fragment=server-command-history` response.
- The fragment reuses the existing command filters, metrics and ownership
  checks, but intentionally renders only bounded metadata and output-download
  links.
- The Livewire server detail view owns the trigger and a `wire:ignore` modal
  shell so server polling cannot replace the read-only dialog contents.
- Remote command execution remains in the existing Livewire command component;
  cancel, rerun and delete remain on the full history page.

This separates inspection from operation while preserving the existing action,
authorization and queue boundaries.

### Preserved behavior and safety

- The full command-history page, filters, pagination, export, cancel, rerun,
  deletion and download routes remain unchanged for normal requests.
- Fragment requests authorize before querying and remain scoped to the exact
  server; command text is escaped and retained output is not rendered inline.
- Opening the modal does not execute, cancel, rerun or delete a command and
  does not expose output to the page; existing download authorization remains
  the boundary for retrieving it.
- JavaScript-disabled users retain the original command-history URL.
- The modal works alongside Livewire's polling without making the modal itself
  a Livewire state-changing surface.

### Verification

- Server command-history regression: **4 tests / 28 assertions passed**.
- Pint on changed PHP files: passed.
- Vite assets were already built for this Blade-only slice; no asset source
  changed.
- Focused browser test: **1 test passed in 1.6 minutes** using PHP 8.5.10;
  verified command metadata, download-link presence, output non-disclosure,
  contextual URL stability and focus restoration.
- `git diff --check`: passed.

### Commit and push

Commit and push: 32fabc1 Open server command history in a dialog.

### Exact next task

After this slice is committed and pushed, inspect scheduled-task output and
report-status/notification destinations for one more bounded read-only
inspector. Keep task execution, retry, incident changes and report mutations
as explicit workflows.

## Slice 2 — shared workspace search

Status: complete; committed and pushed as `af2e8eb`.

### Responsibility problem

The dashboard Search workspace link navigated to a long full-page search result,
while the sidebar, mobile navigation and command palette each exposed separate
search entry points. This made a common navigation task especially costly on
small screens and duplicated the user's mental model.

### Boundaries and benefit

- `SearchController` remains the single workspace-scoped read boundary and now
  serves a body-only `fragment=workspace` response using the same query and
  result groups as the canonical page.
- The authenticated layout owns one shared search/navigation dialog and its
  debounced, abortable client interaction.
- `_workspace-results.blade.php` owns only compact result presentation.
- Existing resource routes and full-page `/search` remain the no-JavaScript and
  deep-link boundaries.

This keeps HTTP coordination, scoped reads and presentation separate without
adding a generic repository or a second search implementation.

### Preserved behavior and safety

- Organization scoping, literal LIKE escaping, query length, result limits,
  ordering, group labels and View more URLs remain unchanged.
- Search results never expose provider tokens, scripts, environment values or
  other secret metadata.
- Dashboard, desktop sidebar, mobile navigation, mobile quick action and
  Ctrl/Cmd+K all use the same shell; their full-page `/search` links remain
  available when JavaScript is disabled.
- Resource selection still navigates intentionally; opening search itself does
  not change the background URL.
- Superseded queries are aborted and stale response bodies are ignored.
- Existing modal creation actions and keyboard focus flows remain intact.

### Verification

- Global search regression: **10 tests / 89 assertions passed**.
- Local UI asset regression: passed in the focused run.
- Blade view cache: passed.
- Pint: passed.
- Focused browser matrix: **3 tests passed in 1.6 minutes**, including the
  dashboard dialog, debounced result fragment and existing modal flows.
- `git diff --check`: passed.

### Commit and push

Commit and push: `af2e8eb Open workspace search in a shared dialog`.

### Exact next task

Implement the first bounded read-only inspector from the route audit: provider
connection history in context, while preserving the full history page and export.

## Slice 7 — scheduled-task output inspector

Status: complete; committed and pushed as 4a80c23.

### Responsibility problem

Recent scheduled-task run links on Automation navigated directly to a plain-text
output response. That discarded the application and environment context, while
the task controls themselves should remain explicit state-changing workflows.
The existing authorized output endpoint already owns the correct environment
policy and raw-response compatibility, so the modal adds only a read-only
presentation boundary.

### Boundaries and benefit

- AutomationController::scheduledTaskOutput() remains the single authorized
  run-output boundary and now serves a body-only
  fragment=scheduled-task-output response in addition to the existing raw text
  response.
- The scheduled-task output partial owns only run metadata, bounded scrolling
  presentation and the existing raw-output fallback link.
- The Automation page owns one shared lazy dialog shell and contextual history
  URLs for all recent runs; it does not duplicate task execution logic.
- Run, delete, retry and incident workflows remain explicit forms/jobs rather
  than being folded into an inspector.

This keeps inspection separate from mutation, reuses the existing authorization
and encrypted output model, and preserves the no-JavaScript endpoint.

### Preserved behavior and safety

- Raw output requests retain their plain-text content type, no-store/private
  headers and existing response body.
- Fragment requests authorize before rendering; foreign actors receive 403 and
  the encrypted output is not rendered by the Automation page itself.
- Output remains escaped in the HTML fragment, encrypted at rest and bounded by
  the existing scheduled-task worker retention limit.
- Opening the dialog does not queue, cancel, delete or retry a run.
- JavaScript-disabled users retain the original raw-output link.
- The dialog uses a shared content target, same-origin lazy loading, URL
  history and focus restoration.

### Verification

- Automation regression: 38 tests / 210 assertions passed.
- PHP syntax checks: passed for the controller, feature test and browser
  fixture.
- Pint on changed PHP files: passed.
- Focused browser journey: 1 test passed in 1.3 minutes using PHP 8.5.10;
  verified lazy output loading, contextual URL stability, direct deep-link
  opening, Escape and focus restoration.
- git diff --check: passed.

### Commit and push

Commit and push: `4a80c23 Open scheduled task output in a dialog`.

### Exact next task

Inspect notification-destination links for one bounded read-only contextual
inspector. Keep report mutations, incident changes, destination tests and
notification delivery as explicit workflows.

## Slice 8 — recipe report-status inspector

Status: complete; committed and pushed as 027f931.

### Responsibility problem

My Community Reports linked directly to a full report-status page. That made a
read-only check of a report interrupt the filtered report history, while the
full page also contains withdrawal and notification-review workflows that must
remain explicit mutations.

### Boundaries and benefit

- RecipeReportsController::status() remains the authorized report-status
  boundary and serves a `fragment=report-status` body-only response after the
  existing policy check and scoped eager load.
- The report-status partial owns only private report details, resolution state,
  timestamps and safe navigation links; it does not include withdrawal or
  notification mutations.
- My Community Reports owns one shared lazy dialog, per-report history keys and
  the full-page fallback href. The filtered list remains the source of valid
  deep-linked dialog records.
- The existing full report-status page and report mutation endpoints are
  unchanged for direct navigation and no-JavaScript use.

This keeps inspection separate from mutation, preserves deliberate 404
concealment for foreign reports and avoids adding a second report query or
generic modal abstraction.

### Preserved behavior and safety

- Report ownership policy runs before both full-page and fragment rendering;
  foreign actors receive the existing 404 response and private details are not
  disclosed.
- Report details and contributor resolution notes remain absent from the
  history list and are shown only after the authorized status lookup.
- Withdrawal, update-review notification handling and full-page status remain
  available through their existing routes and forms.
- Opening the dialog preserves the filtered history URL path, supports a
  bookmarkable `dialog=report-status-{id}` state, and keeps the direct status
  link as the JavaScript-disabled fallback.

### Verification

- Recipe report-history regression: **9 tests / 136 assertions passed**.
- PHP syntax checks: passed for the controller, feature test and browser
  fixture; Node syntax check passed for the browser spec.
- Pint on changed PHP files: passed.
- Focused browser journey: **1 test passed in 44.2 seconds** using PHP 8.5.10;
  verified mobile lazy loading, private details, URL stability, deep-link
  opening, Escape and focus restoration.
- `git diff --check`: passed.

### Commit and push

Commit and push: `027f931 Open recipe report status in a dialog`.

### Exact next task

Audit notification destination/status links and the remaining route inventory;
keep delivery tests, incident changes and other state-changing workflows as
explicit pages or forms.

## Slice 9 — provider filter and edit modal reliability

Status: complete; committed and pushed as c1d2c22.

### Responsibility problem

The provider filter is rendered as the existing mobile bottom-sheet filter
dialog, but mobile document scrolling depended on a JavaScript-only state marker
while filter dialogs were excluded from the base CSS scroll lock. This made the
background susceptible to scrolling during filter use. The provider edit flow
also needed a browser regression proving that its lazy fragment lifecycle does
not navigate or refresh the background document.

### Boundaries and benefit

- The existing `x-ui.filter-panel` and modal lifecycle remain the shared
  implementation; the change adds a mobile-only CSS lock for open filter
  dialogs rather than introducing a provider-specific filter component.
- Desktop filters remain inline and are not affected by the mobile selector.
- The existing lazy provider edit dialog remains responsible for form loading
  and cancellation; browser coverage now keeps its no-document-navigation
  guarantee alongside the filter coverage.

This fixes the concrete interaction issue at the shared presentation boundary,
keeps no-JavaScript filter fallback behavior unchanged and avoids duplicating
provider filtering or edit workflows.

### Preserved behavior and safety

- Provider filter validation, query parameters, pagination, inventory results
  and export URLs are unchanged.
- Filter forms still work without JavaScript and close/focus behavior remains
  native on supported mobile browsers.
- Provider edit remains a same-page lazy dialog with its direct edit route as
  fallback; no persistence or authorization behavior changes.
- The background document is inert and its scroll is locked only while the
  mobile filter dialog is open; the dialog body remains the scrollable region.

### Verification

- Provider inventory/filter regression: **5 tests / 40 assertions passed**.
- Focused browser journeys: **2 tests passed in 1.0 minute** using PHP 8.5.10;
  verified provider edit cancellation/history and mobile filter opening,
  background scroll lock, no document navigation and focus restoration.
- Asset build: passed.
- Node syntax check and `git diff --check`: passed.

### Commit and push

Commit and push: `c1d2c22 Lock background while provider filters are open`.

### Exact next task

Audit notification destination/status links and the remaining route inventory;
choose the next bounded contextual inspector while keeping delivery, incident
and other state-changing workflows explicit.

## Slice 10 — repository deployment-impact inspector

Status: complete; committed and pushed as ff8945b.

### Responsibility problem

The repository inventory linked to a full-page deployment-impact preview for a
read-only changed-path analysis. That interrupted the inventory context on
mobile even though the preview has no persistence, queue, provider call or
deployment side effect.

### Boundaries and benefit

- `RepositoriesController::impactPreview()` remains the policy-authorized
  request boundary and now returns either the existing full-page shell or a
  body-only `fragment=repository-impact-preview` response.
- The extracted impact-preview partial owns the existing path form, bounded
  target results and safe repository links for both surfaces.
- The repository inventory owns the lazy dialog trigger, contextual history
  URL and shared content target; it does not evaluate path impact itself.
- The full-page route remains the no-JavaScript/deep-link fallback, while
  deployment, webhook and repository mutation workflows remain explicit.

This reuses the existing request normalization, authorization and pure impact
query without introducing a second evaluator or a generic repository layer.

### Preserved behavior and safety

- Changed-path validation, conservative unavailable-path behavior, target
  ordering, counts, path limits and organization scoping remain unchanged.
- The preview remains read-only: it does not create builds, dispatch jobs,
  contact providers or modify repository settings.
- Foreign users are still denied before malformed preview validation, and
  invalid paths produce no preview or side effects.
- Full-page navigation and repository links from results remain available;
  valid modal submissions refresh only the dialog and retain the inventory URL.

### Verification

- Repository impact regression: **5 tests / 35 assertions passed**.
- PHP syntax checks and Pint on changed PHP files: passed.
- Focused browser journey: **1 test passed in 1.5 minutes** using PHP 8.5.10;
  verified initial lazy load, result refresh, no document navigation, URL
  stability, Escape/focus restoration and direct deep-link opening.
- Browser fixture export, Node syntax check and `git diff --check`: passed.

### Commit and push

Commit and push: `ff8945b Open repository impact preview in a dialog`.

### Exact next task

Audit notification destinations and the remaining read-only inventory links;
prioritize a contextual inspector only where it preserves ownership, read
state and no-JavaScript fallback semantics.

## Slice 11 — build comparison inspector

Status: complete; committed and pushed as 4a0d86e and f4269ea.

### Responsibility problem

The build detail page sent “Compare with previous” to a separate comparison
page, even though comparison is a bounded read-only view. That interrupted the
deployment timeline and evidence context, while the same build page contains
approval, rollback, cancellation, note and log workflows that must remain
explicit.

### Boundaries and benefit

- `BuildsController::compare()` remains the single policy-authorized comparison
  boundary and now serves either the existing full-page shell or a body-only
  `fragment=build-comparison` response.
- The extracted comparison partial owns the existing escaped metadata,
  duration calculation presentation and safe build links for both surfaces.
- The Livewire deployment-status view owns the trigger and a `wire:ignore`
  dialog shell so polling cannot replace the read-only modal content.
- Build operations remain on the deployment page; the direct comparison route
  remains the no-JavaScript and deep-link fallback.

This preserves the existing policy checks and same-repository/distinct-build
guard while separating read-only inspection from deployment operations.

### Preserved behavior and safety

- Comparison authorization runs before fragment rendering; the existing
  forbidden/not-found behavior for foreign, cross-repository and identical
  builds is unchanged.
- Escaping of commit messages, operator notes and failure messages remains in
  the shared presentation; no source-code or provider request is added.
- Duration comparisons continue to distinguish faster, slower, equal and
  unavailable values honestly.
- Livewire polling and the existing operator-note/editor dialogs remain
  compatible, while comparison open/close changes only browser history and
  dialog presentation.

### Verification

- Deployment comparison regression: **4 tests / 40 assertions passed**.
- PHP syntax checks and Pint on changed PHP files: passed.
- Focused browser journey: **1 test passed in 1.3 minutes** using PHP 8.5.10;
  verified canonical build URL stability, lazy authorized content, deep-link
  opening, Escape and focus restoration.
- Browser fixture export, Node syntax check and `git diff --check`: passed.
- An initial focused run caught an undefined controller request parameter;
  adding the explicit `Request` dependency fixed it before commit and the
  complete focused regression then passed.

### Commit and push

Commits and pushes: `4a0d86e Open build comparison in a dialog` and
`f4269ea Complete build comparison dialog trigger`.

### Exact next task

Audit notification destinations, observability evidence links and remaining
read-only product pages; keep notification read-state changes and incident
response actions explicit.

## Slice 15 — account audit inspector

Status: complete; committed and pushed as 10510fc.

### Responsibility problem

The account security section’s “View full account audit” link left account
settings for the full activity page, even though the first useful view is a
small, read-only owner-scoped security summary. That navigation was especially
costly on mobile and duplicated the context switch already solved for sign-in
history.

### Boundaries and benefit

- `ActivityController` remains the authorized activity read boundary and now
  serves the `fragment=account-audit` representation as well as the existing
  full page.
- The account view owns the trigger and dialog shell; the audit partial owns
  the compact summary, feed and links back to full filtering/export.
- The existing activity query, metrics, owner scoping, pagination and audit
  entitlement are reused. No generic audit repository or second query path was
  introduced.
- Notification “View and mark read” links remain excluded because they mutate
  read state and intentionally redirect to a different workflow.

### Preserved behavior and safety

- Account activity remains owner-scoped and excludes credential, provider
  identity, session and network details.
- The direct full activity URL remains the no-JavaScript fallback, while the
  account URL receives bookmarkable dialog state.
- Pagination and full-audit/export links retain their existing filters and
  entitlement behavior.
- Opening the inspector is a GET-only read and creates no activity, jobs or
  other side effects.

### Verification

- Activity, insights and account-security regression: **20 tests / 160
  assertions passed**.
- PHP syntax checks, Pint and Node syntax check: passed.
- Focused browser journey: **1 test passed in 1.5 minutes** using PHP 8.5.10;
  verified mobile opening, lazy fragment loading, URL stability, deep-link
  opening, Escape and focus restoration.
- Browser fixture export passed as part of the focused journey.
- `git diff --check`: passed before commit.

### Commit and push

Commit and push: `10510fc Open account audit in a dialog`.

### Exact next task

Audit notification destinations and remaining read-only product links. Keep
notification read-state transitions, runtime log access and incident response
actions explicit unless a separate bounded read fragment can preserve their
security and fallback semantics.

## Slice 16 — dashboard workspace-activity inspector

Status: complete; committed and pushed as 1055beb.

### Responsibility problem

The dashboard’s Recent activity section sent “View all” to the full Activity
page, interrupting the workspace context for a read-only question. The full
page remains the right place for search, category/date filters and export; the
dashboard needed a compact, lazy activity inspector instead.

### Boundaries and benefit

- `ActivityController` remains the owner-scoped activity read boundary and now
  serves `fragment=workspace-activity` alongside the existing full page and
  account-audit fragment.
- The dashboard owns the contextual trigger and modal shell; the new activity
  partial owns compact metrics, feed, pagination and links to the full page.
- Existing `ActivityQuery`, metrics, entitlement checks and activity feed
  presentation are reused. No dashboard-specific query or generic repository
  was introduced.
- Filtering, CSV export and notification state changes remain explicit on
  their existing pages/workflows.

### Preserved behavior and safety

- Activity remains scoped to the authenticated owner and keeps its existing
  event ordering, escaping, pagination and secret-safe presentation.
- The direct `/activity` link remains the no-JavaScript fallback, while the
  canonical dashboard URL receives bookmarkable dialog state.
- Opening the inspector is a GET-only read: it creates no events, jobs or
  mutations.
- Full activity filtering and export remain available from the modal without
  pretending the compact view is a complete replacement.

### Verification

- Activity, insights and account-security regression: **21 tests / 167
  assertions passed**.
- Dashboard dialog and active-deployment regression: **3 tests / 30 assertions
  passed**.
- PHP syntax checks, Pint, Node syntax check and `git diff --check`: passed.
- Focused browser journey: **1 test passed in 58.1 seconds** using PHP 8.5.10;
  verified the dashboard trigger, lazy fragment loading, canonical `/home`
  path stability, deep-link opening, Escape and focus restoration.
- Browser fixture export passed as part of the focused journey.

### Commit and push

Commit and push: `1055beb Open dashboard activity in a dialog`.

### Exact next task

Audit dashboard operational links: active deployments, system health,
provisioning, recent inventory and feedback. Modalize only bounded read-only
views; leave deployment control, provisioning and moderation workflows as
explicit pages/actions.

## Slice 13 — account sign-in-history inspector

Status: complete; committed and pushed as 3ce2366.

### Responsibility problem

The account page’s recent sign-in section sent “View full history” to a
separate page, interrupting security review on mobile. The full history already
had a bounded owner-scoped query, derived client metadata, filters, metrics and
export. The missing boundary was a reusable read-only fragment, not a second
security-history implementation.

### Boundaries and benefit

- `SignInHistoryController::index()` remains the authenticated account-scoped
  read boundary and now serves either the existing full-page shell or a
  `fragment=sign-in-history` body.
- The extracted sign-in content partial owns filters, metrics, derived device
  labels, pagination and the export link for both surfaces.
- The account page owns the mounted dialog shell and contextual trigger.
- Sign-in history deletion remains on the account page as an explicit,
  password-protected destructive operation; no mutation is available inside
  the inspector.

This applies single responsibility at the page/fragment presentation boundary,
reuses the existing query and privacy transformation, and avoids a generic
account repository or a security-specific modal framework.

### Preserved behavior and safety

- Owner scoping, filter normalization, pagination query values, metrics,
  derived device/IP display and CSV export remain unchanged.
- Raw user agents and other sensitive account metadata remain excluded from
  both full and fragment responses.
- The full sign-in-history route and no-JavaScript fallback remain available.
- Fragment requests are GET-only and do not clear history, change sessions or
  revoke credentials.
- Filter submissions stay inside the dialog and preserve the account path and
  modal history state.

### Verification

- Account/sign-in regression: **18 tests / 142 assertions passed**.
- Fixture export: **1 test / 210 assertions passed**.
- PHP syntax checks, Pint and Node syntax check: passed.
- Focused browser journey: **1 test passed in 46.8 seconds** using PHP
  8.5.10; verified the mobile collapsible section, lazy fragment loading,
  read-only content, contextual URL stability, deep-link opening, Escape and
  focus restoration.
- `git diff --check`: passed before commit.

### Commit and push

Commit and push: `3ce2366 Open account sign-in history in a dialog`.

### Exact next task

Audit notification destinations and observability evidence links. Preserve
notification read-state transitions and incident response actions as explicit
workflows; choose another read-only inspector only where it has a bounded
authorized fragment and a useful full-page fallback.

## Slice 14 — observability health-history inspector

Status: complete; committed and pushed as 4bb3538.

### Responsibility problem

The environment evidence page linked “View health history” to the website
history page, interrupting an investigation that was already scoped to an
environment. Runtime log links return bounded JSON and incident links lead to
response workflows, so they should not be copied into a generic read-only
modal.

### Boundaries and benefit

- `ObservabilityController::environmentContext()` remains the authorized,
  bounded environment-evidence read boundary.
- The environment-context view owns the contextual trigger and modal shell.
- `WebsitesController::healthChecks()` remains the single website-scoped
  health-history query/fragment boundary; no observability-specific health
  query was added.
- Runtime-log retrieval, incident response and evidence-page filtering remain
  explicit routes and workflows.

This preserves single responsibility and dependency direction by composing two
existing read boundaries only at the presentation edge, without duplicating
queries or weakening the website policy check.

### Preserved behavior and safety

- Environment filters, shareable links, tenant scoping, bounded evidence and
  sensitive-body exclusion remain unchanged.
- The direct website health-history URL remains the no-JavaScript fallback.
- Opening the dialog performs an authorized GET fragment request only; it does
  not refresh runtime logs, change monitoring, create incidents or mutate an
  investigation view.
- The canonical `/observability/environments/{id}/context` path and its
  existing filters are retained in the modal history URL.
- Log bodies and incident summaries remain outside the evidence page/modal.

### Verification

- Observability context and website health regression: **19 tests / 185
  assertions passed**.
- PHP syntax checks, Pint and Node syntax check: passed.
- Focused browser journey: **1 test passed in 59.2 seconds** using PHP
  8.5.10; verified the canonical context path, lazy health fragment loading,
  URL stability, deep-link opening, Escape and focus restoration.
- Browser fixture export passed as part of the focused journey.
- `git diff --check`: passed before commit.

### Commit and push

Commit and push: `4bb3538 Open observability health history in a dialog`.

### Exact next task

Audit notification destinations and the remaining read-only product links.
Keep notification read-state transitions, runtime log access and incident
response actions explicit unless a separate bounded read fragment can preserve
their security and fallback semantics.

## Slice 12 — build health-history inspector

Status: complete; committed and pushed as 5ec64fd.

### Responsibility problem

The deployment page summarized application health but sent “View health
history” to the website page, interrupting a deployment investigation on
mobile. The existing website health-history endpoint already owns the scoped
query, filters, authorization and export behavior, so the missing boundary was
only a contextual read-only presentation.

### Boundaries and benefit

- `WebsitesController::healthChecks()` remains the single authorized history
  read boundary and supplies the existing
  `fragment=website-health-checks` body.
- The Livewire deployment-status view owns the build-local trigger and
  `wire:ignore` dialog shell; it does not duplicate health queries or monitor
  execution.
- The direct website history link remains the no-JavaScript fallback, while
  the build URL receives a bookmarkable dialog state.
- “Run health check now” remains an explicit POST action with its existing
  queue and authorization semantics.

This keeps inspection separate from a state-changing health check, reuses the
existing website policy boundary and avoids creating a second health-history
implementation.

### Preserved behavior and safety

- Website-scoped health authorization, filters, pagination, retained-result
  limits, escaping and export behavior are unchanged.
- Opening the dialog performs only an authorized GET fragment request; it does
  not queue a check, change monitoring settings or mutate the deployment.
- Filter controls remain inside the modal and the deployment page path and
  scroll context remain stable.
- The website detail route remains available when JavaScript is disabled.

### Verification

- Website health-history and deployment-comparison regression: **15 tests /
  153 assertions passed**.
- PHP syntax checks, Pint and Node syntax check: passed.
- Focused browser journey: **1 test passed in 45.9 seconds** using PHP
  8.5.10; verified lazy health-history loading, contextual URL stability,
  direct dialog opening, Escape and focus restoration.
- `git diff --check`: passed before commit.

### Commit and push

Commit and push: `5ec64fd Open build health history in a dialog`.

### Exact next task

Audit notification destinations, observability evidence links and remaining
read-only product pages; keep notification read-state changes and incident
response actions explicit.
