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
