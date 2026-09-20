# Contextual navigation progress

This ledger tracks the contextual modal and navigation improvements requested for
the application. Each slice is implemented on `main`, verified in the isolated
checkout, committed, pushed and then considered closed before the next slice.

## Slice 1 — website provisioning timeline

Status: complete; committed and pushed as `0af32ac`.

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

Status: complete; committed and pushed as `14dcfd4`.

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

Convert application configuration authoring, review and receipt views into an
application-context modal.

## Slice 3 — application configuration in context

Status: complete; committed and pushed as `8986dba`.

### Responsibility problem

The application detail page sent users to a separate configuration page for
authoring, reviewing and recovering configuration. That broke the application
context and made the most important setup workflow unnecessarily long on
mobile. The existing configuration controller also duplicated review-state
assembly between the canonical page and the new contextual surface.

### Boundary

- `ApplicationConfigurationController::dialog()` is an authorized,
  body-only fragment boundary. It reuses `authoringPageData()` and the shared
  `reviewState()` read path instead of duplicating configuration services.
- `configuration-dialog.blade.php` presents authoring, review and receipt
  states in the reusable modal shell hosted by the application page.
- The existing Form Request, review service, planner, reconciler, cancellation
  and retry services remain the business and safety boundaries.
- Modal submissions carry only the explicit `dialog` context and redirect back
  to the application with the review identity; ordinary full-page URLs retain
  their existing redirects.

### Preserved behavior and safety

- Workspace management authorization remains enforced before fragment access or
  validation; non-managers receive the existing denial.
- YAML and JSON validation still never flashes submitted commands, bindings or
  secrets. Modal validation failures return to the application context and the
  fragment renders the error without old input.
- Review identity, project ownership, receipt visibility, secret-safe plans,
  apply/retry/cancel guards, no-op behavior, leases and transaction semantics
  remain in the existing services.
- Canonical review pages keep their existing 422 stale-review response. The
  contextual fragment renders the same safe stale-review recovery message as a
  normal in-dialog outcome.
- The body-only endpoint does not expose a full HTML document or secret values.

### Verification

- Configuration/API/OpenAPI/planner/document/resource/concurrency and related
  environment regressions: 209 tests / 1,974 assertions passed before the
  final modal-only request assertions.
- Configuration web coverage after the final changes: 5 tests / 71 assertions
  passed, including authorization-before-validation, modal validation
  redirect, no old input, review submission, stale-review recovery and
  secret-safe rendering.
- Project creation, environment runtime and shared tenancy coverage passed in
  the affected regression run.
- Asset fixture export: 1 test / 144 assertions passed.
- Browser coverage: configuration application-context modal, lazy-loader
  failure/retry, and modal scroll-lock flows passed (3 tests).
- Blade compilation, Pint and `git diff --check` passed.

### Commit and push

Commit and push: `8986dba Keep configuration workflow in application context`.

## Slice 4 — environment resources in context

Status: complete; committed and pushed as `c7592ae`.

### Responsibility problem

The application detail page had contextual dialogs for environments, encrypted
variables and worker processes, but attaching an environment resource still
used an inline write form inside the resources section. On a narrow screen the
form expanded the page and its validation state was less consistent with the
other application composers.

### Boundary

- `resource-create-dialog.blade.php` owns only the reusable HTTP form surface
  for attaching one environment resource.
- The application page owns the trigger, entitlement gate, URL-backed open
  state and per-environment dialog identity.
- `StoreEnvironmentResourceRequest`, `SaveEnvironmentResourceAction` and
  `EnvironmentController::storeResource` remain the validation, authorization,
  encryption and persistence boundaries; no new service or abstraction was
  introduced.

### Preserved behavior and safety

- The existing field names, hidden environment/panel context, resource type
  values, managed checkbox and variables format remain unchanged.
- Existing resource entitlement and environment authorization checks still run
  before invalid input is processed.
- Encrypted variables, validation keys, old-input behavior, redirect flash
  messages and no-write-on-validation-failure behavior remain unchanged.
- The resources section reopens when its dialog is requested, and the modal
  has a full-page URL fallback for direct or no-JavaScript navigation.
- Resource sections remain collapsed by default; the browser journey expands
  the section before activating the accessible trigger.

### Verification

- Environment operations, runtime and project/environment regression coverage:
  18 tests / 144 assertions passed.
- Isolated Blade fixture export: 1 test / 145 assertions passed.
- Application-detail composer browser journey: 1 passed, including resource
  section expansion, URL state, focus restoration and Escape behavior.
- Blade cache, Pint and `git diff --check`: passed.

### Commit and push

Commit and push: `c7592ae Make environment resources contextual dialogs`.

### Exact next task

Inventory the remaining detail-page history and settings links. Keep long
reports, imports, restores, destructive operations and protocol callbacks as
explicit pages unless a bounded modal preserves their pagination,
authorization and execution ordering.

## Slice 5 — preview settings in context

Status: complete locally; commit and push pending.

### Responsibility problem

The application detail page summarized preview environments inside a collapsible
section but still rendered the multi-field enablement, hostname and lifetime
form inline. This added avoidable vertical scrolling and made preview settings
inconsistent with the other application-level composers.

### Boundary

- preview-settings-dialog.blade.php owns the bounded preview-settings form
  presentation.
- The application page owns the preview summary, entitlement gate, trigger and
  URL-backed dialog state.
- UpdateProjectPreviewsRequest and UpdateProjectPreviewsAction remain the
  authorization, normalization, entitlement and persistence boundaries.

### Preserved behavior and safety

- Preview enablement, hostname normalization, lifetime bounds, _project_form
  context, validation keys and redirect/flash behavior remain unchanged.
- Entitlement authorization still runs before validation and no preview write
  occurs when the plan check fails.
- Validation failures reopen the preview dialog and retain the existing preview
  panel context; successful updates still use the existing action and lifecycle.
- The no-JavaScript URL fallback remains the application page with
  dialog=preview-settings, while long preview history remains on the page.

### Verification

- Preview lifecycle and settings coverage: 23 tests / 259 assertions passed.
- Project/environment dialog regression: 7 tests / 64 assertions passed.
- Isolated Blade fixture export: 1 test / 146 assertions passed.
- Application-detail mobile composer journey: 1 passed, including preview
  section expansion, URL state, focus restoration and Escape behavior.
- Blade cache, Pint, JavaScript syntax check and git diff --check: passed.

### Exact next task

Commit and push this slice, then continue the detail-page audit with the
existing long history links and bounded settings actions. Prefer context
preserving summary/timeline improvements over embedding paginated reports in
large modals.
