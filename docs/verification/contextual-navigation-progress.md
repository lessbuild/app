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

Status: complete; committed and pushed as aaa8861.

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

### Commit and push

Commit and push: aaa8861 Move preview settings into a contextual dialog.

### Exact next task

Continue the detail-page audit with the existing long history links and
bounded settings actions. Prefer context-preserving summary/timeline
improvements over embedding paginated reports in large modals.

## Slice 6 — environment settings and deployment controls in context

Status: complete; committed and pushed as 4e26121.

### Responsibility problem

Each application environment rendered two large edit forms inline: runtime and
placement settings, plus deployment locks, maintenance windows and rollout
controls. These forms dominated the mobile page and made the environment
inventory harder to scan, even though their existing requests and actions
already formed clear operation boundaries.

### Boundary

- The application page now presents compact summaries and per-environment
  dialog triggers.
- environment-settings-dialog.blade.php and
  deployment-controls-dialog.blade.php own only the reusable form surfaces.
- EnvironmentRequest, DeploymentControlsRequest, EnvironmentController and the
  existing update actions remain the validation, authorization and persistence
  boundaries.

### Preserved behavior and safety

- Existing field names, hidden environment/panel identity, defaults, feature
  gates, validation keys, old-input values, policy checks and flash messages
  remain unchanged.
- Runtime entitlement checks, production uniqueness protection, scoped server
  and website selection, deployment locks, maintenance-window validation,
  strategy settings and rollback semantics still execute in the original
  requests/actions.
- Validation failures reopen only the submitted dialog and preserve the
  existing panel context; denied or malformed requests still perform no write.
- The current page remains the canonical no-JavaScript fallback, with a
  dialog query identifying the selected operation.

### Verification

- Project/environment dialog coverage: 7 tests / 72 assertions passed.
- Deployment controls, strategy, lifecycle and runtime regressions: 18 tests /
  109 assertions passed.
- Isolated Blade fixture export: 1 test / 148 assertions passed.
- Application-detail mobile composer journey: 1 passed, including settings,
  deployment-control, variable, process, resource and preview dialogs.
- Blade cache, Pint, JavaScript syntax check and git diff --check: passed.

### Commit and push

Commit and push: 4e26121 Move environment controls into contextual dialogs.

### Exact next task

Review remaining detail-page history links and other bounded settings
surfaces. Keep paginated history, exports, imports, restores, destructive
operations and protocol callbacks as explicit pages unless a smaller read-only
summary genuinely improves the flow.

## Slice 7 — repository webhook settings in context

Status: complete; committed and pushed as ec85060.

### Responsibility problem

Repository webhook enablement, rotation and disablement were rendered as
credential-sensitive controls inline on an already long repository detail page.
GitLab signing-token input and webhook lifecycle confirmations belong to one
focused settings operation and should not compete with deployment history.

### Boundary

- webhook-settings-dialog.blade.php owns the repository-context form surface
  and confirmation controls.
- RepositoryWebhookSettingsController, RepositoryWebhookSettingsRequest and
  the existing enable/disable actions remain the authorization, validation,
  secret-generation and persistence boundaries.
- The controller preserves the dialog query only for modal-originated enable
  or rotate submissions; canonical full-page redirects remain unchanged.

### Preserved behavior and safety

- Workspace policy checks still run before GitLab signing-token validation.
- Generated webhook secrets remain one-time session flashes and are never
  stored or rendered as repository attributes.
- Rotation and disablement confirmations, provider-specific token handling,
  payload URL, branch filtering, replay protection and delivery history remain
  unchanged.
- A modal-originated successful enable/rotate returns to the repository with
  the dialog open so the one-time secret remains immediately copyable.
- Long delivery history and CSV export remain explicit, filterable page
  workflows.

### Verification

- Repository webhook and delivery-history coverage: 18 tests / 199 assertions
  passed.
- Isolated Blade fixture export: 1 test / 150 assertions passed.
- Provider/repository/recipe dialog browser journey: 1 passed, including
  webhook URL state, focus restoration and direct dialog rendering.
- Blade cache, Pint, JavaScript syntax check and git diff --check: passed.

### Commit and push

Commit and push: ec85060 Move repository webhook settings into a dialog.

### Exact next task

Finish the detail-page audit. Keep full history/report/export flows as pages
and document any remaining inline action with a concrete safety or
execution-order reason.

## Slice 8 — website log retention in context

Status: complete; committed and pushed as `5b3ad50`.

### Responsibility problem

The website detail page placed the runtime log-retention editor beneath the
log snapshots as an inline form. On mobile this made an already long
operational section taller and separated a bounded settings change from the
website controls used elsewhere in the application.

### Boundary

- `log-retention-dialog.blade.php` owns the bounded retention form surface.
- The website page owns the runtime-log summary, update-policy gate, trigger and
  URL-backed dialog state.
- The existing runtime-log retention request, controller and persistence path
  remain the validation, authorization and write boundaries.
- Runtime log refresh, health history and provisioning timeline remain inline
  operational views; their pagination and polling behavior is unchanged.

### Preserved behavior and safety

- The existing `log_retention_lines` values, validation key, PATCH route and
  policy behavior remain unchanged.
- Modal-originated invalid input returns to the website with the dialog open;
  the canonical endpoint still keeps its existing redirect behavior.
- Retention controls are hidden for actors who cannot update the website, while
  the endpoint continues to enforce authorization independently.
- No log output, credentials or old input is added to the dialog beyond the
  existing safe retention field.

### Verification

- Observability and website health-history coverage: 32 tests / 311 assertions
  passed.
- Isolated website fixture export is exercised by the browser harness.
- Website retention dialog browser journey: 1 passed, including expansion of
  runtime logs, URL state, focus restoration, Escape and direct dialog loading.
- Provider/repository/recipe dialog regression: 1 passed.
- JavaScript syntax check, Blade cache, Pint and `git diff --check` passed.

### Exact next task

Complete the detail-page audit and record the intentional full-page and direct
action exceptions.

## Detail-page audit — 2026-09-20

Status: complete; committed and pushed as `aa63ee3`.

### Reviewed boundaries

- Application detail: configuration-as-code, preview settings, environment
  settings, deployment controls, variables, processes, resource attachment,
  environment creation and contextual repository/website creation use
  URL-backed dialogs. Existing application deletion and deployment actions stay
  explicit.
- Provider, repository, server and website details: bounded edit/settings
  controls use reusable dialogs. Provider connection testing, server
  provisioning/retry, website health checks, runtime refresh, provisioning
  retry and cleanup remain direct operational actions.
- Repository webhook lifecycle uses its contextual dialog, while webhook
  delivery history and CSV export remain a filterable history page.
- Configuration review remains available as a full-page no-JavaScript fallback;
  the application-context dialog is the primary in-context path.

### Intentional exceptions

- Paginated histories, reports, filters and CSV exports remain pages so URL
  state, pagination, downloads and query bounds are not hidden in a modal.
- Imports, restores, deployment rollback/cancellation and other destructive or
  irreversible workflows remain explicit or confirmation-bound actions so
  consequences and execution ordering stay visible.
- Health checks, log refreshes, deploy/retry commands and cleanup retries remain
  direct actions because they are immediate operations rather than bounded
  editors.
- Callback, webhook-signature, OAuth/SSO and other protocol workflows retain
  their existing page or integration boundaries; they are not ordinary UI
  settings.
- Full-page edit/create routes remain as accessible no-JavaScript fallbacks for
  the server-rendered dialogs. They are not duplicate application workflows.

### Verification

- Detail-page inventory reviewed for inline forms, edit/create triggers,
  histories, exports, imports, restores and destructive actions.
- No new dependency, route, persisted value, validation key, queued-job
  payload or provider behavior was introduced by the audit.
- The website, provider/repository/recipe and application contextual-dialog
  browser journeys and focused PHP regressions pass.

### Exact next task

Keep the remaining live, cloud/provider and real-device acceptance work
separate from this locally verified UI slice.
