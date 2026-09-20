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

Status: complete; implementation verified locally and ready to commit/push.

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

Status: complete; implementation verified locally and ready to commit/push.

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

Status: complete; implementation verified locally and ready to commit/push.

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

Commit and push: pending in this working slice.

### Exact next task

After this slice is committed and pushed, inspect bounded server command/task
output and report-status links for the next read-only contextual inspector.
Keep remote execution, retry, provisioning and report mutations explicit.

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
