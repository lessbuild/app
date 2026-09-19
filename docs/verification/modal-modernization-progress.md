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

Status: complete locally and pushed in `be3ea3f`; the isolated development runtime is next to be updated and restarted at the pushed tip.

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
