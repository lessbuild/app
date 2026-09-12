# BuildPusher SOLID refactoring progress ledger

## Phase 0 — isolated baseline

Date: 2026-09-12 UTC

Source checkout at start: `/root/Documents/Codex/2026-08-30/clone-my-repo-work-on-it/deployer`

- Starting source commit: `a137739` (`main`, matching `origin/main`).
- The source checkout had no tracked changes. Its only existing untracked change was `docs/solid-refactor-plan-2026-09-12.md`; that plan was copied into this worktree and remains untouched in the source checkout.
- Isolated worktree: `/root/Documents/Codex/2026-09-12/buildpusher-solid-and-laravel-refactoring-plan`.
- Branch: `refactor/solid-laravel-20260912`.
- No applicable project `AGENTS.md` exists; the only discovered file was under `vendor/` and was not applicable.
- Locked Composer dependencies were installed with PHP `/root/.local/share/buildpusher/php-8.5.10/bin/php` and an isolated Composer cache. `npm ci --no-audit --no-fund` completed successfully.
- The isolated `.env` uses `APP_URL=http://127.0.0.1:8092`, SQLite at `storage/database/refactor.sqlite`, file cache/session storage, an isolated session cookie, absolute cache paths under this worktree, and isolated backup/repository directories. A new local application key was generated; its value is not recorded.
- Runtime path assertions were run before Artisan: the resolved database, cache, session, filesystem, queue and Laravel cache paths all pointed into this worktree. Neither the live checkout database path nor the acceptance-drill database path existed.
- All 119 migrations completed successfully. `Database\Seeders\DemoSeeder` populated only the isolated SQLite database for browser checks.
- The isolated Laravel server serves successfully at `http://127.0.0.1:8092` when started with `--no-reload`; Laravel's reload-mode `serve` process intentionally passes only selected environment variables to its child, which omitted the isolated key and database settings.

### Baseline commands and results

| Check | Result |
| --- | --- |
| `php artisan test --fail-on-warning --fail-on-risky --fail-on-deprecation --fail-on-phpunit-deprecation --do-not-record-test-run-history` | Exit 2; 1,132 passed, 53 failed, 10,358 assertions across 1,185 tests. Failures clustered in rate-limited account/access/recipe/session/security flows and the testing-environment isolation test; no source changes had been made in this worktree. |
| `php vendor/bin/pint --test` | Passed. |
| `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:assets` | Passed: 9 browser fixture tests; isolated Vite build completed. |
| `BROWSER_BASE_URL=http://127.0.0.1:8092 BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npx playwright test tests/Browser/accessibility.spec.js` | 2 passed, 1 failed. The existing tablet check expects a `Search and navigate` button at 768px although that control is not rendered at that breakpoint. |
| Combined `npx playwright test` attempt against the isolated server | Not a valid clean baseline: the command omitted `BROWSER_PHP_BINARY`, so its asset fixture invoked system PHP 8.3; the visual audit then waited on an absent mobile `Settings` link and was interrupted. |

The full PHP suite and focused browser failures are recorded as pre-refactor baseline findings. They are not silently attributed to the refactor. The separate live acceptance drill and paid/cloud acceptance remain outstanding and were not modified or reused.

## Phase 1 — resource configuration slice

Responsibility problem: `ApplicationConfigurationReconciler::apply()` coordinated the transaction and ownership workflow while also constructing persisted resource configuration. That construction included organization-scoped, version-checked secret reads for external resources and managed MySQL/PostgreSQL/Redis/Valkey connection details for deployment snapshots.

Boundary used: `ApplicationConfigurationResourceConfiguration` now owns only that translation. `ApplicationConfigurationReconciler` still owns the reviewed environment loop, local writes, ownership claims, removal ordering, deployment intent identity, and transaction callback. The collaborator is concrete and constructor-injected; no speculative interface was added because there is one consumer and no real implementation variant.

Preserved guarantees:

- Secret version and scope checks still execute under the existing transaction and row locks.
- Existing encrypted resource configuration remains preserved when no replacement variables are declared.
- Managed credentials, deterministic Valkey ports/container names, deployment snapshots, no-op intent reuse, ownership recording, removal safeguards, queue timing and stale-attempt protection remain in the original orchestration path.
- No routes, validation keys, flash messages, YAML schema, persisted field or serialized payload changed.

Verification after the extraction:

- Configuration, operation and ownership suite: 178 passed (1,743 assertions).
- Pint: passed.
- The full-suite baseline remains 1,132 passed and 53 failed; the failures are recorded above and are unrelated to this slice.

## Phase 1 — single-environment reconciliation slice

Responsibility problem: after resource configuration was isolated, the reconciler still combined the per-environment state machine—environment attributes, processes, resources, variables, child removal, ownership claims and deployment intent reuse—with the review-level transaction coordinator.

Boundary used: `ApplicationConfigurationEnvironmentReconciler` now converges one environment and its owned children using the existing resource, variable and deployment collaborators. The outer reconciler retains immutable-review parsing and binding resolution, the transaction callback, cross-environment ordering, and project-wide environment deletion. The method explicitly documents that it must run inside the caller's transaction.

Preserved guarantees:

- Environment and child writes, ownership claims, secret revalidation, deployment snapshots and intent de-duplication execute in their original order and transaction.
- The existing project lock, review freshness checks, row locks, cancellation/retry behavior, and no-network transaction boundary remain unchanged.
- No route, request, YAML, persistence, serialization, queue or remote-execution contract changed.

Verification after the slice:

- Configuration, operation and ownership suite: 178 passed (1,743 assertions).
- Pint: passed.
- `git diff --check`: passed.

## Phase 2 — shared provider inventory query slice

Responsibility problem: `ProviderController` combined HTTP coordination with organization-scoped provider filtering and six independently derived inventory metrics. The same filter construction was also repeated by the private CSV export, making it easy for the HTML and export paths to drift.

Boundary used: `ProviderInventoryQuery` now owns the typed, organization-scoped provider query and its inventory metrics. The controller still normalizes request input, handles pagination/response construction, and formats CSV cells. The collaborator is concrete and constructor-injected; no generic repository or speculative interface was added.

Preserved guarantees:

- Workspace scoping still resolves through the current organization relationship.
- Search escaping, type/usage/connection filters, inventory scopes, six metric meanings, ordering, pagination, eager loading and export filtering remain unchanged.
- Provider tokens remain excluded from HTML and CSV output; encrypted persistence, entitlement checks, validation keys, flash messages and provider adapter behavior are untouched.

Verification after the slice:

- Provider inventory, export, insight and capability suite: 19 passed (149 assertions).
- Pint and PHP syntax checks: passed.
- `git diff --check`: passed.
- The broader provider baseline remains 62 passed and 3 failed in the pre-existing manual connection feedback/rate-limit tests; this slice does not alter that path.

## Phase 2 — provider connection-history query slice

Responsibility problem: `ProviderController` also combined provider-history filtering, retained-sample ordering and two different metric projections for the detail and history pages. Those semantics were duplicated around pagination and export queries.

Boundary used: `ProviderConnectionHistoryQuery` now owns provider-scoped history filtering, newest-retained sampling and observation metrics. The controller keeps request filter normalization, authorization, pagination and response/CSV rendering. The collaborator is concrete and constructor-injected because there is no real alternate history implementation.

Preserved guarantees:

- Provider relationship scoping, accepted result/source/date filters and `DateRange` behavior are unchanged.
- Newest-first ordering, `MAX_PER_PROVIDER` retention bounds, median/failure-streak calculations, empty-state values and latest timestamps remain unchanged.
- Authorization, route names, pagination parameters, CSV query bounds and sanitized cell formatting remain at their existing HTTP boundary.

Verification after the slice:

- Provider connection-history and insight suite: 9 passed (110 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 2 — provider inventory export slice

Responsibility problem: even after query extraction, `ProviderController::export()` still owned CSV protocol details, relationship projection and lazy iteration. That made the controller responsible for both HTTP coordination and a sizable inventory serialization workflow.

Boundary used: `ProviderInventoryExporter` now owns the streamed inventory response and consumes `ProviderInventoryQuery`. The controller retains filter normalization and delegates after the authenticated route boundary. The exporter is concrete and constructor-injected; no generic export framework was introduced.

Preserved guarantees:

- The filename, response headers, UTF-8 BOM, header order, provider ordering, lazy batch size, selected relationship columns and count aggregates are unchanged.
- CSV formula escaping, null handling, resource labels/counts, monitoring fields and credential exclusion remain unchanged.
- The export continues to use the same organization-scoped query as the HTML inventory page.

Verification after the slice:

- Provider inventory, filter and export suite: 11 passed (100 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 2 — provider connection-history export slice

Responsibility problem: `ProviderController::exportConnectionChecks()` still mixed the authenticated HTTP endpoint with CSV protocol, retained-history iteration and spreadsheet-safe field serialization.

Boundary used: `ProviderConnectionHistoryExporter` now owns the streamed history response and consumes `ProviderConnectionHistoryQuery`. The controller retains authorization and request filter normalization before delegation. The exporter is concrete and constructor-injected; it does not broaden the provider contract or expose credentials.

Preserved guarantees:

- The private response headers, filename pattern, UTF-8 BOM, header/row order and `MAX_PER_PROVIDER` bound are unchanged.
- Filtered newest-first history, result labels, provider metadata, endpoint/error escaping, null handling and timestamp serialization are unchanged.
- The controller's show/history routes still use the same policy checks and the same query collaborator as HTML responses.

Verification after the slice:

- Provider history, insight and inventory-export suite: 12 passed (158 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 2 — provider-management exit review

The provider read boundaries are complete. The existing `ProviderConnectionTester`, `ProviderHealthMonitor`, `ServerProviderResolver` and cloud adapters were reviewed against the provider requirements. No common adapter extraction was justified: DigitalOcean, Hetzner and Vultr use materially different endpoints, authentication/payload shapes, response normalization and idempotent-delete details. The existing `ServerProvider` contract and resolver already provide the useful dependency-inversion boundary, and the adapter tests cover the shared lifecycle expectations plus provider-specific behavior. The DigitalOcean connection probe remains the droplets endpoint rather than an account read.

Provider creation/update actions were also intentionally left in the controller. They are short HTTP-coordinating operations whose entitlement exceptions, policy authorization, validated request data and token-safe redirect/flash behavior are coupled to the Form Request boundary; extracting them would add an abstraction without separating a stable reusable business operation. Monitoring state transitions remain in `ProviderHealthMonitor`.

Verification after the complete read refactor:

- Exact provider-management regression command (`Provider*Test.php`, server lifecycle and source deployment): 61 passed, 4 failed (553 assertions). The four failures are in `ProviderConnectionTest`; the original `a137739` checkout reproduces the isolated first-test 429, and the remaining failures are the documented rate-limiter/manual-feedback baseline cluster.
- Expanded provider/cloud command including automatic monitoring, cloud adapter and DigitalOcean contract tests: 85 passed, 4 failed (704 assertions), with the same `ProviderConnectionTest` cluster.
- Full Pint: passed.
- The inventory/history focused gates remain green: 19 inventory/capability tests, 9 history/insight tests, 11 inventory/export tests, and 12 combined history/inventory export tests as recorded above.

Phase 2 exit conclusion: provider organization scoping, pagination, ordering, filters, metrics, CSV behavior, entitlements, encrypted credentials, manual monitoring, health leases/CAS, DigitalOcean droplets probing and cloud adapter behavior remain covered without public-contract changes. The separate live paid-provider acceptance remains outstanding.

## Phase 3A — recipe-report query slice

Responsibility problem: `RecipeReportsController` combined contributor/reporter's organization and ownership-scoped filtering, notification-backed unread predicates, ordering rules and report-history metrics with HTTP response coordination. The same report query semantics fed HTML, pagination and CSV paths.

Boundary used: `RecipeReportQuery` now owns contributor and reporter query construction, deterministic ordering, unread-update selection and the unread notification EXISTS predicate. The controller retains request normalization, authorization, pagination and response formatting. The collaborator is concrete and constructor-injected because the application has one report store and no alternate query implementation.

Preserved guarantees:

- Contributor ownership and reporter identity scoping, unpublished-history visibility, SQL-wildcard escaping, status/reason/date/age/focus/update filters and deterministic ordering remain unchanged.
- Unread notification category, notifiable identity, report-reference correlation, newest-per-report selection and review-update mutation scope remain unchanged.
- Eager-loaded columns, pagination, CSV filters, authorization, flash messages, notification timing, transactions and encrypted report fields remain at their existing boundaries.

Verification after the slice:

- `RecipeReportTest`: passed (14 tests).
- The focused aggregate command: 43 passed, 17 failed (498 assertions). The failures are the existing rate-limit cluster: two bulk-validation requests lacked the expected error bag after throttling, history review returned 429, and notification tests began with 429s causing dependent missing-record assertions. The extracted read paths themselves passed; no mutation code changed in this slice.
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — recipe-report export slice

Responsibility problem: the controller still mixed authenticated HTTP coordination with two sizable streamed CSV protocols, including headers, BOM handling, selected relationship columns, lazy iteration and spreadsheet-safe serialization.

Boundary used: `RecipeReportHistoryExporter` owns reporter-history CSV output and `RecipeReportInboxExporter` owns contributor-inbox CSV output. Both consume `RecipeReportQuery`; the controller retains request filter normalization and delegates after the existing authenticated route boundary. They remain separate because the two exports have different columns, visibility semantics and query projections.

Preserved guarantees:

- Filenames, response headers, UTF-8 BOM, header/row order, lazy batch size, query ordering, selected columns and relationship eager loading remain unchanged.
- Reporter ownership and unpublished-history visibility, contributor recipe scoping, formula escaping, null handling, timestamps, status labels and sensitive-field exclusion remain unchanged.
- No route, validation key, flash message, persistence, notification, transaction or provider behavior changed.

Verification after the slice:

- Export-focused report/history gate: 5 passed (85 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — recipe-report mutation validation slice

Responsibility problem: single-report submission and resolution-note endpoints kept reusable field rules and text normalization inside the controller, alongside authorization and locked state transitions. That made validated input harder to reuse and obscured the HTTP boundary without offering a safe reason to move business state changes.

Boundary used: `StoreRecipeReportRequest` owns report reason/details validation and `RecipeReportResolutionRequest` owns the shared resolution-note rule and normalization. The controller consumes `validated()` data and request accessors, while publication/authorship checks, contributor ownership checks, locks, transactions, notifications and activity recording remain explicit in the operation coordinator.

Preserved guarantees:

- Existing validation keys, max lengths, supported reasons, whitespace behavior, blank values, unauthenticated redirects and authorization status codes remain unchanged.
- Secret-safe encrypted persistence, SQLite writer reservation, recipe/report lock order, transaction rollback, notification timing and redirect flash messages remain unchanged.
- No route, payload, serialized value, YAML schema or mutation state transition changed.

Verification after the slice:

- `RecipeReportTest`: 14 passed (168 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — bulk report validation slice

Responsibility problem: the two bulk review endpoints embedded identical bounded-selection validation in the controller and selected their error bags through `validateWithBag`, alongside the atomic review workflows.

Boundary used: `ResolveRecipeReportsRequest` and `ReopenRecipeReportsRequest` now own the required array, cardinality, integer and strict-distinct rules. Each request retains its route-specific `bulkResolve` or `bulkReopen` error bag. The controller consumes only validated IDs and continues to own sorting, organization/recipe ownership checks, row locks, atomic state changes, notifications and audit events.

Preserved guarantees:

- Empty, oversized, duplicate and malformed selections produce the same validation keys and action-specific error bags.
- Missing/foreign report handling, transaction rollback, notification scope, audit timing, status messages and HTTP routes remain unchanged.

Verification after the slice:

- Bulk selection validation gate: 3 passed (15 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — single report submission action slice

Responsibility problem: `RecipeReportsController::store()` combined HTTP eligibility checks, SQLite writer reservation, locked create-or-update persistence, report lifecycle reset, contributor notification and audit recording.

Boundary used: `SubmitRecipeReportAction` now owns the reusable report submission operation and receives the reporter, recipe and validated attributes explicitly. The controller retains the pre-validation published/non-authorship HTTP guards and response flash; the action revalidates the locked recipe state and owns the transaction plus injected notifier/activity collaborators.

Preserved guarantees:

- The pre-validation 404/403 behavior, SQLite writer reservation, recipe/report lock order, create-versus-update semantics, resolved-state reset and notification deduplication remain unchanged.
- Notification and activity writes remain inside the existing transaction, with the same anonymous metadata and message wording.
- No route, validation key, persisted field, serialized value, YAML schema or queue behavior changed.

Verification after the slice:

- `RecipeReportTest`: 14 passed (168 assertions).
- New-report notification regression: 1 passed (11 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — single report resolution action slice

Responsibility problem: `RecipeReportsController::resolve()` combined immediate relationship authorization with a second locked authorization check, the resolve/no-op state machine, notification acknowledgement, reporter notification and audit recording.

Boundary used: `ResolveRecipeReportAction` now owns the locked resolve operation and receives the recipe, report, contributor and normalized note explicitly. The controller retains the immediate HTTP 404 relationship guard, Form Request validation and redirect response; the action rechecks ownership after locking and injects the notifier and activity recorder.

Preserved guarantees:

- Recipe lock then report lock ordering, stale relationship protection, idempotent repeated resolution, resolution-note persistence and transaction rollback remain unchanged.
- Contributor unread acknowledgement, reporter status notification, anonymous activity message, notification timing and flash text remain unchanged.
- No route, validation key, persisted field, serialized value, YAML schema or queue behavior changed.

Verification after the slice:

- `RecipeReportTest`: 14 passed (168 assertions).
- Resolution rollback/idempotency notification checks: 2 passed (18 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — resolution-note update action slice

Responsibility problem: `RecipeReportsController::updateResolutionNote()` combined locked ownership verification, the resolved-state precondition, unchanged-note detection, encrypted persistence, reporter notification replacement and audit recording.

Boundary used: `UpdateRecipeReportResolutionNoteAction` now owns that state transition and returns whether the note changed. The controller retains the immediate relationship guard, validated-note request boundary and existing flash-message mapping; the action receives typed models and the normalized note and injects notification/activity collaborators.

Preserved guarantees:

- Recipe/report lock order, stale ownership protection, 409 behavior for unresolved reports and unchanged-note no-op behavior remain unchanged.
- Encrypted note persistence, reporter notification replacement, activity wording/timing, transaction rollback and redirect flash messages remain unchanged.
- No route, validation key, persisted field, serialized value, YAML schema or queue behavior changed.

Verification after the slice:

- `RecipeReportTest`: 14 passed (168 assertions).
- Resolution-note rollback regression: 1 passed (5 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — single report reopen action slice

Responsibility problem: `RecipeReportsController::reopen()` combined locked relationship authorization, resolved-state idempotency, state reset, two notification transitions and audit recording.

Boundary used: `ReopenRecipeReportAction` now owns the locked reopen operation and receives typed recipe/report/contributor models. The controller retains the immediate HTTP relationship guard and redirect response; the action rechecks ownership after locking and injects notification/activity collaborators.

Preserved guarantees:

- Recipe lock then report lock ordering, stale ownership protection, resolved-state no-op behavior and clearing of `resolution_note` remain unchanged.
- Contributor notification reopening, reporter notification ordering, activity wording/timing, transaction rollback and flash messages remain unchanged.
- No route, validation key, persisted field, serialized value, YAML schema or queue behavior changed.

Verification after the slice:

- `RecipeReportTest`: 14 passed (168 assertions).
- Reopen notification/rollback gate: 2 passed (17 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — bulk report transition actions slice

Responsibility problem: `resolveMany()` and `reopenMany()` still combined validated selection handling with organization-scoped row locking, atomic state transitions, notification fan-out and per-recipe audit grouping.

Boundary used: `ResolveRecipeReportsAction` and `ReopenRecipeReportsAction` now own their distinct bulk workflows and return the changed count. The controller retains validated-ID normalization and HTTP status-message mapping; each action receives the contributor and IDs, performs its own transaction and injects the notifier/activity recorder.

Preserved guarantees:

- Sorted selection order, all-or-nothing contributor recipe ownership checks, row locks, rollback behavior and changed-count semantics remain unchanged.
- Resolve-only notification acknowledgement, reopen notification ordering, per-recipe audit grouping, resolution-note clearing, status labels and flash messages remain unchanged.
- No route, validation key, persisted field, serialized value, YAML schema or queue behavior changed.

Verification after the slice:

- Targeted bulk atomic/notification gate: 4 passed (30 assertions).
- Full `RecipeFeedbackInboxTest`: 21 passed, 1 failed (208 assertions); the single failure was the existing route throttle returning 429 before a later foreign-selection assertion. The same foreign-selection and rollback tests pass in isolation.
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — shared recipe-report lock collaborator slice

Responsibility problem: the individual report actions each carried a copy of the SQLite writer-reservation and recipe-row-lock implementation. That duplicated a concurrency-sensitive guarantee and made future mutation changes liable to diverge.

Boundary used: `RecipeReportLocks` now owns the concrete recipe lock protocol and is constructor-injected into the single-report actions. It deliberately has no interface because there is one persistence strategy and no alternate implementation; bulk actions continue to lock their selected report rows directly.

Preserved guarantees:

- The SQLite self-update reservation occurs before the recipe snapshot read, followed by the same `lockForUpdate()` query; no lock order or transaction boundary changed.
- Report create/update, resolve, note update, reopen, notification, audit, rollback and public HTTP behavior remain unchanged.

Verification after the slice:

- With `DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array CACHE_DRIVER=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`, the report regression classes passed separately: `RecipeReportTest` 14 (168 assertions), `RecipeFeedbackInboxTest` 22 (211), `RecipeReportHistoryTest` 7 (112), and `RecipeReportNotificationTest` 17 (117), for 60 passed (608 assertions).
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — report withdrawal action slice

Responsibility problem: `RecipeReportsController::destroy()` still combined the reporter-scoped locked lookup, notification cleanup, deletion and audit recording with HTTP response coordination.

Boundary used: `WithdrawRecipeReportAction` now owns the atomic withdrawal operation and receives the recipe and reporter explicitly. It reuses `RecipeReportLocks` and injected notification/activity collaborators; the controller only supplies the authenticated route models and returns the existing flash response.

Preserved guarantees:

- Recipe/report lock order, reporter scoping, notification deletion before report deletion, cascade-safe behavior, audit wording/timing and transaction rollback remain unchanged.
- Unpublished-report withdrawal, authentication behavior, route names, response status and flash text remain unchanged.

Verification after the slice:

- `RecipeReportTest`: 14 passed (168 assertions).
- `RecipeReportNotificationTest`: 17 passed (117 assertions), including withdrawal and rollback coverage.
- PHP syntax checks, Pint and `git diff --check`: passed.

## Phase 3A — recipe-reports exit review

Recipe Reports now has explicit query, export, validation, lock and mutation boundaries. The controller retains HTTP filter normalization, immediate relationship guards and response/flash mapping; extracted actions own cohesive transactions and continue to use the existing notifier/activity services. No generic repository, provider-style interface or speculative report abstraction was added.

The local report regression gates are green when run with the isolated PHPUnit-style in-memory database and array cache. The earlier aggregate failures caused by the cached disposable file and shared rate limiter are documented above; no production or acceptance-drill checkout was used. Live paid-provider acceptance remains outstanding and is unrelated to this slice.

## Phase 3B — website inventory query slice

Responsibility problem: `WebsitesController` combined HTTP coordination with organization-scoped website filtering and six independently derived inventory metrics. The same filtering logic was also embedded in the CSV export, allowing the HTML and export paths to drift.

Boundary used: `WebsiteInventoryQuery` now owns the typed, organization-scoped website query and inventory metrics. The controller still normalizes request input, authorizes resource routes, handles pagination and formats the CSV response. The collaborator is concrete and constructor-injected; no generic repository or speculative interface was added.

Preserved guarantees:

- Current-organization scoping, search escaping, status/health/attention/provisioning filters and six metric meanings remain unchanged.
- Listing ordering, eager loading, pagination, export ordering, repository counts, lazy batch size, CSV headers/BOM/escaping and sensitive-field exclusion remain unchanged.
- Website lifecycle jobs, placement/relocation behavior, encrypted environment persistence, validation, authorization, flash messages and provider behavior were not changed.

Verification after the slice:

- `WebsiteInventoryInsightsTest`: 3 passed (16 assertions).
- `InfrastructureListFilterTest`: 8 passed (62 assertions).
- `WebsiteInventoryExportTest`: 3 passed (44 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 3B — website inventory export slice

Responsibility problem: after the query boundary was extracted, `WebsitesController::export()` still owned the CSV protocol, relationship/count projection, lazy iteration and spreadsheet-safe serialization for website inventory.

Boundary used: `WebsiteInventoryExporter` now owns the streamed inventory response and consumes `WebsiteInventoryQuery`. The controller retains filter normalization and delegates after the existing authenticated route boundary. The exporter is concrete and constructor-injected; no generic export framework was introduced.

Preserved guarantees:

- Filename, private/no-store/nosniff headers, UTF-8 BOM, header order, website ordering, lazy batch size, server eager loading and repository count projection remain unchanged.
- CSV formula escaping, null handling, monitoring/health display values and exclusion of encrypted environments, database passwords, provisioning tokens and health errors remain unchanged.
- The HTML listing and CSV export continue to share the same organization-scoped filters, including invalid-filter normalization at the controller boundary.

Verification after the slice:

- `WebsiteInventoryExportTest`: 3 passed (44 assertions).
- Combined `WebsiteInventoryInsightsTest` and `InfrastructureListFilterTest`: 11 passed (78 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 3B — website health-history query slice

Responsibility problem: `WebsitesController` combined health-history filtering, retained-sample loading, median/failure-streak calculations and filtered summary projection with HTTP response coordination. The same semantics fed the website detail metrics, paginated history and CSV export.

Boundary used: `WebsiteHealthHistoryQuery` now owns website-scoped history queries, newest retained sampling and health metrics. The controller retains filter normalization, policy authorization, pagination and response/CSV coordination. The collaborator is concrete and constructor-injected because there is one health-check store and no alternate implementation.

Preserved guarantees:

- Website relationship scoping, accepted result/source/date filters and `DateRange` normalization remain unchanged.
- Newest-first ordering, `MAX_PER_WEBSITE` bounds, successful-duration selection, median rounding, failure-streak calculation, empty-state values and latest timestamps remain unchanged.
- Health-check creation, remote probing, retention deletion, authorization, response headers and CSV serialization remain at their existing boundaries.

Verification after the slice:

- `WebsiteHealthHistoryTest` and `WebsiteHealthInsightsTest`: 12 passed (117 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 3B — website health-history export slice

Responsibility problem: `WebsitesController::exportHealthChecks()` still combined the authenticated endpoint with CSV headers/BOM, retained-row iteration, formula-safe serialization and private response headers.

Boundary used: `WebsiteHealthHistoryExporter` now owns the health-history CSV protocol and consumes `WebsiteHealthHistoryQuery`. The controller retains policy authorization and filter normalization before delegation. The exporter is concrete and constructor-injected; it does not broaden the health-monitoring or provider contracts.

Preserved guarantees:

- Filename, private/no-store/nosniff headers, UTF-8 BOM, header/row order, newest-first ordering and `MAX_PER_WEBSITE` bound remain unchanged.
- Result labels, nullable fields, endpoint/error formula escaping and timestamp serialization remain unchanged.
- The export remains website-policy protected and uses the same filtered query semantics as the history page.

Verification after the slice:

- `WebsiteHealthHistoryTest` and `WebsiteHealthInsightsTest`: 12 passed (117 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 3B — website update action slice

Responsibility problem: `WebsitesController::update()` combined HTTP coordination with the locked website state machine: deployment/provisioning guards, health-state reset, relocation safeguards, provisioning identity rotation, log cleanup and after-commit dispatch.

Boundary used: `UpdateWebsiteAction` now owns that cohesive state transition and accepts a website plus validated attributes. The controller retains policy authorization, Form Request validation, monitoring entitlement enforcement and redirect mapping. Existing Web actions continue to own remote provisioning, retry recovery and former-placement cleanup; no duplicate remote abstraction was introduced.

Preserved guarantees:

- Locking and active-deployment/provisioning guards, validation keys/messages, health reset rules and release-retention no-op behavior remain unchanged.
- Relocation source retention, previous-placement safeguards, provisioning-token rotation, bounded log deletion, transaction scope and `AddWebsiteJob::afterCommit()` timing remain unchanged.
- Encrypted environment handling, safe URL/slug behavior, authorization, queue payloads and all public responses remain unchanged.

Verification after the slice:

- Website lifecycle, relocation, retention, monitoring, deployment-serialization and security suite: 38 passed (451 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Phase 3B — website logical deletion action slice

Responsibility problem: `WebsitesController::destroy()` combined policy-protected HTTP response mapping with the atomic logical-deletion operation: row locking, active-deployment protection and soft deletion. Remote cleanup is already isolated in `DeleteWebsiteFromCaddyJob` and `DeleteWebsitePlacementAction`.

Boundary used: `DeleteWebsiteAction` now owns the locked soft-delete operation and returns whether deletion was allowed. The controller retains policy authorization and the existing error/success redirects. Existing remote cleanup, retry and idempotency boundaries were reused without duplication.

Preserved guarantees:

- The website row lock, active-deployment check, transaction boundary and soft-delete result remain unchanged.
- Remote current/previous placement cleanup, failure restoration, cleanup ordering, queue identifiers, authorization and flash messages remain unchanged.
- No route, serialized job payload, persisted value, encrypted field or provider behavior changed.

Verification after the slice:

- Website deletion, relocation, deployment-serialization and security suite: 19 passed (178 assertions).
- Pint, PHP syntax checks and `git diff --check`: passed.

## Next task

Run the complete Phase 3B website regression gate, review the remaining straightforward CRUD/queue coordination against the existing Web actions, and close the Websites slice if no further stable boundary is justified.
