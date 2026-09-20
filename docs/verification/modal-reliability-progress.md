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
