# Modal modernization progress

## Slice 1 — shared modal foundation and domain workflows

Status: implementation complete; commit and push pending verification handoff.

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

Pending final review, commit and push of this slice.

### Exact next task

After the commit is pushed and the isolated dev runtime is updated, inspect organization invitations and feedback submission. Convert only the compact workflows whose validation, authorization and failure behavior can be preserved without hiding a long or destructive operation.

The broader modal modernization plan remains incomplete. Live acceptance and paid-cloud verification remain separate from this local slice.
