# Modal modernization progress

## Slice 1 — shared modal foundation and domain workflows

Status: complete and deployed to the isolated development runtime.

### Responsibility problem

The domain index combined inventory, two secondary creation workflows and their full forms in one always-visible section. On small screens this made the primary inventory harder to scan and forced users to scroll past forms they were not using. The page also had no shared URL-backed interaction pattern for compact workflows.

### Boundary and design decision

- `x-dialogs.modal` owns the shared accessible dialog structure, title/description semantics, close control and responsive panel layout.
- The core layout owns only the generic browser behavior: native dialog opening, URL history, Escape/back handling, focus restoration and progressive no-JavaScript fallback.
- `domains/index.blade.php` remains responsible for domain-specific fields, existing Form Requests, policies, routes, flash messages and response behavior.
- Add-domain and temporary-domain are compact, interruptible workflows and are therefore modal candidates. Domain inventory remains the page content.

This applies single responsibility at the UI boundary without introducing a generic business abstraction. Existing actions and validation contracts remain unchanged.

### Preserved contracts and safety guarantees

- Existing routes, HTTP methods, validation keys, old input, flash messages and domain actions are unchanged.
- Existing authorization and organization scoping remain in the existing controller/request boundary.
- The add-domain form reopens after validation failure, including the existing secret-safe validation behavior.
- Query-backed opening supports refresh, direct links and no-JavaScript fallback.
- Native dialog support uses a normal anchor fallback when unavailable.
- Browser Back closes an open dialog; Escape closes it and restores focus to the trigger.
- The inventory table and existing domain lifecycle behavior remain outside the modal boundary.

### Verification

- `tests/Feature/DomainManagementTest.php`: 8 tests, 55 assertions passed.
- `tests/Feature/LocalUiAssetTest.php tests/Feature/DomainManagementTest.php`: 29 tests, 498 assertions passed.
- `npm run build`: passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 30 assertions passed.
- `npm run test:browser -- tests/Browser/asset-layout.spec.js`: 10 tests passed, including domain open/close, history, focus restoration and validation-reopen fixture coverage.
- `git diff --check`: passed.

### Commit and push

Commit and push: `5e74d12 Add accessible domain modal workflows`.

The isolated `buildpusher-dev-main.service` runtime was fast-forwarded to this commit, rebuilt, view-cached, restarted and verified active. `https://buildpusher.com/login` returned HTTP 200. This is the isolated development deployment, not live acceptance.

### Exact next task

After the commit is pushed and the isolated dev runtime is updated, inspect organization invitations and feedback submission. Convert only the compact workflows whose validation, authorization and failure behavior can be preserved without hiding a long or destructive operation.

The broader modal modernization plan remains incomplete. Live acceptance and paid-cloud verification remain separate from this local slice.

## Slice 2 — invitation and feedback composers

Status: complete and deployed to the isolated development runtime.

### Responsibility problem

The workspace page kept the invitation form as a full secondary disclosure alongside security and membership administration. The feedback page kept a multi-field composer permanently beside the feedback inventory. Both increased mobile scrolling and competed with the primary read/review tasks, even though each submission is a short, interruptible operation.

### Boundary and design decision

- Workspace invitation and private feedback submission use the shared modal foundation.
- Existing Form Requests, policies, actions, encryption, seat checks, notifications and response redirects remain unchanged.
- Security policy, notification preferences, member role/removal operations, SSO and workspace deletion remain page/disclosure workflows because they are longer, sensitive or destructive.
- Multiple triggers may target one dialog. The modal manager tracks the actual opener so focus restoration and history cleanup are deterministic.

This keeps presentation responsibility separate from application operations and avoids moving authorization or persistence into browser code.

### Preserved contracts and safety guarantees

- Invitation and feedback routes, methods, validation keys, normalization, authorization ordering, flash messages and action behavior are unchanged.
- Invitation role defaults preserve the existing first-role behavior; failed input is retained and the dialog reopens after validation failure.
- Feedback privacy, encrypted storage, workspace visibility and secret-sized/external-context validation remain unchanged.
- Filtered feedback context is retained when opening the composer.
- Direct dialog URLs and no-JavaScript anchor fallback remain available.
- Membership edits, owner protections, seat synchronization, review/delete permissions and named destructive flows remain outside the modal boundary.

### Verification

- `tests/Feature/OrganizationManagementTest.php`: 21 tests passed.
- `tests/Feature/ProductFeedbackTest.php`: 6 tests passed.
- `tests/Feature/LocalUiAssetTest.php`: passed in the 55-test/642-assertion focused run.
- `tests/Feature/DomainManagementTest.php`: 8 tests passed in the same focused run.
- Focused aggregate: 55 tests, 642 assertions passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 36 assertions passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:browser -- tests/Browser/asset-layout.spec.js --grep "compact invitation" --workers=1`: 1 test passed.
- `npm run build`: passed.
- Pint: passed.
- `git diff --check`: passed.

### Commit and push

Commit and push: `a243d72 Use dialogs for invitations and feedback`.

The isolated `buildpusher-dev-main.service` runtime was fast-forwarded to this commit, rebuilt, view-cached and restarted. The service is active and the runtime checkout is clean on `main`.

### Exact next task

After this slice is pushed and the isolated runtime is updated, inspect backup schedule and load-balancer node workflows. Convert only short create/add forms; preserve destination save-before-verify behavior, entitlements, organization-scoped lookups and remote-job semantics.

## Slice 3 — backup schedules and high-availability node forms

Status: complete and deployed to the isolated development runtime.

### Responsibility problem

The backup schedule form and high-availability route/node forms were embedded in long inventory pages. On mobile, the forms competed with recovery evidence, destinations, route status and node inventory. The load-balancer page also expanded the create form automatically when no route existed, which made the empty state unnecessarily tall.

### Boundary and design decision

- Backup schedule creation uses the shared modal because it is a short, existing Form Request-backed operation with no remote execution in the request.
- High-availability route creation and application-node addition use the shared modal because they are compact inputs whose existing actions and queued configuration jobs remain the business boundary.
- Backup destination creation, editing and verification remain page disclosures. Saving encrypted credentials and verifying them are intentionally separate operations and were not combined into a modal workflow.
- Existing node inventory remains a responsive disclosure so incomplete routes still expose their required next action without hiding status information.

This reduces page-level presentation responsibility while preserving the existing request, policy, action, transaction and job boundaries. No new business abstraction was introduced.

### Preserved contracts and safety guarantees

- Existing routes, methods, validation keys, old input, flash messages, entitlement checks and organization-scoped lookups are unchanged.
- Schedule defaults and selected values are retained after validation failure; the schedule dialog reopens with the existing errors.
- Route creation and node validation reopen only their relevant dialog, while incomplete-node disclosure behavior remains unchanged.
- Existing load-balancer self-routing and dedicated-server safeguards, queue dispatches, deletion behavior and status transitions remain outside the presentation change.
- Backup destination encryption, save-before-verify behavior, temporary-object verification, sanitized failures and no-server verification remain unchanged.
- Direct query URLs and no-JavaScript anchor fallbacks are available for each dialog.

### Verification

- Focused backup, destination, recovery and high-availability run: 24 tests, 180 assertions passed.
- `tests/Feature/ManagedBackupTest.php`: schedule dialog and existing backup/retry/restore coverage passed.
- `tests/Feature/LoadBalancerOperationsTest.php`: create, node, authorization, entitlement and safety coverage passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 39 assertions passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:browser -- tests/Browser/asset-layout.spec.js --grep "backup schedule workflow" --workers=1`: 1 test passed.
- `npm run build`: passed.
- Pint: passed.
- `git diff --check`: passed.
- Isolated runtime restarted successfully; `https://buildpusher.com/login` returned HTTP 200.

### Commit and push

Commit and push: `65d9dad Use dialogs for backup and load balancer forms`.

The isolated `buildpusher-dev-main.service` runtime was fast-forwarded to this commit, rebuilt, view-cached, route-cache-cleared, restarted and verified active on `main`.

### Exact next task

Inspect database-management and API-token/credential workflows. Convert only compact, reversible create or issue forms; keep clone/restore reviews, credential rotation with consequential effects, OAuth/SSO, two-factor and other multi-step or destructive workflows as full-page flows.

## Slice 4 — API tokens and database credentials

Status: complete and deployed to the isolated development runtime.

### Responsibility problem

The automation page kept API-token issuance inline with the token inventory and quick-start material. Database-resource cards kept credential issuance inline with credential history and destructive clone controls. Both forms are short, but their one-time secrets and validation state deserve a focused interaction rather than more permanent page height.

### Boundary and design decision

- API-token creation uses the shared modal; token inventory remains in its responsive disclosure.
- Database-credential issuance uses one resource-specific modal per supported database; existing credential inventory and clone safety controls remain in the resource disclosure.
- Existing Form Requests, policies, entitlement checks, token/database actions, encryption and one-time secret flash behavior are unchanged.
- Token rotation/revocation, database inspection, database clone confirmation, OAuth/SSO, two-factor and other consequential operations remain explicit page or action workflows.

The extraction is presentational only. HTTP controllers still receive the same validated input and actions still own persistence and queued side effects.

### Preserved contracts and safety guarantees

- Existing routes, methods, validation keys, default expiry values, selected abilities, flash messages and response behavior are unchanged.
- Invalid token and database-credential submissions reopen only their relevant modal and preserve safe old input; generated plaintext secrets are still shown once through the existing session flash.
- Database credential passwords and API token plaintext never enter persisted token/password columns or unrelated rendered markup.
- Resource ownership, current-workspace scoping, plan checks, unsupported-resource responses and no-write-on-denial behavior remain unchanged.
- Database clone target restrictions and exact confirmation remain outside the modal boundary.
- Direct query URLs and normal anchor fallbacks remain available without JavaScript.

### Verification

- `tests/Feature/AutomationTest.php` and `tests/Feature/DatabaseOperationsTest.php`: 42 tests, 222 assertions passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 42 assertions passed.
- `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:browser -- tests/Browser/asset-layout.spec.js --grep "credential workflows" --workers=1`: 1 test passed.
- `npm run build`: passed.
- Pint: passed.
- `git diff --check`: passed.
- Isolated runtime restarted successfully; after the startup window `https://buildpusher.com/login` returned HTTP 200.

### Commit and push

Commit and push: `47aa5a8 Use dialogs for credential issuance`.

The isolated `buildpusher-dev-main.service` runtime was fast-forwarded to this commit, rebuilt, view-cached, route-cache-cleared, restarted and verified active on `main`.

### Exact next task

Audit the remaining product pages for compact, reversible forms: deployment approvals/promotions, small domain or repository settings, and notification/report composers. Do not modalize full deployment timelines, restore/rollback confirmation, provider/server setup, YAML configuration, security settings or destructive operations.

## Slice 5 — gallery report and metric-alert composers

Status: complete and deployed to the isolated development runtime.

### Responsibility problem

The gallery detail page placed a private report composer beside a long root-level script and contributor feedback history. Observability placed metric-rule creation beside telemetry cards and alert-rule inventory. Both were short, bounded submissions whose permanent inline forms increased mobile scrolling and obscured the primary evidence.

### Boundary and design decision

- Private gallery report creation/update uses the shared modal. Report history, contributor resolution controls and irreversible withdrawal remain visible or explicit.
- Metric-alert rule creation uses the shared modal. Existing rule inventory, deletion and alert delivery behavior remain unchanged.
- Alert-destination endpoints, status-page publication, status updates, deployment approvals/promotions and repository/provider settings remain page workflows because they carry integration, public-communication, release or credential consequences.
- Existing policies, Form Requests, normalization, encryption and actions remain the business boundary.

This is a presentation-only extraction: controllers still coordinate the same routes, requests and actions, while the modal component owns focus and URL behavior.

### Preserved contracts and safety guarantees

- Existing route names, methods, validation keys, flash/status messages, report anonymity and encrypted report details are unchanged.
- Current gallery reports still update in place, reopen resolved reports, and retain the existing withdraw confirmation.
- Metric thresholds, cooldown options, server scoping, alert entitlement checks and no-write-on-denial behavior are unchanged.
- Failed forms reopen only their relevant dialog and preserve the existing validated/old-input behavior.
- Direct query URLs, normal anchor fallback and keyboard focus restoration remain available.

### Verification

- `tests/Feature/RecipeReportTest.php` and `tests/Feature/RecipeFeedbackInboxTest.php`: 38 tests, 395 assertions passed.
- `tests/Feature/ObservabilityTest.php`, `tests/Feature/OperationalIncidentTest.php`, and `tests/Feature/IncidentNotificationTest.php`: 28 tests, 244 assertions passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 49 assertions passed.
- Gallery report browser flow: 1 Playwright test passed at 390px.
- Metric-alert browser flow: 1 Playwright test passed at 390px.
- `npm run build`: passed.
- Pint: passed.
- `git diff --check`: passed.
- Isolated runtime restarted successfully; `https://buildpusher.com/login` returned HTTP 200.

### Commit and push

Gallery report commit and push: `a76179e Use dialog for gallery report composer`.

Metric-alert commit and push: `a99993d Use dialog for metric alert rules`.

The isolated `buildpusher-dev-main.service` runtime was fast-forwarded to `a99993d`, rebuilt, view-cached, route-cache-cleared, restarted and verified active on `main`.

### Exact next task

Complete the remaining audit and final verification: review deployment/repository compact actions and notification saved-filter forms for a real mobile benefit, then run the complete PHP/Pint/browser verification and document any intentionally unchanged inline workflow.

## Slice 6 — promotion composer

Status: complete and deployed to the isolated development runtime.

### Responsibility problem

The project release page kept the promotion request fields permanently beside deployment history and release evidence. Promotion is a short request, but it is still a consequential release operation that must retain the selected successful revision and its existing approval context.

### Boundary and design decision

- The promotion request uses the shared URL-backed modal.
- The project page remains responsible for the successful-build context, target-environment options and existing policy/request boundary.
- `BuildsController`, the promotion request, policy, action, approval workflow and queued deployment behavior remain unchanged.
- Approval, rollback, restore, cancellation and first-deployment workflows remain explicit page workflows because they need durable release context or carry recovery/destructive consequences.

This is a presentational boundary only. It reduces mobile page height without moving release invariants, authorization or persistence into the dialog component.

### Preserved contracts and safety guarantees

- The existing promotion route, HTTP method, validation keys, flash messages, target-environment rules, protected-environment approval behavior and lineage metadata are unchanged.
- Only successful builds expose the promotion trigger; the selected build identity remains explicit in the existing request contract.
- Invalid input reopens the promotion dialog without creating a promotion build.
- Existing duplicate-promotion, same-project, forward-environment, deployment-lock and authorization behavior remains in the existing operation boundary.
- Direct query URLs, normal anchor fallback, Escape handling and focus restoration remain available.

### Verification

- `tests/Feature/BuildPromotionTest.php`: 8 tests, 66 assertions passed, including closed-by-default, direct URL opening and validation-reopen coverage.
- Full PHP regression later passed 1,616 tests and 13,434 assertions.
- `npm run build`: passed.
- Pint: passed.
- `git diff --check`: passed.

### Commit and push

Commit and push: `5acc6a4 Use dialog for promotion requests`.

The isolated `buildpusher-dev-main.service` runtime was fast-forwarded, rebuilt, view-cached, restarted and verified active. This is isolated development evidence, not live acceptance.

### Exact next task

Inspect the notification saved-filter composer as the final compact, reversible candidate, then complete the full audit and verification record.

## Slice 7 — notification saved-filter composer

Status: complete and deployed to the isolated development runtime.

### Responsibility problem

The notifications page kept the saved-filter name form inside a secondary disclosure. Saving a named filter is a short preference operation, but the always-available form added mobile height to a page whose primary task is reviewing and acting on notifications.

### Boundary and design decision

- The saved-filter name form uses the shared URL-backed modal.
- Notification filtering, saved-filter listing/removal, bulk actions, authorization, validation and preference persistence remain page/controller boundaries.
- The filter query is carried into the dialog URL and existing store route so the saved preference still represents the current view.
- Inventory, bulk actions, destructive deletion and primary notification evidence remain visible or explicit page workflows.

This keeps the modal responsible for presentation and interaction state while preserving the existing preference operation and query semantics.

### Preserved contracts and safety guarantees

- Existing route, HTTP method, validation key, redirect, flash behavior, filter values and saved-filter persistence are unchanged.
- Invalid names reopen only the saved-filter dialog and do not write a preference.
- Current filters continue to be submitted explicitly; unrestricted request data is not introduced.
- Direct query URLs, no-JavaScript anchor fallback, Escape handling and focus restoration remain available.
- Notification ownership, workspace scoping, bulk-action authorization and secret-safe input behavior remain unchanged.

### Verification

- `tests/Feature/NotificationBulkActionTest.php` and `tests/Feature/NotificationInboxInsightsTest.php`: 12 tests, 72 assertions passed for the focused notification slice.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 53 assertions passed with the notification dialog fixture.
- Targeted Playwright saved-filter workflow: 1 test passed at 390px, including disclosure opening, URL state, Escape/focus restoration and direct URL opening.
- The corrected complete browser matrix: 26 tests passed in 21.1 minutes.
- `npm run build`: passed.
- Pint and `git diff --check`: passed.

### Commit and push

Commit and push: `556e67b Use dialog for saved notification filters`.

The isolated `buildpusher-dev-main.service` runtime was fast-forwarded, rebuilt, view-cached, restarted and verified active. The later compatibility test commit `1a7ee41` and browser coverage commit `b564506` were also pushed to `origin/main`.

## Final modal audit and verification

Status: complete locally on `main`; no production or paid-cloud operation was performed.

### Audit decision

The remaining UI was reviewed against the modal decision rule: use a dialog for a short, reversible, interruptible task; keep workflows requiring durable context, long forms, one-time credentials, remote execution, approval, recovery or destructive confirmation on the page.

The following remain intentionally non-modal:

- Repository webhook enable/rotate settings, because they issue one-time integration credentials and change external webhook state.
- First deployment, redeployment, approval, cancellation, rollback and restore, because they require release evidence, preflight context, durable status or recovery consequences.
- Provider and server setup, YAML configuration authoring/review/receipt, backup destination save/verify, SSO, two-factor, security settings and workspace deletion, because they are multi-step, secret-bearing, destructive or safety-critical.
- Database clone/restore and other destructive operations, because the destination and overwrite/recovery consequences must remain explicit.
- Inventory filters, timelines, logs, tables and command history, because they are primary evidence or navigation surfaces rather than short submission forms. Existing command/delete dialogs are retained where they already match the interaction contract.

No new generic action, repository, policy or business interface was introduced for this presentation work. Existing Form Requests, policies, actions, Livewire components, transactions, jobs and provider contracts remain the behavior boundaries.

### Final verification

- Complete strict PHP suite with PHP 8.5.10: 1,616 tests, 13,434 assertions passed in 693.03 seconds; no failures, warnings, risky tests or deprecations.
- Focused stale-server-test compatibility fix: 4 tests, 82 assertions passed. The old assertion was updated because the prior intentional removal of `Setup Information` had left the test expecting removed UI.
- Full Pint: passed.
- Composer locked platform requirements: passed for PHP 8.5.10 and required extensions. Composer itself emitted environment deprecation notices but exited successfully; no dependencies or lockfiles changed.
- Vite production asset build: passed.
- Node syntax check for the browser suite: passed.
- `git diff --check`: passed.
- Full browser matrix: 26 tests passed in 21.1 minutes, covering accessibility, light/dark 320/390/768/1440 layouts, no-JavaScript provider submission, all modal workflows, navigation, served Livewire assets and mobile/tablet/desktop visual audits.
- Isolated runtime route cache: rebuilt successfully with `artisan route:cache`.
- Isolated runtime Blade cache: rebuilt successfully with `artisan view:cache`.
- Isolated `buildpusher-dev-main.service`: active after restart.
- `https://buildpusher.com/login`: HTTP 200.
- Served Livewire asset: HTTP 200, `application/javascript`.
- Served Vite stylesheet: HTTP 200, `text/css`.

### Commits and push status

The final source branch is clean, on `main`, and aligned with `origin/main` at `b564506`:

- `5acc6a4` — promotion composer.
- `556e67b` — saved notification-filter composer.
- `1a7ee41` — compatibility test for the intentionally removed server setup panel.
- `b564506` — organization invitation modal browser coverage.

All commits were pushed immediately after creation. The isolated runtime was fast-forwarded to the final pushed tip and verified after caching/restart.

### Handoff

The modal modernization plan is complete for the local/dev scope. Physical-phone checks, production deployment, provider-backed acceptance, billing, mail, monitoring, GitHub App, SSO and the separate live acceptance drill remain external release gates and are not represented by these local results.

Exact next task: no further modal slice is justified by the current audit. Continue with a separately authorized product/UI backlog item or external acceptance gate.

## Follow-up Slice 1 — automation schedule and task composers

Status: complete locally and pushed in `5349f5d`; the isolated development runtime was updated and restarted at the pushed tip.

### Responsibility problem

Each expanded automation environment rendered the complete deployment-schedule and scheduled-task creation forms inline with runtime controls and execution history. On a 390px viewport these forms consumed roughly 590 pixels per environment, pushing existing schedules, task runs and capacity controls out of view.

### Boundary and design decision

- Deployment-schedule and scheduled-task creation use one URL-backed shared dialog per environment.
- The automation page remains responsible for environment context, existing schedules, task runs and runtime controls.
- Existing Form Requests, entitlement checks, policies, actions, encryption and queue behavior remain unchanged.
- YAML workflow editing, scaling, hibernation, task execution/deletion and other durable operations remain page workflows.

This is a presentation-only extraction. The dialog carries the environment identity through the existing route and carries a small hidden dialog key solely to reopen the correct form after validation failure.

### Preserved contracts and safety guarantees

- Existing route names, methods, validation keys, entitlement errors, flash messages and persistence behavior are unchanged.
- Invalid schedule input reopens the schedule dialog for the submitted environment; task input uses the separate task dialog key.
- Existing defaults for cron, timezone, timeout, overlap prevention and failure alerts are retained.
- Commands remain submitted through the existing request/action boundary and are not placed in URLs or browser storage.
- Direct query URLs, no-JavaScript anchor fallback, Escape, Back navigation and focus restoration remain available.

### Verification

- `tests/Feature/AutomationTest.php`: 35 tests, 182 assertions passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 55 assertions passed.
- Focused Playwright automation schedule/task flow at 390px: 1 test passed in 34.0 seconds.
- Pint: passed.
- Node syntax check and `git diff --check`: passed.

### Exact next task

Convert the deployment operator-note editor while preserving the named `buildNote` validation bag.

## Follow-up Slice 2 — deployment operator-note composer

Status: complete locally and pushed in `42080e0`; the isolated development runtime was updated and restarted at the pushed tip.

### Responsibility problem

The deployment detail page kept a six-line operator-note editor permanently above the deployment log. The note is useful context, but the editor is only needed when adding or changing that context and pushed the deployment evidence lower on mobile screens.

### Boundary and design decision

- The operator-note editor uses the shared URL-backed dialog.
- The deployment page keeps the current note, deployment timeline, logs and recovery controls visible in their existing context.
- The existing `BuildNoteRequest`, `buildNote` error bag, policy, action, activity metadata and redirect messages remain unchanged.
- The dialog is marked `wire:ignore` because its parent Livewire component polls active deployments; an open editor must not be replaced during a poll.

This is a presentation-only extraction. The normal HTTP form still owns note validation and persistence, while the Livewire component continues to own deployment refreshes.

### Preserved contracts and safety guarantees

- Existing route, PATCH method, validation key, named error bag, trimming, blank-to-null normalization, flash messages and activity behavior are unchanged.
- Existing notes remain visible and the same save/clear behavior is available from the dialog.
- Invalid submissions reopen the dialog using the existing named `buildNote` errors.
- Operator notes remain escaped and bounded; the dialog does not expose note content in its URL.
- Direct query URLs, no-JavaScript fallback, Escape, Back navigation and focus restoration remain available.

### Verification

- `tests/Feature/DeploymentNoteTest.php`: 5 tests, 32 assertions passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 58 assertions passed.
- Focused light 390px built-asset browser route: 1 test passed in 1.4 minutes, including the deployment-note open/close, focus and direct-URL flow.
- Pint: passed.
- Node syntax check and `git diff --check`: passed.

### Exact next task

Convert the compact application-detail forms: add environment, add variable version and add process definition.

## Follow-up Slice 3 — application-detail composers

Status: complete locally and pushed in `c428f04`; the isolated development runtime was updated, cached and restarted at the pushed tip.

### Responsibility problem

The application detail page rendered three complete creation forms inside every
environment card: add environment, add encrypted variable version, and add
worker/scheduler process. These are infrequent creation actions, while the
environment overview and existing resources are the primary information users
need. Keeping the forms inline made the mobile page substantially longer and
made each environment card compete with its own operational context.

### Boundary and design decision

- Add-environment uses a project-level shared URL-backed dialog.
- Variable-version and process-definition creation use one environment-aware
  dialog per environment.
- Existing environment settings, deployment controls, resource attachment,
  existing variable/process inventories and destructive actions remain in their
  current page context.
- Existing Form Requests, policy checks, entitlement checks, actions and
  transaction/encryption behavior remain unchanged.

This is a presentation-only extraction. The dialog carries only the existing
project/environment route identity and a small dialog key in the URL; submitted
forms retain the existing panel markers so validation redirects reopen the
correct environment context.

### Preserved contracts and safety guarantees

- Existing route names, methods, validation keys, entitlement failures, flash
  messages and persistence behavior are unchanged.
- Add-environment, variable and process validation failures reopen the matching
  dialog after a redirect; no invalid record is written.
- Variable values remain absent from rendered page content, including failed
  validation responses. The value field intentionally remains blank after an
  error rather than reflecting secret input.
- Existing encrypted variable versioning, scheduler replica normalization and
  environment ownership checks remain in their current actions and requests.
- Direct query URLs, no-JavaScript anchor fallback, Escape, Back navigation and
  focus restoration remain available through the shared dialog foundation.

### Verification

- `tests/Feature/ProjectEnvironmentTest.php`: 7 tests, 47 assertions passed.
- `tests/Feature/EnvironmentOperationsTest.php`: 3 tests, 22 assertions passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 62 assertions passed.
- Focused 390px application-detail Playwright flow: 1 test passed in 17.4
  seconds.
- Pint, Node syntax check and `git diff --check`: passed.

### Exact next task

Convert the gallery report-resolution dialog while preserving
reporter/contributor authorization, unread notifications and resolution-note
validation.

## Follow-up Slice 4 — gallery report-resolution composers

Status: complete locally and pushed in `be3ea3f`; the isolated development runtime
was updated, cached and restarted at the pushed tip before this follow-up slice.

### Responsibility problem

The contributor recipe page and feedback inbox each rendered the full
resolution-note editor inside every report card. Most reports only need their
status, details and existing resolution context; keeping the textareas inline
made long report lists especially difficult to scan on mobile and duplicated
the same resolution UI in two entry points.

### Boundary and design decision

- One URL-backed dialog is created per report, with a stable report-specific
  DOM ID and query key.
- The shared Blade presentation fragment is used by both the recipe detail and
  contributor inbox pages.
- Bulk resolve/reopen and the input-free reopen action remain inline because
  they are selection/lifecycle controls rather than text composition.
- Existing `RecipeReportResolutionRequest`, contributor policy checks, locked
  actions, notification service and encrypted model casts remain unchanged.

This is a view-level reuse boundary only. No generic action or report
repository was introduced.

### Preserved contracts and safety guarantees

- Existing resolve and resolution-note update routes, PATCH methods, field
  names, flash messages, response statuses and redirect behavior are unchanged.
- The dialog URL retains inbox filters and page context. A hidden report ID
  reopens only the submitted report after resolution-note validation fails.
- Resolution notes remain escaped and encrypted; the URL contains only the
  report identity required to reopen the correct editor.
- Contributor/report ownership, anonymous reporter rendering, unread update
  behavior, audit events, locks and idempotent actions are unchanged.
- Direct query URLs, no-JavaScript anchor fallback, Escape, Back navigation and
  focus restoration remain available through the shared dialog foundation.

### Verification

- `tests/Feature/RecipeReportTest.php`: 16 tests, 186 assertions passed.
- `tests/Feature/RecipeFeedbackInboxTest.php`: 24 tests, 235 assertions
  passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 66 assertions
  passed.
- Focused 390px gallery contributor-resolution Playwright flow: 1 test passed
  in 17.9 seconds.
- Pint, Node syntax check and `git diff --check`: passed.

### Exact next task

Update the isolated runtime, then convert the observability operational-incident
investigation-note editor while preserving incident authorization, activity
timing and status transitions.

## Follow-up Slice 5 — observability investigation-note composer

Status: complete locally and pushed in `2c05632`; isolated runtime and final
regression verification are complete.

### Responsibility problem

Each active operational incident rendered a free-text investigation-note input
inside the response timeline. The timeline and status controls are useful
context, but the note editor is only needed when recording evidence and made
incident cards taller on small screens.

### Boundary and design decision

- The investigation-note editor uses the shared URL-backed dialog, with one
  stable dialog ID and query key per incident.
- Assignment, acknowledgement, resolution, timeline evidence and incident
  status transitions remain inline because they are response workflow rather
  than short composition tasks.
- The existing `StoreOperationalIncidentNoteRequest`, incident policy,
  activity event, encrypted event storage and redirect behavior remain the
  business boundary.

This is a presentation-only extraction. The modal owns focus and URL state;
the existing request and controller continue to own authorization, validation
and persistence. No provider, job, transaction or incident-state abstraction
was introduced.

### Preserved contracts and safety guarantees

- The existing route, POST method, `message` field, 5,000-character limit,
  flash/validation behavior and authorization-before-validation ordering are
  unchanged.
- Invalid note submissions reopen only the submitted incident's dialog and
  retain the existing validation message; no event is written on rejection.
- Timeline expansion, assignment, acknowledgement, resolution, recovery and
  activity timing remain unchanged.
- The incident identity used to reopen the editor is bounded to the rendered
  incident and is not used as a permission substitute.
- Direct query URLs, normal anchor fallback, Escape handling, focus
  restoration and responsive native-dialog behavior remain available.

### Verification

- `tests/Feature/OperationalIncidentTest.php`: 7 tests, 60 assertions passed.
- `tests/Browser/fixtures/AssetLayoutFixtureTest.php`: 1 test, 67 assertions
  passed.
- Dedicated observability 390px Playwright flow: 1 test passed in 26.4
  seconds, including timeline expansion, dialog focus and Escape restoration.
- Light 390px built-asset route matrix: 1 test passed in 1.0 minute,
  including the observability dialog path.
- Pint, Node syntax check and `git diff --check`: passed.

### Commit and push

Commit and push: `2c05632 Use dialog for incident investigation notes`.

### Exact next task

No further modal slice is justified by the current audit. Continue with a
separately authorized product/UI backlog item or external acceptance gate.

## Follow-up final verification — 2026-09-19

Status: complete for the isolated local/development scope.

### Verification record

- Complete strict PHP 8.5.10 suite: **1,622 tests / 13,513 assertions**
  passed in **416.55 seconds**, with no failures, warnings, risky tests or
  deprecations.
- Full Pint: passed.
- Locked Composer platform requirements under PHP 8.5.10: passed. Composer
  emitted known upstream PHP 8.5 deprecation notices but exited successfully;
  no dependencies or lockfiles changed.
- Vite production build: passed.
- Browser-test Node syntax check: passed.
- `git diff --check`: passed.
- Complete built-asset browser matrix: **20 tests passed in 8.9 minutes**,
  covering the new incident-note flow, all prior modal flows, light/dark
  320/390/768/1440 layouts, provider no-JavaScript submission, navigation,
  focus/Escape behavior and served assets.

### Isolated runtime evidence

- Runtime checkout `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`
  is clean on `main` at `5442346`.
- `buildpusher-dev-main.service` is active after the update.
- Blade and route caches were rebuilt successfully.
- `https://buildpusher.com/login` returns HTTP 200.
- The manifest CSS returns `text/css`; the served Livewire asset returns
  `application/javascript`.

This is isolated development evidence only. No production deployment, paid
cloud operation, provider-backed acceptance or physical-phone verification was
performed. Those remain separately authorized external gates.

## Follow-up Slice 6 — compact settings and review dialogs

Status: complete locally and pushed on `main` in `42d4613`.

### Responsibility problem

The previous audit left five short workflows inline or on a separate page even
though their surrounding pages were primarily inventory, evidence or summary
surfaces: server display-name editing, dashboard widget selection, the monthly
cost threshold, saving an observability investigation view, and workspace
feedback review. On mobile these controls either added avoidable page height or
sent users away from the context they were acting on.

### Boundary and design decision

- The server detail page now opens display-name editing in a shared dialog;
  the existing `servers.edit` page remains available as a direct full-page
  fallback.
- Dashboard customization, cost-budget editing, investigation-view saving and
  feedback review each use the shared URL-backed dialog component.
- Existing Form Requests, policies, actions, routes, methods, validation keys,
  flash messages and persistence remain the application boundary.
- Inventory, evidence, saved-view lists, feedback content, deployment pages,
  setup forms, YAML/configuration editors, recovery workflows and security
  settings remain page workflows where context or safety requires it.

This is a presentation-only extraction. No generic action, repository,
policy, provider contract or business abstraction was introduced.

### Preserved contracts and safety guarantees

- Server labels still normalize whitespace, clear to the cloud hostname and
  record the same activity behavior; the technical hostname cannot be
  changed by the dialog.
- Dashboard widget preferences still preserve unrelated user preferences and
  reject unsupported widgets without writing.
- Cost management retains manager and entitlement authorization before budget
  validation and writing; the budget remains a planning threshold rather than
  a provider spending cap.
- Investigation filters are copied from the normalized context object rather
  than unrestricted query input, preserving the existing query-safety
  guarantee. Saved-view authorization, expiry, ownership and evidence
  revalidation remain unchanged.
- Feedback review authorization still precedes validation and persistence;
  workspace visibility, encrypted feedback storage and status transitions are
  unchanged.
- Direct dialog URLs, native anchor fallback, validation reopening,
  Escape/back handling, focus restoration and responsive sizing use the
  existing shared modal foundation.

### Verification

- Focused feature suite: **59 tests / 513 assertions** passed under PHP
  8.5.10, including authorization, query-safety, persistence and dialog URL
  assertions.
- Pint: passed.
- Blade view compilation: passed.
- Vite production build: passed.
- Built-asset browser matrix: **20 tests passed in 11.4 minutes**.
- `git diff --check`: passed.

### Commit and push

Commit and push: `42d4613 Use dialogs for compact settings workflows`.

### Exact next task

Keep long setup, deployment, recovery, credential, YAML, security and
destructive workflows as explicit pages. Any further modal work requires a
newly identified compact workflow and separate authorization; production
deployment, physical-device checks and external acceptance remain outstanding.

## Follow-up Slice 7 — application, server and website creation dialogs

Status: complete locally and pushed on `main` in `f18447f`, `87bf94f`,
`000cfe0` and `35404fb`.

### Responsibility problem

The inventory pages already represented the user's application, server and
website resources, but their primary creation actions sent the user to separate
pages. That added navigation and context switching, especially on mobile and
from dashboard setup guidance. The existing creation operations were already
cohesive; the justified boundary was a shared presentation entry point rather
than a new business abstraction.

### Boundary and design decision

- Website creation is available from the websites inventory dialog and reuses
  the existing website form, request, policy, action and provisioning flow.
- Server creation is available from the servers inventory dialog and reuses
  the existing server form, request, policy, action and provider flow.
- New Application is available from the projects inventory dialog and shares
  the creation form with the standalone project-create page.
- Dashboard quick actions, setup steps, mobile quick action, command palette,
  provider/project/repository guidance and deployment preflight link to the
  inventory dialogs where the user can act in context.
- The shared URL-backed native dialog remains responsible only for presentation,
  focus, Escape/back behavior and direct-link state. No generic action,
  repository, policy or provider abstraction was introduced.

### Preserved contracts and safety guarantees

- `/projects/create`, `/servers/create` and `/websites/create` remain direct
  full-page fallbacks, including no-JavaScript use.
- Existing POST routes, methods, field names, validation rules, named error
  behavior, flash messages, policies, plan limits, organization scoping,
  transactions, persistence and queued provisioning behavior are unchanged.
- The server and website dialogs continue using the existing provider and
  active-server eligibility data; application templates continue using the
  existing catalog and allowlist.
- Failed dialog validation redirects preserve the creation dialog query state
  while keeping submitted sensitive values subject to the existing request and
  session handling.
- No provider credentials, secrets, route contracts or job serialization were
  changed.

### Verification

- Complete strict PHP 8.5.10 suite: **1,628 tests / 13,561 assertions**
  passed in **551.61 seconds**, with no failures, warnings, risky tests or
  deprecations.
- Locked Composer platform requirements under PHP 8.5.10: passed; Composer
  emitted only the known upstream PHP 8.5 deprecation notices.
- Focused dashboard, dialog, title and asset checks: **53 tests / 741
  assertions** passed.
- `AssetLayoutFixtureTest.php`: **1 test / 77 assertions** passed.
- Dedicated 390px creation-dialog Playwright flow: **1 test passed**.
- Complete built-asset browser matrix: **21 tests passed in 14.3 minutes**.
- Full Pint, Vite production build, Node syntax check, route cache, Blade view
  cache and `git diff --check`: passed.

### Exact next task

This creation-dialog slice is complete. Keep long setup, credential,
deployment, recovery and destructive workflows as pages unless a new compact
workflow is separately identified and authorized. Production deployment,
provider-backed acceptance and physical-device verification remain external
gates.

## Follow-up Slice 8 — gallery publishing and on-demand script inspection

Status: complete locally and pushed on `main` in `b2339ba`.

### Responsibility problem

The gallery page's primary publish action navigated away from the user's
current discovery context, while script inspection was not available from the
gallery card. On mobile this added avoidable context switching and made it
harder to inspect a published recipe before opening its full detail page.

### Boundary and design decision

- The existing gallery publish form is rendered in the shared URL-backed
  dialog and continues to submit to the existing `recipes.store` operation.
- Script inspection uses a small presentation endpoint that returns only the
  published recipe's escaped script fragment. The gallery card opens it in a
  per-recipe dialog and loads the fragment on demand.
- The shared modal loader owns only presentation concerns: same-origin fetch,
  loading/error state, focus, Escape/back behavior and direct-link fallback.
- `RecipeGalleryIndexRequest` recognizes dialog state separately from the
  existing filter contract; it does not broaden or normalize gallery filters.
- No new business action, repository, policy or provider abstraction was
  introduced. The existing request, policy, action, entitlement and
  persistence boundaries remain the operation boundary.

This applies the single-responsibility principle at the UI boundary without
moving business rules into a dialog component or creating a generic CRUD
abstraction.

### Preserved contracts and safety guarantees

- Existing publish validation keys, old-input behavior, flash messages,
  entitlement checks, authorization and persistence remain unchanged.
- Publish validation failures reopen the publish dialog only when the gallery
  marker is present; the standalone recipe-create flow remains unchanged.
- The default gallery index does not load or render recipe script bodies.
- The script endpoint returns a not-found response for private or otherwise
  unpublished recipes and exposes an escaped script only for published
  recipes.
- No recipe script is placed in the default gallery response, logs or session
  old input by this slice.
- Existing detail, favorite, rating, report and contributor workflows remain
  page-based and retain their current routes and response behavior.
- Native anchors provide direct URL and no-JavaScript fallbacks. URL-backed
  dialog state, Escape handling and focus restoration use the existing shared
  modal foundation.

### Verification

- Focused recipe feature coverage: **37 tests / 415 assertions** passed under
  PHP 8.5.10.
- Browser fixture export: **1 test / 88 assertions** passed.
- Dedicated 390px gallery publishing and script-inspection flow: **1 test
  passed in 43.9 seconds**.
- Complete strict PHP 8.5.10 suite: **1,629 tests / 13,583 assertions**
  passed in **687.53 seconds**, with no failures, warnings, risky tests or
  deprecations.
- Complete built-asset browser matrix: **22 tests passed in 10.2 minutes**.
- Full Pint, locked Composer platform requirements, Vite production build,
  Node syntax check, Blade view cache, route cache and `git diff --check`:
  passed. Composer emitted only the known upstream PHP 8.5 deprecation
  notices and no dependency or lockfile changed.

### Commit and push

Implementation commit and push: `b2339ba Use dialogs for gallery publishing
and script inspection`.

### Exact next task

Keep long recipe editing, installation/update, recovery and other durable
workflows as explicit pages. Continue with a separately authorized compact UI
workflow or external acceptance gate.

## Follow-up Slice 9 — repository creation dialog

Status: complete locally, pushed on `main` in `30331e3`, and deployed to the
isolated development runtime.

### Responsibility problem

The Repositories inventory sent users to a separate, long creation page even
when they were already reviewing deployment targets. The same context switch
appeared in dashboard setup, project readiness, GitHub App selection, website
deployment actions, provider details and the command palette. This was a
presentation boundary problem; the existing repository operation was already
cohesive.

### Boundary and design decision

- The inventory page now owns the URL-backed `repository-create-dialog`.
- The existing repository form partial is reused inside the dialog rather
  than duplicated. A small optional field prefix gives the modal controls
  unique IDs alongside the inventory's provider and website filters.
- Existing `RepositoryRequest`, authorization, `CreateRepositoryAction`,
  provider/website queries and `repositories.store` remain the business
  boundary.
- Primary repository entry points now link to the inventory dialog and retain
  their existing prefilled provider, website, name, URL and branch context.
- `/repositories/create` remains the direct full-page and no-JavaScript
  fallback. No generic repository abstraction or new business service was
  introduced.

This keeps the single-responsibility split clear: the inventory dialog owns
presentation and focus/history behavior, while the existing request and
action own validation, authorization, normalization, persistence and remote
workflow setup.

### Preserved contracts and safety guarantees

- Existing form names, validation rules, branch default, URL normalization,
  deployment-root/path-filter handling, command fields, description field,
  flash behavior and redirect to the repository detail page are unchanged.
- Source-control provider filtering and active-website eligibility remain
  enforced both in the form and by `RepositoryRequest`; missing prerequisites
  still disable submission and show the existing guidance.
- Validation failures from the modal return to the open dialog with old input
  and the existing error keys. Direct create and edit pages retain their
  original field IDs and behavior.
- Project readiness, GitHub App selection, dashboard setup, command palette,
  website and provider links now open the same dialog without bypassing
  authorization or changing deployment/webhook semantics.
- No provider token, repository credential, job serialization, webhook
  behavior or persisted value changed.

### Verification

- Focused PHP coverage: **55 tests / 479 assertions** passed under PHP
  8.5.10.
- Browser fixture export: **1 test / 92 assertions** passed.
- Dedicated 390px primary-creation browser flow: **1 test passed in 26.0
  seconds**.
- Complete strict PHP 8.5.10 suite: **1,631 tests / 13,597 assertions**
  passed in **560.83 seconds**, with no failures, warnings, risky tests or
  deprecations.
- Complete built-asset browser matrix: **22 tests passed in 10.1 minutes**.
- Full Pint, locked Composer platform requirements, Vite production build,
  Node syntax check, Blade view cache, route cache and `git diff --check`:
  passed. Composer emitted only the known upstream PHP 8.5 deprecation
  notices; no dependencies or lockfiles changed.

### Commit, push and runtime

Implementation commit and push: `30331e3 Use a dialog for repository
creation`.

The isolated `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`
checkout is clean on `main` at `30331e3`; its assets and caches were rebuilt,
`buildpusher-dev-main.service` restarted successfully, and the public login
page returned HTTP 200 with `app-TtqGG4AO.css`.

### Exact next task

Keep repository editing, deployment, webhook rotation, provider setup and
other durable or remote-side-effect workflows as explicit pages. Continue
with a separately authorized compact UI workflow or external acceptance gate.

## Follow-up Slice 10 — provider, repository, and recipe dialogs

Status: complete locally and pushed to `main` in `848c96e`.

### Responsibility problem

Provider creation, provider editing, repository editing, and recipe creation
or editing still moved the user to separate form pages. That made the common
inventory and detail journeys lose context and duplicated the modal behavior
that already existed for other compact workflows.

### Boundary and design decision

- Provider inventory owns the URL-backed provider creation dialog.
- Provider detail owns the provider edit dialog when its edit URL is requested.
- Repository detail owns the repository edit dialog when its edit URL is
  requested, without rendering encrypted deployment hooks on the normal
  read-only page.
- Recipe inventory owns the create dialog and resolves only the explicitly
  selected recipe for editing. Recipe detail renders the edit dialog only for
  its edit URL. This keeps encrypted recipe scripts out of the default
  inventory/detail HTML and avoids decrypting every recipe in a list.
- Existing form partials are reused with optional field prefixes so filters
  and dialog controls have unique IDs. Existing Form Requests, policies,
  actions, transactions, encrypted casts, redirects, validation keys and
  direct full-page routes remain the application boundary and fallback.
- Dashboard, server/repository prerequisites, gallery links, deployment
  guidance and deployment-status links now point to the corresponding
  server-rendered dialog URLs.

This preserves the responsibility split: Blade dialog components own
presentation, URL/history state and focus behavior; Form Requests and
policies keep HTTP validation and authorization; existing actions keep
business writes and remote workflow semantics.

### Preserved contracts and safety guarantees

- Provider tokens remain encrypted, blank on edit unless newly submitted,
  excluded from old input and absent from connection history/log output.
- Repository build and post-deployment hooks remain encrypted and absent from
  normal repository detail/inventory output; they render only in the explicit
  edit dialog or existing full-page editor.
- Recipe scripts remain encrypted and absent from normal inventory output and
  search results. Only one authorized selected recipe is loaded for an edit
  dialog.
- Existing validation error keys, old input, authorization ordering, response
  redirects, flash/status messages, persisted values, job serialization,
  webhook behavior and deployment preflight semantics are unchanged.
- Native anchors retain direct URL and no-JavaScript fallbacks. Dialog URLs
  preserve Escape, focus restoration, browser history and server-open state.

### Verification

- Focused modal and affected feature coverage: **107 tests / 881 assertions**
  passed under PHP 8.5.10.
- Browser fixture export: **1 test / 112 assertions** passed.
- Full built-asset browser/dialog matrix: **23 tests passed in 11.0 minutes**.
- Full Pint, Blade view cache, Node syntax check and `git diff --check` passed.
- No dependency or lockfile changed. The system PHP 8.3 browser invocation
  was rejected by the PHPUnit platform check; the rerun used the required
  `/root/.local/share/buildpusher/php-8.5.10/bin/php` and passed.

### Commit and push

Implementation commit and push: `848c96e Use modals for provider repository
and recipe editing`.

### Exact next task

Continue the add/edit inventory with website editing and other compact
resource-management forms. Keep long import, configuration-review, security,
recovery and remote-side-effect workflows as explicit pages until their
ordering and failure behavior can be preserved in a server-rendered dialog.

## Follow-up Slice 11 — website dialogs

Status: complete locally and pushed to `main` in `4289a44`.

### Responsibility problem

Website creation already opened in an inventory dialog, but its implementation
was embedded in the inventory page and website editing still navigated to a
full-page form. This made the website workflow inconsistent with the provider,
repository, and recipe dialog conventions and duplicated the long form
markup's presentation responsibility.

### Boundary and design decision

- The website creation form now lives in a reusable
  `scenes.websites.create-dialog` component and is included by the inventory.
- Website detail owns a server-rendered `scenes.websites.edit-dialog` when the
  URL requests `dialog=edit-website`. The normal detail page does not load the
  server choices or decrypted environment text.
- The existing website form partial is reused with prefixed IDs. Its direct
  create/edit pages remain unchanged as no-JavaScript fallbacks.
- Dashboard/deployment guidance and repository preflight links now point to
  the website detail edit dialog.
- `WebsiteRequest`, `CreateWebsiteAction`, `UpdateWebsiteAction`, health and
  placement actions, encryption casts, plan checks and redirects remain the
  business boundary.

This keeps dialog markup responsible for presentation and URL state while
preserving the existing HTTP validation, policy authorization, persistence,
remote provisioning and cleanup semantics.

### Preserved contracts and safety guarantees

- Website environment values remain encrypted and are absent from the normal
  website detail response; they render only in the explicit authorized edit
  dialog or existing editor page.
- Server eligibility, plan limits, monitoring defaults, health path rules,
  retention bounds, placement behavior, validation keys and redirects remain
  unchanged.
- Existing create-dialog prerequisite alerts and no-submit behavior remain
  intact. Native links retain direct and no-JavaScript fallbacks.

### Verification

- Focused website/modal coverage: **66 tests / 569 assertions** passed under
  PHP 8.5.10.
- Targeted built-asset 390px creation/edit browser coverage: **2 tests passed
  in 1.6 minutes**.
- Pint, Blade view cache, Node syntax check and `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `4289a44 Use modals for website editing`.

### Exact next task

Continue with remaining compact server/infrastructure add/edit forms and audit
their encrypted fields, plan gates, remote side effects, and authorization
ordering before deciding whether each belongs in a dialog or remains a direct
workflow page.

## Follow-up Slice 12 — server dialogs

Status: complete locally and pushed to `main` in `e39bc00`.

### Responsibility problem

Server creation was already URL-backed, but the dialog markup lived directly
inside the inventory view. Server display-name editing was likewise embedded
inside the Livewire detail view, while the standalone routes remained the only
reusable form boundary. This made server creation/editing inconsistent with
the other resource dialogs and made the form partial assume fixed element IDs.

### Boundary and design decision

- Server inventory now includes a reusable
  `scenes.servers.create-dialog` component.
- Server detail now includes a reusable `scenes.servers.edit-dialog`
  component for display-name changes.
- The existing server form partial accepts an optional field prefix and
  explicit nullable server model, preserving the standalone create page while
  making modal controls unique.
- Server catalog JavaScript now finds fields by their form names within each
  `[data-server-catalog]` component. This preserves provider-dependent catalog
  loading for prefixed modal fields without relying on duplicate-prone IDs.
- Existing full-page create/edit routes, `ServerRequest`,
  `ServerDisplayNameRequest`, policies, plan checks, provisioning action,
  retries, redirects and one-time credential handling remain unchanged.

This applies single responsibility at the presentation boundary only: dialog
components own modal markup and URL state, while existing requests, policies,
actions and jobs retain validation, authorization, persistence and provisioning
responsibilities.

### Preserved contracts and safety guarantees

- Provider selection, region/size/image catalog refresh, recipe selection,
  plan-limit handling and provisioning submission remain unchanged.
- Display-name updates still change only the BuildPusher label; cloud hostname,
  authorization, event recording, normalization, validation keys and redirects
  remain unchanged.
- No server credentials or remote provisioning fields were added to the edit
  dialog. Existing direct routes remain available for no-JavaScript clients.
- Modal field prefixes prevent ID collisions while request names and error keys
  remain the existing persisted/validation contract.

### Verification

- Focused server/modal coverage: **29 tests / 266 assertions** passed under
  PHP 8.5.10.
- Browser fixture export: **1 test / 121 assertions** passed.
- Built-asset creation/edit dialog workflows: **2 tests passed in 1.3
  minutes**.
- Pint, Blade view cache, Node syntax, Vite asset build and
  `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `e39bc00 Use reusable dialogs for server
workflows`.

### Exact next task

Audit the remaining infrastructure add/edit surfaces in order: database
resources, load balancers, domains and backup destinations. Extract only
compact local forms whose authorization, plan checks and side-effect ordering
can be preserved; keep import, restore, provisioning and other remote or
destructive workflows as explicit pages unless a safe URL-backed dialog
boundary is demonstrated.

## Follow-up Slice 16 — backup setup dialogs

Status: complete locally and pushed to `main` in `9d10672`.

### Responsibility problem

Backup scheduling already used a dialog, but schedule markup was embedded in
the backup inventory. Destination creation and editing were still disclosure
forms rendered inline for every destination. That made the recovery page carry
multiple mutation presentations and rendered every destination edit form even
when no edit was requested.

### Boundary and design decision

- Destination creation now uses `scenes.backups.destination-create-dialog`.
- Destination editing uses `scenes.backups.destination-edit-dialog` and is
  rendered only for the destination selected by
  `dialog=edit-destination-{id}`.
- Schedule creation now uses `scenes.backups.schedule-dialog`.
- The reusable destination form retains provider presets and is used inside the
  create/edit dialog components. It now renders field-level validation errors
  and optional form markers for safe dialog reopening.
- The inventory retains organization-scoped data, management gating, selected
  destination resolution and verification controls. Existing requests,
  policies, entitlements, actions, encryption casts, remote verification and
  restore operations remain unchanged.

This keeps local form presentation separate from backup/recovery operations and
does not move HTTPS verification, restore, run-backup or destructive cleanup
into the dialog boundary.

### Preserved contracts and safety guarantees

- S3/Spaces/R2/S3-compatible presets, endpoint derivation, region guidance,
  bucket/path safeguards and provider documentation remain unchanged.
- Edit credential fields remain blank; blank values retain encrypted keys and
  submitted values remain excluded from flashed old input and rendered output.
- Destination immutability checks for retained snapshots, verification
  success/failure sanitization, temporary-object semantics and schedule
  validation remain unchanged.
- Create/edit URLs, validation error reopening, request field names, response
  messages and no-JavaScript submissions remain intact.
- The default backups page does not render the selected destination's edit
  form or its credential rotation inputs. The explicit edit URL does.

### Verification

- Backup/database regression coverage: **28 tests / 202 assertions** passed
  under PHP 8.5.10.
- Browser fixture export: **1 test / 128 assertions** passed.
- Built-asset backup schedule/destination workflow: **1 test passed in 51.3
  seconds**.
- Blade view cache, Pint, Node syntax, Vite/browser asset checks and
  `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `9d10672 Use reusable dialogs for backup
setup`.

### Exact next task

Audit remaining product forms that are already local dialogs: organization
invites, automation tokens/schedules/tasks, project environment variables and
processes, observability rules/destinations/incidents, notification filters,
feedback, build notes, gallery actions and cost budgets. Extract them into
feature components without changing permissions, secret handling, queued side
effects or protocol/recovery ordering. Keep configuration authoring, imports,
restore, authentication and other long workflows as explicit pages.

## Follow-up Slice 17 — application and repository creation dialogs

Status: complete locally and pushed to `main` in `1316261`.

### Responsibility problem

New application and repository creation already used URL-backed dialogs, but
their markup remained embedded in the two inventory pages. This left each
inventory responsible for both list rendering and a sizeable creation form.

### Boundary and design decision

- Application creation now uses `scenes.projects.create-dialog`.
- Repository creation now uses `scenes.repositories.create-dialog`.
- Existing project and repository form partials remain the field boundary;
  dialog components own modal markup, prerequisite guidance, actions and
  cancel URLs.
- `StoreProjectRequest`, `StoreRepositoryRequest`, policies, template
  selection, provider/website scoping, `CreateProjectAction`, repository
  actions, transactions and redirects remain unchanged.

No generic creation service or new interface was introduced. This is a
presentation-only single-responsibility extraction.

### Preserved contracts and safety guarantees

- Application template defaults/allowlists, transaction-created production
  environment, entitled processes, slug behavior and flash/redirect behavior
  remain unchanged.
- Repository provider and active-website prerequisites, field prefixes,
  validation keys, encrypted hook handling and deployment behavior remain
  unchanged.
- Existing dialog URLs, focus/history behavior and direct full-page routes
  remain available.

### Verification

- Creation, project and repository regression coverage: **33 tests / 224
  assertions** passed under PHP 8.5.10.
- Blade view cache, Pint and `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `1316261 Extract application and repository
create dialogs`.

### Exact next task

Continue the local-dialog audit with automation tokens/schedules/tasks and
organization invitations. Preserve token secrecy, schedule/task validation,
membership authorization, rate limits, dispatch timing and existing named
error/flash behavior.

## Automation and organization dialog follow-up — 2026-09-19

Automation token creation, deployment-schedule creation, scheduled-task
creation and workspace invitations now use reusable scene components:
`scenes.automation.token-dialog`, `scenes.automation.schedule-dialog`,
`scenes.automation.task-dialog` and `scenes.organizations.invite-dialog`.
The feature pages retain URL-backed open state, trigger links and entitlement
or membership gating; the components own only the modal form presentation.

Existing token secrecy, one-time plaintext display, schedule/task validation,
encrypted task-command handling, membership authorization, entitlement checks,
rate limits, notification dispatch, validation keys, old-input behavior and
flash messages remain unchanged. No workflow or persistence abstraction was
introduced.

Verification passed with 56 focused PHP tests / 299 assertions, one Blade
fixture test / 128 assertions, and three targeted mobile browser journeys.
Blade view cache, Pint and `git diff --check` passed. No dependency or
lockfile changed.

Implementation commit and push: `83ff12a Extract automation and organization
dialogs`.

The next task is the remaining inline-dialog audit: project child-resource
forms, observability, notifications, feedback, build notes, gallery actions
and cost/budget workflows. Long configuration, import, restore,
authentication and protocol workflows remain explicit pages unless their
ordering and failure contracts can be preserved.

## Project child-resource dialog follow-up — 2026-09-19

Application detail environment creation, encrypted-variable creation and
worker/scheduler process creation now render through reusable components:
`scenes.projects.environment-create-dialog`,
`scenes.projects.variable-create-dialog` and
`scenes.projects.process-create-dialog`. The detail page retains resource
scoping, policy checks, feature entitlements, URL state and validation-panel
state; each component owns only its form presentation.

Environment identity and panel markers, validation keys, encrypted-value
non-disclosure, process-command handling, old input, redirects and existing
actions remain unchanged. No persistence, authorization or deployment
workflow was moved into the components.

Verification passed with 15 focused PHP tests / 121 assertions and one
targeted mobile project-detail browser journey. Blade cache, Pint and
`git diff --check` passed. No dependency or lockfile changed.

Implementation commit and push: `30bad5e Extract project child-resource
dialogs`.

The next task is the remaining inline-dialog audit through observability,
notifications, feedback, build notes, gallery actions and cost/budget
workflows. Long configuration, import, restore, authentication and protocol
flows remain explicit pages where modal extraction could alter ordering or
failure semantics.

## Observability dialog follow-up — 2026-09-19

Metric alert creation, environment-investigation saving and operational
incident investigation notes now render through reusable scene components:
`scenes.observability.metric-rule-dialog`,
`scenes.observability.investigation-dialog` and
`scenes.observability.incident-note-dialog`. Inventory and incident partials
retain policy/entitlement gating, resource identity and URL-backed open state;
the components own only the form presentation.

Metric thresholds, server scoping, investigation filter identity, expiry
choices, incident identity, bounded note sizes, secret-safe old input,
validation keys, notification behavior and response messages remain
unchanged. Larger destination, status-page and status-update forms were not
mixed into this slice and remain the next observability work because their
create/update workflows have separate validation and subscriber-notification
contracts.

Verification passed with 45 focused PHP tests / 353 assertions and two
targeted mobile browser journeys. Blade cache, Pint and `git diff --check`
passed. No dependency or lockfile changed.

Implementation commit and push: `7382176 Extract observability dialog
components`.

Next task: convert observability alert-destination, status-page and
status-incident create/update forms into URL-backed reusable dialogs, then
continue with notifications, feedback, build notes, gallery actions and
cost/budget workflows.

## Observability administration dialog follow-up — 2026-09-19

Alert-destination creation, status-page creation, status-update creation and
per-incident status review updates now render through reusable scene
components: `scenes.observability.alert-destination-create-dialog`,
`scenes.observability.status-page-create-dialog`,
`scenes.observability.status-incident-create-dialog` and
`scenes.observability.status-incident-edit-dialog`.

The inventory keeps organization policy/entitlement gating, status-page
scoping and URL state. Form Requests, encrypted destination persistence,
incident kind/status compatibility, subscriber notification timing, response
messages and existing field names remain unchanged. Validation reopen markers
are scoped to the relevant create or incident-update dialog.

Verification passed with 29 focused PHP tests / 262 assertions, one fixture
test / 140 assertions and one targeted four-workflow mobile browser journey.
Blade cache, Pint and `git diff --check` passed. No dependency or lockfile
changed.

Implementation commit and push: `4e18d41 Move observability management forms
into dialogs`.

Next task: audit notifications and feedback for remaining add/edit forms,
then continue with build notes, gallery actions and cost/budget workflows.

## Follow-up Slice 13 — high-availability route dialogs

Status: complete locally and pushed to `main` in `063b95b`.

### Responsibility problem

High-availability route creation and application-node addition already used
URL-backed dialogs, but their form markup was embedded in the large inventory
view. That coupled route inventory rendering to two separate mutation forms and
made the dialog presentation difficult to reuse or test independently.

### Boundary and design decision

- Route creation now lives in `scenes.load-balancers.create-dialog`.
- Per-route application-node addition now lives in
  `scenes.load-balancers.node-dialog`.
- The inventory retains the URL/history state, node-management disclosure
  state, and per-route context, while the components own modal markup and
  existing request field rendering.
- No speculative load-balancer edit operation was introduced because the
  current application exposes creation, node management, apply and deletion,
  not a resource update contract.

Existing requests, policies, entitlement checks, actions, queued configuration,
remote cleanup, error status mapping and redirects remain the operation
boundary. This is a presentation-only extraction following single
responsibility without changing the remote workflow.

### Preserved contracts and safety guarantees

- Environment/server/hostname/health-path validation and node
  server/port/weight validation remain unchanged.
- Organization scoping, high-availability entitlement checks, self-routing
  restrictions, queue timing, failure handling and success messages remain
  unchanged.
- The dialog URLs, open-on-validation-error behavior, node disclosure state and
  no-JavaScript form fallback remain intact.
- The create and node forms use unique component-owned IDs while preserving
  existing request names and validation keys.

### Verification

- `LoadBalancerOperationsTest`: **5 tests / 41 assertions** passed under PHP
  8.5.10.
- Blade view cache, Pint and `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `063b95b Extract load balancer dialogs into
components`.

### Exact next task

Extract the existing domain-add and temporary-domain dialogs into reusable
components. Preserve website authorization, Cloudflare provider scoping,
temporary-domain configuration failures, DNS side effects and validation-error
reopening before moving to encrypted backup destination forms.

## Follow-up Slice 14 — domain dialogs

Status: complete locally and pushed to `main` in `5dd80bc`.

### Responsibility problem

Domain addition and temporary-domain issuance already used URL-backed dialogs,
but both forms were embedded in the domain inventory view. They have different
validation and remote DNS behavior, so keeping their markup in the inventory
made the page responsible for two separate mutation presentations.

### Boundary and design decision

- Domain aliases/redirects now render through
  `scenes.domains.add-dialog`.
- Temporary hostname issuance now renders through
  `scenes.domains.temporary-dialog`.
- The inventory retains modal URL state, workspace data and authorization
  gating; each component owns only its corresponding form presentation.
- The existing `StoreWebsiteDomainRequest`,
  `IssueTemporaryWebsiteDomainRequest`, website policy checks, Cloudflare
  provider scoping, actions, DNS jobs and response mapping remain unchanged.

This separates two cohesive UI responsibilities without merging operations
that have different side effects or failure semantics.

### Preserved contracts and safety guarantees

- Website selection, hostname normalization, alias/redirect rules, DNS
  provider selection and validation keys remain unchanged.
- Temporary-domain base-domain configuration errors, Cloudflare checks,
  generated hostnames, DNS side effects, proxy queueing and warning/success
  feedback remain unchanged.
- Dialog URLs, validation-error reopening and native form submission remain
  intact. The components use unique field IDs while preserving request names.

### Verification

- `DomainManagementTest`: **8 tests / 55 assertions** passed under PHP 8.5.10.
- Blade view cache, Pint and `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `5dd80bc Extract domain dialogs into
components`.

### Exact next task

Extract database credential issuance into a reusable component, then convert
backup destination create/edit forms to URL-backed server-rendered dialogs.
Keep encrypted credentials blank on edit, preserve safe validation failures,
and do not move backup verification or restore operations into the modal
boundary.

## Follow-up Slice 15 — database credential dialog

Status: complete locally and pushed to `main` in `4442977`.

### Responsibility problem

Database credential issuance already used a URL-backed dialog, but its markup
was embedded inside the database inventory loop. That left the inventory
responsible for a repeated mutation form and did not provide component-owned
accessible IDs for each resource.

### Boundary and design decision

- Credential issuance now renders through
  `scenes.databases.credential-dialog` for each managed database resource.
- The inventory retains resource-specific open/error state and management
  gating; the component owns only the credential form presentation.
- `StoreDatabaseUserRequest`, resource policies, entitlement checks,
  `CreateDatabaseUserAction`, queued management jobs, one-time password flash
  and response messages remain unchanged.

This is a focused add-operation extraction. Database clone, inspection and
credential-revocation workflows remain separate because they have different
confirmation, queueing and lifecycle semantics.

### Preserved contracts and safety guarantees

- Resource-scoped hidden identity, username/privilege/expiry field names,
  validation keys, authorization ordering and validation-error reopening remain
  unchanged.
- Generated passwords remain flashed for one-time display only; encrypted
  persistence and queue behavior are untouched.
- Component-owned field IDs improve label association without exposing new
  credential values or changing the existing no-JavaScript submission path.

### Verification

- Database and backup regression coverage: **14 tests / 106 assertions**
  passed under PHP 8.5.10.
- Blade view cache, Pint and `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `4442977 Extract database credential dialog`.

### Exact next task

Convert backup destination creation and editing from inline disclosure forms to
URL-backed server-rendered components. Preserve encrypted credential omission,
blank-on-edit rotation semantics, destination immutability safeguards,
provider guidance, verification actions and safe validation failure behavior.

## Follow-up Slice 16 — notification and feedback dialogs

Status: complete locally and pushed to `main` in `9780fef`.

### Responsibility problem

The notification saved-filter form and the feedback compose/review forms were
already URL-backed dialogs, but their markup lived inside the large page
templates. That coupled inbox/filter and feedback-list rendering to mutation
forms and made the same dialog presentation harder to reuse consistently.

### Boundary and design decision

- Notification saved-filter markup now lives in
  `scenes.notifications.save-filter-dialog`.
- Private feedback submission now lives in
  `scenes.feedback.compose-dialog`.
- Workspace feedback review/update now lives in
  `scenes.feedback.review-dialog`, rendered for each authorized feedback item
  on the list page.
- The pages retain URL state, filtering, list context and authorization
  decisions; the components own only their corresponding modal form markup.

This is a presentation boundary following single responsibility. No generic
form abstraction or new business service was introduced. Existing routes,
requests, policies, actions, encrypted storage and response behavior remain
the application boundary.

### Preserved contracts and safety guarantees

- Saved-filter names, active filter query parameters, validation reopening and
  saved-filter persistence remain unchanged.
- Feedback category, severity, title, description, reproduction and related
  page fields retain their names, limits, private-content warning and error
  behavior.
- Workspace review authorization still precedes validation and mutation;
  status, response, named marker and redirect behavior remain unchanged.
- Feedback review dialogs are only rendered for users who can review the
  workspace. Existing submitter/organization scoping and encrypted feedback
  persistence are untouched.
- Components are included by the page render itself, so opening a dialog does
  not require loading a second feature page or fetching a separate form.

### Verification

- Product feedback, notification bulk/inbox and local UI regression coverage:
  **39 tests / 555 assertions** passed under PHP 8.5.10.
- Browser fixture: **1 test / 140 assertions** passed.
- Targeted mobile browser journeys for feedback and saved filters: **2
  passed**.
- Blade view cache, Pint and `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `9780fef Extract notification and feedback dialogs`.

### Exact next task

Audit build-note, gallery, dashboard and cost/budget mutation forms. Extract
only real add/edit workflows into page-included URL-backed components, while
keeping script inspection, report resolution, deployment actions, imports,
restores and other long or protocol-sensitive workflows as explicit pages
when a modal would change ordering or failure semantics.

## Follow-up Slice 17 — dashboard, cost and promotion dialogs

Status: complete locally and pushed to `main` in `4a34ba0`.

### Responsibility problem

Dashboard preferences, the monthly infrastructure budget editor and tested
release promotion were URL-backed dialogs embedded in otherwise large
dashboard, cost and project-detail templates. Their inline markup made those
pages responsible for both the surrounding read model and separate mutation
presentations.

### Boundary and design decision

- Dashboard preferences now render through
  `scenes.dashboard.preferences-dialog`.
- Budget editing now renders through `scenes.costs.budget-dialog`.
- Tested-release promotion now renders through
  `scenes.projects.promotion-dialog`.
- The host pages retain open-state calculation, resource/query context and
  authorization gating. Components own only the modal form markup and its
  existing field rendering.

This is a small presentation-only boundary following single responsibility.
Promotion remains an explicit deployment operation; no business logic or
generic action abstraction was introduced.

### Preserved contracts and safety guarantees

- Dashboard widget names, hidden form marker, validation keys, preference
  persistence and redirect behavior remain unchanged.
- Budget limits, manager authorization, planning-threshold wording, numeric
  field attributes and update route remain unchanged.
- Promotion build identity, target options, release-note field, validation
  reopen behavior, tenant checks, approval gates, queue semantics and API
  behavior remain unchanged.
- Components are rendered as part of their host page, so opening a dialog does
  not load a second feature page.

### Verification

- Dashboard, cost and promotion regression coverage: **42 tests / 373
  assertions** passed under PHP 8.5.10.
- Blade view cache, Pint and `git diff --check` passed.
- No dependency or lockfile changed. No browser fixture currently covers
  these three dialog workflows; their existing feature tests remain the
  behavioral gate.

### Commit and push

Implementation commit and push: `4a34ba0 Extract dashboard cost and promotion dialogs`.

### Exact next task

Extract the remaining gallery and deployment-note dialogs into reusable scene
components. Preserve report visibility, script privacy, gallery publishing,
review-resolution semantics, Livewire state and deployment authorization.

## Follow-up Slice 18 — gallery dialogs

Status: complete locally and pushed to `main` in `0840b55`.

### Responsibility problem

Gallery publishing, on-demand script inspection, private report/update and
contributor resolution forms were spread across the gallery index, recipe
detail, reports inbox and a view partial. That made several large read views
own mutation markup and left the resolution dialog as a partial rather than a
reusable component.

### Boundary and design decision

- Gallery publishing now uses `scenes.gallery.publish-dialog`.
- Script inspection now uses `scenes.gallery.inspect-dialog`.
- Private report creation/update now uses `scenes.gallery.report-dialog`.
- Contributor resolution and resolution-note editing now uses
  `scenes.gallery.report-resolution-dialog` on both the recipe detail and
  feedback-inbox pages.
- The host pages retain route/query state, list context and authorization;
  components own modal markup and existing form fields.

The inspection component keeps its existing lazy content behavior: default
gallery cards render only the loading placeholder and fetch the public script
through the existing modal content endpoint when opened. No script data is
made available to unauthorized pages.

### Preserved contracts and safety guarantees

- Publish markers, recipe fields, validation keys, category requirements and
  redirect behavior remain unchanged.
- Script inspection remains limited to explicitly published recipes and does
  not expose private recipe content in the gallery index.
- Report identity privacy, report update semantics, encrypted details,
  contributor-only resolution, resolution-note behavior and reopen actions
  remain unchanged.
- Per-report URL keys, hidden report IDs, validation reopening, pagination and
  existing no-JavaScript form submissions remain intact.
- Components are included by every page that uses the corresponding dialog;
  opening a dialog does not load a second gallery feature page.

### Verification

- Gallery, report and feedback-inbox regression coverage: **53 tests / 575
  assertions** passed under PHP 8.5.10.
- Targeted browser journeys for gallery publishing/script inspection,
  private reporting and contributor resolution: **3 passed**.
- Blade view cache, Pint and `git diff --check` passed.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `0840b55 Extract gallery dialogs into components`.

### Exact next task

Extract the remaining deployment operator-note dialog into a reusable
component, preserving Livewire polling/state, named `buildNote` errors and
deployment authorization. Then re-audit for any non-component modal markup
before final verification.

## Follow-up Slice 19 — deployment operator-note dialog

Status: complete locally and pushed to `main` in `6d9966a`.

### Responsibility problem

The deployment detail Livewire view still owned the operator-note modal form
alongside polling, timeline and deployment controls. That coupled a small
add/edit presentation to the stateful deployment view and left the final
inline `<x-dialogs.modal>` outside the scene component convention.

### Boundary and design decision

Operator-note markup now lives in
`scenes.builds.operator-note-dialog`. The Livewire view retains the current
build, URL open state and surrounding deployment state; the component owns
only the note form presentation.

The component deliberately keeps `wire:ignore` because the existing modal
focus/close behavior must not be reset by deployment polling. No Livewire
state, route, action or validation behavior was moved.

### Preserved contracts and safety guarantees

- Add-versus-edit title, existing note value, clear-on-empty behavior and
  maximum length remain unchanged.
- The `buildNote` error bag, field name, update route, CSRF/method fields and
  authorization behavior remain unchanged.
- Deployment polling, timeline rendering, stale build protections and
  surrounding actions remain in the Livewire view.
- The component is included in the deployment page render and does not load a
  second build feature page.

### Verification

- Deployment note, history and comparison regression coverage: **26 tests /
  233 assertions** passed under PHP 8.5.10.
- Isolated mobile built-asset/Livewire sweep: **1 passed** across the full
  authenticated screen set in 1.8 minutes.
- Blade view cache, Pint and `git diff --check` passed.
- A final search found no non-component `<x-dialogs.modal>` or raw `<dialog>`
  markup under `resources/views`.
- No dependency or lockfile changed.

### Commit and push

Implementation commit and push: `6d9966a Extract deployment note dialog`.

### Exact next task

Run the final component audit and focused regression suite across all dialog
families, then update the handoff with the complete pushed commit sequence.

## Follow-up Slice 20 — legacy CRUD route alignment

Status: complete locally and pushed to `main` in `1466933`.

### Responsibility problem

The main inventory and detail pages already hosted reusable add/edit dialogs,
but several legacy `/create` and `/edit` routes still rendered their own
full-page forms. That left duplicate presentation paths and meant the same
provider, repository, recipe, server, website or project form could drift
depending on how it was opened.

### Boundary and design decision

The legacy route views now render the same page-included scene dialog
components with `open` enabled. Inventory and detail pages include those
components in their normal render and open them through the existing URL/query
state. Direct routes retain their existing breadcrumbs, route URLs and
no-JavaScript fallback while sharing the form markup and behavior.

The shared components accept only small presentation compatibility props where
needed: direct routes keep their established field IDs and server-edit title,
while inventory pages retain unique prefixes for accessible, collision-free
markup.

### Preserved contracts and safety guarantees

- Provider, repository, recipe, server, website and project validation rules,
  authorization, plan gates, prerequisites, old-input behavior and redirects
  remain unchanged.
- Existing secret-safe validation behavior remains intact; credentials are not
  exposed through the modal markup or flashed input.
- Existing field names, IDs, named error handling, flash messages,
  breadcrumbs and response routes remain compatible.
- All audited add/edit CRUD forms are rendered by reusable scene components
  included by the page that uses them; opening a dialog does not request a
  second feature page.
- Destructive confirmations, imports/restores, configuration/protocol
  workflows, security flows and page-level settings remain explicit inline or
  full-page workflows where a modal would change ordering, safety or clarity.

### Verification

- Focused provider, repository, recipe, project, website and server regression
  coverage: **79 tests / 560 assertions** passed under PHP 8.5.10.
- Complete PHP suite after the final code change: **1,641 tests / 13,696
  assertions** passed in 522.62 seconds.
- Blade view cache, full Pint, `git diff --check` and the production Vite
  build passed.
- The final view audit found **44** reusable scene dialog components and no
  non-component `<x-dialogs.modal>` or raw `<dialog>` markup under
  `resources/views`.
- Existing browser evidence remains valid: three gallery journeys, two
  feedback/notification journeys and one isolated mobile authenticated-screen
  sweep passed. Gallery script inspection remains intentionally lazy so
  sensitive script data is not included until explicitly requested.
- No dependency or lockfile changed. No production deployment, live
  acceptance drill or external cloud operation was performed.

### Commit and push

Implementation commit and push: `1466933 Render CRUD routes with shared dialogs`.

### Exact next task

Continue with normal product work from `main`; the modal modernization slice
is complete and the final handoff record is ready to be committed and pushed.

## Follow-up Slice 21 — dashboard-local creation dialogs

Status: complete locally, pushed to `main` in `577138f` and deployed to the
served development runtime.

### Responsibility problem

Dashboard create-server, add-website, provider, repository and application
links navigated to inventory or setup pages before opening their dialogs. That
lost the dashboard context and made quick actions feel like separate feature
pages. The dashboard also needed to preserve its setup-step behavior and
validation reopening when the dialog host moved to the dashboard.

### Boundary and design decision

`DashboardCreationDialogData` owns the workspace-scoped option queries needed
by the dashboard dialog host. The dashboard includes the existing reusable
provider, server, website, repository and application scene components and
opens them through dashboard-local query state. Shared components accept only
the small cancel-URL and validation-marker presentation inputs required by
both inventory pages and the dashboard.

Recipe update links also remain on the dashboard when opened from the gallery
summary. The selected recipe is loaded through the existing workspace scope
and policy, while the full server page remains the place that renders the
private recipe selector. This keeps private recipe names out of the dashboard's
always-rendered quick-create markup.

### Preserved contracts and safety guarantees

- Dashboard create actions open in place, and Escape/close returns to the
  dashboard URL state instead of navigating to an inventory page.
- Setup actions stay local when that setup step is currently actionable;
  future disabled steps remain disabled rather than becoming misleading
  links.
- Server and website validation failures redirect back to the dashboard with
  the relevant dialog query and reopen the submitted dialog. Existing field
  names, validation keys, policies, actions, flash messages and response
  routes remain unchanged.
- Provider, repository, project and recipe direct routes retain their original
  no-JavaScript fallback and cancel destinations.
- Workspace scoping, authorization, plan gates, eager option loading and
  secret-safe old-input behavior remain unchanged. No schema, dependency or
  queue serialization changes were made.

### Verification

- Focused dashboard and dialog regression coverage: **43 tests / 399
  assertions** passed under PHP 8.5.10.
- Complete PHP suite: **1,644 tests / 13,738 assertions** passed in 711.69
  seconds.
- Dashboard browser journey for page-local creation dialogs: **1 passed**.
- Blade view cache, full Pint, `git diff --check` and production Vite build
  passed.
- Served runtime smoke checks passed: both application services active,
  `/api/health` ready, login HTTP 200 and the expected local CSS asset served.
- No dependency, migration, credential or production-infrastructure change
  was made. External cloud acceptance remains separate.

### Commit and push

Implementation commit and push: `577138f Keep dashboard creation dialogs on
page`.

### Exact next task

Continue normal product work from `main`; dashboard quick-create dialogs are
now page-local and the served development runtime is on the implementation
commit.

## Follow-up Slice 22 — current-page New app footer action

Status: complete locally, pushed to `main` in `0131ee7` and deployed to the
served development runtime.

### Responsibility problem

The mobile footer's **New app** action and the equivalent command-palette item
were hard-coded to the Applications inventory route. That made a quick action
leave the current page before opening the application dialog. A shared layout
action also needs a dialog host on pages that do not otherwise render the
application inventory or dashboard component.

### Boundary and design decision

The authenticated layout now builds a route-local application dialog URL and
renders the existing `scenes.projects.create-dialog` component on pages that
do not already host it. The dashboard, applications inventory and direct
application-create route retain their existing page-owned component so dialog
IDs are not duplicated. The layout view composer supplies the existing
`ApplicationTemplateCatalog` definitions; no new business or persistence
abstraction was introduced.

The shared action intentionally uses the current route path plus the modal
state, rather than copying arbitrary query parameters into the rendered link.
This keeps the user on the same page without echoing malformed or sensitive
filter input. The command palette closes before opening the same modal.

### Preserved contracts and safety guarantees

- New app stays on the current page and opens the reusable application dialog;
  the dashboard and applications page keep their existing local hosts.
- Close/Escape returns focus to the originating footer trigger and removes only
  the modal state from the browser history.
- Application validation still returns to the dialog URL, and the existing
  policy, Form Request, action, field names, plan checks and success response
  remain unchanged.
- Direct `/projects/create` behavior is unchanged; no duplicate dialog IDs
  are rendered on routes that already include the application component.
- Invalid query values are not copied into global modal links or markup.
- No schema, dependency, credential, queue or production-infrastructure
  change was made.

### Verification

- Focused shared-layout/dialog regression: **49 tests / 692 assertions**
  passed under PHP 8.5.10.
- Complete PHP suite: **1,645 tests / 13,753 assertions** passed in 931.85
  seconds.
- Current-page footer and command-palette browser journey: **1 passed**.
- Blade view cache, full Pint, `git diff --check` and production Vite build
  passed.
- Served runtime smoke checks passed: both application services active,
  `/api/health` ready, login HTTP 200 and the expected local CSS asset served.

### Commit and push

Implementation commit and push: `0131ee7 Keep mobile New app on the current
page`.

### Exact next task

Continue normal product work from `main`; the mobile New app action no longer
navigates to the Applications inventory from another page.

## Follow-up Slice 23 — current-page create and edit links

Status: complete; implementation is pushed and deployed to the isolated
development runtime.

### Responsibility problem

Several contextual actions still sent users to a feature inventory before
opening the existing form: the command palette's server, website and
repository actions, provider/project/website detail-page creation actions,
the GitHub App repository action, and edit actions reached from repository,
deployment, gallery and feedback context. This broke the user's current
workflow and made related creation or editing depend on an unnecessary page
navigation.

### Boundary and design decision

The authenticated layout now hosts lazy provider, server, website and
repository creation shells on pages that do not already own the inventory
dialog. A small authenticated `CreationDialogController` returns only the
requested form body and reuses `DashboardCreationDialogData` for its existing
workspace-scoped options. Existing inventory and direct-create components
remain page-owned, so their IDs, eager option loading and query behavior are
unchanged.

Cross-resource links now point to the current page with URL-backed dialog
state. Repository and deployment pages host their linked website/repository
edit dialogs locally; gallery and report pages host the selected private
recipe editor locally. The modal loader reloads when a second trigger supplies
different environment prefill values. Direct current-page dialog URLs render
their form server-side as a no-JavaScript fallback.

### Preserved contracts and safety guarantees

- Imports, configuration authoring/review, destructive confirmations and
  other multi-step protocol workflows remain full-page or explicit where a
  modal would change ordering or safety.
- Existing policies, organization scoping, route bindings, validation keys,
  plan gates, form actions, flash messages, field IDs, old-input behavior and
  persisted values remain unchanged.
- Provider credentials and secret environment values are not added to shared
  markup or lazy endpoint responses beyond the existing edit/create form
  behavior.
- The lazy endpoint accepts only same-origin cancel URLs and returns a body
  view rather than a complete page. No dependency, schema, queue or external
  provider behavior changed.
- Nested “add provider/server/website” actions remain on the originating
  page, and no-JavaScript navigation still renders the corresponding form.

### Verification

- Focused current-page dialog coverage: **20 tests / 134 assertions** passed
  under PHP 8.5.10.
- Affected gallery, report, feedback and dialog regression set: **55 tests /
  590 assertions** passed after the final URL/filter compatibility fixes.
- Full built-asset/browser suite: **30 passed** across light/dark 320, 390,
  768 and 1440px layouts, dialog focus/scroll behavior, no-JavaScript
  provider submission and feature-specific modal workflows.
- Pint, Blade compilation, Vite production build and `git diff --check`
- passed. The complete strict PHP suite passed with **1,652 tests / 13,813
  assertions** in 814.60 seconds.
- Route and view caches were regenerated in the isolated runtime. Both
  `buildpusher-dev-main.service` and `buildpusher-dev-main-worker.service`
  are active; internal and public `/api/health` report `{"status":"ready"}`;
  `/login` returns HTTP 200 and the current local CSS asset is served.

This is local/runtime smoke evidence only, not the separate paid-cloud or
external acceptance drill.

### Commit and push

Implementation commit `62d06e7 Keep contextual CRUD actions on current pages`
was pushed to `origin/main` and deployed to
`/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`.

### Exact next task

Continue normal product work from `main`; keep standard contextual create/edit
actions on the current page and preserve the documented full-page workflow
exceptions. The separate live/paid-cloud acceptance drill remains outstanding.
