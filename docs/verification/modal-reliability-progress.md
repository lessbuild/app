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

Commit and push Slice 1, then implement the dashboard/navigation workspace
search dialog using the existing `SearchController` query semantics.
