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

Status: implementation and verification complete; commit and push pending.

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

Pending final review, commit and push of this slice.

### Exact next task

After this slice is pushed and the isolated runtime is updated, inspect backup schedule and load-balancer node workflows. Convert only short create/add forms; preserve destination save-before-verify behavior, entitlements, organization-scoped lookups and remote-job semantics.
