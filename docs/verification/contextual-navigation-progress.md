# Contextual navigation progress

This ledger tracks the contextual modal and navigation improvements requested for
the application. Each slice is implemented on `main`, verified in the isolated
checkout, committed, pushed and then considered closed before the next slice.

## Slice 1 — website provisioning timeline

Status: complete locally; commit and push pending.

### Responsibility problem

The website detail page placed operational progress below health checks and
runtime logs, and rendered the legacy `Setup Information` list. That made an
active or failed website harder to understand on mobile and duplicated the
timeline treatment used by deployment pages.

### Boundary

- `WebsiteProvisioningTimeline` reads the existing website plan and persisted
  callback state into `DeploymentTimelineEntry` values.
- `WebsiteSetup` remains the Livewire authorization and refresh boundary, but
  now renders the website-specific timeline component.
- The website page presents the timeline before health history and keeps the
  existing provisioning log, retry action, cleanup warning and polling.

No callback, job, transaction, authorization or provisioning state transition
was changed. Because the callbacks persist a stage count rather than a failure
phase, the timeline marks only the next uncompleted stage as failed and does
not invent per-stage timestamps.

### UI corrections

- Removed `Setup Information` from the website page.
- Renamed the operations section to `Provisioning timeline`.
- Moved the operations section above health history.
- Corrected the empty repository message to refer to a website.
- Added pagination controls for the existing paginated repository relation.
- Renamed `Add Repo` to `Add repository`.

### Verification

- Website provisioning timeline unit tests: 3 tests / 5 assertions passed.
- Website provisioning log regression: passed with timeline and legacy-label
  assertions.
- Existing website/server provisioning, retry and health-history regression:
  30 tests / 394 assertions passed before the additional timeline assertions;
  the focused post-change timeline/log run passed 10 tests / 64 assertions.
- Isolated Blade fixture export: 1 test / 140 assertions passed.
- Mobile render at 390px: timeline appears at approximately 573px, before
  health history; `Setup Information` is absent.
- Pint and `git diff --check`: passed.

### Commit and push

Commit and push: `0af32ac Replace website setup with provisioning timeline`.

## Slice 2 — shared modal-loader reliability

Status: complete locally; commit and push pending.

### Responsibility problem

The shared modal loader used one generic recipe-specific error, allowed a slow
response to replace newer content, did not distinguish an expired session,
and did not initialize triggers inserted into lazy-loaded content. Modified
links were also intercepted even when the browser should open a new tab or
window.

### Boundary

- The shared layout script now owns request cancellation, response identity,
  error presentation, retry and modal-trigger initialization.
- Laravel endpoints, policies, requests and actions remain unchanged.
- Full-page fallback links continue to use the originating anchor URL, so a
  server-rendered modal URL and the no-JavaScript path remain available.

### Preserved behavior

- Same-origin content is still fetched only when a dialog opens.
- Existing URL-backed modal history, Escape handling, focus restoration and
  background scroll locking remain in place.
- A canceled or stale response cannot replace content from a newer request.
- 401/419 responses and redirects to login receive a sign-in message rather
  than rendering the login page inside the dialog.
- Retry does not duplicate form submissions or alter application state.

### Verification

- New lazy-loader failure/retry browser flow: passed.
- Existing creation, dashboard, application, provider, repository, recipe,
  gallery, mobile form and modal-scroll flows: 7 passed.
- PHP test behavior was unchanged; no dependency or lockfile changed.

### Exact next task

Commit and push this slice, then convert application configuration authoring,
review and receipt views into an application-context modal.
