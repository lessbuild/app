# BuildPusher chat handoff

## Product expansion current checkpoint — 2026-09-13

The product-expansion sequence is active on `main`. Phase 6B's isolated
restore-verification execution slice is complete locally at commit `a9b8730`
after the backup-recovery characterization, read-only evidence summary and
Phase 5B read-only multi-target impact-preview slice. It was implemented in
the isolated clone `/tmp/buildpusher-product-expansion-uHhkwZ`, fast-forwarded
into canonical `main` and pushed to GitHub `origin/main`. Building on the Phase 3A
manifest, Phase 3B readiness states,
Phase 3C ownership-aware cleanup, Phase 3D organization-locked quotas,
Phase 3E initialization/credential boundaries and Phase 3F provider
observations, curated Laravel and generic Node presets now have a versioned
operational contract and new projects record the installed template version
without rewriting legacy rows.

Supported Laravel presets carry `php artisan db:seed --force` as an encrypted,
revision/attempt-bound preview payload. It runs once after the candidate release
is active within the existing post-deployment stage, writes a success marker
only after completion and remains retryable after interruption or failure.
Exact build/revision matching prevents stale callbacks from changing a newer
attempt. New preview-owned Valkey resources receive encrypted random passwords
and shell-escaped `--requirepass`; existing passwordful and legacy
passwordless resources preserve their current state. Generic Node previews now
inherit the selected source environment's runtime settings and compose the same
managed PostgreSQL/Valkey resources without Laravel workers or initialization.
The deployment plan still has 15 stages, queue dispatch remains outside the
transaction, and provider readiness is not inferred from local callbacks. The
published Laravel and Node templates are characterized against the shared
dependency, managed-resource and exact preview-cleanup paths. Template changes
remain reviewed deployments; there is no automatic version mutation. Mailpit
and other additional services are explicitly deferred because the existing
resource schema, provisioning, backup and cleanup lifecycle does not support
them yet.

The build detail page now has a plan-driven deployment timeline covering the
recorded request, conditional approval, deployment preparation, build,
application preparation, release activation, traffic routing, managed
resources, health verification and finalization milestones. It displays the
full immutable revision, requesting and approving/rejecting actor identity and,
for configuration-driven builds, the existing review/application/operation
identity and non-secret intent digest. The timeline does not invent timestamps:
only request, approval, release activation and finalization use persisted times;
other milestones explicitly show that no individual timestamp is recorded.
The existing setup-stage, log, approval, rollback, queue and callback behavior
is unchanged.

Automatic push deployments now have optional per-target include and exclude
path globs. GitHub and GitLab changed paths are bounded, normalized and retained
on webhook deliveries; missing or malformed path data, including current
Bitbucket push payloads, remains unknown and queues conservatively. A known
delivery outside the configured scope receives an explicit `skipped` history
status and creates no build or queue job. Exclusions win, shared dependency
paths must be listed explicitly for each target, and pending delivery path sets
are merged conservatively. Existing repositories default to no filters, so their
deploy behavior is unchanged. The repository form and history/dashboard views
explain and expose this outcome without rendering credentials or payloads.

Repositories now support an optional safe relative service root. New
non-default deployment payloads snapshot `repository_root`, and the existing
clone, checkout, dependency, build-hook, Artisan, canary, release, process,
runtime, Caddy, log, scheduled-task and restore paths use that service
directory. Rollbacks, previews and configuration identity preserve the root;
legacy builds fall back to the repository value. Blank/`.` roots retain the
old paths, and a release remains a whole checkout under the existing slug.
There is no shared-dependency inference, separate release-artifact model or
automatic cross-service orchestration. Website-level maintenance follows the
latest successful service deployment where available.

The repository inventory now links to a read-only **Deployment impact preview**.
It evaluates the existing pure path-impact rules across enabled push-webhook
targets in the selected workspace, accepts newline-delimited paths or an
explicit unavailable-path mode, and shows affected, unaffected and unknown
targets with service roots and bounded matched-path evidence. `viewAny`
authorization and eager-loaded tenant-scoped inventory protect the read; the
page creates no builds, deliveries, jobs, provider calls or persisted state.
Unknown path data remains conservative, and no shared-dependency inference or
cross-service orchestration was added.

Phase 6 characterization traced the managed website backup and restore
lifecycles. A completed `WebsiteBackup` records a Restic snapshot, size,
completion time and per-backup HTTPS transport evidence; that transport field
does not prove independent hosting or data integrity. `BackupRestore` records
only queued/running/succeeded/failed state, start/completion time and an error.
The existing restore is an in-place production restore with a remote safety
rollback and optional live health check, not an isolated restore test with
persisted integrity, application-smoke, recovery-stage or cleanup evidence.
The local SQLite control-plane backup/verifier is a separate recovery scope.
The current backups page previously derived summary metrics from only its latest
50 mixed-status rows and labeled a completed in-place restore as restore-drill
evidence. `BackupRecoveryEvidenceQuery` and immutable
`BackupRecoverySummary` now report completed backups, per-backup HTTPS
transport evidence, completed in-place restores and measured duration
separately. The page now offers a distinct, manager-authorized isolated
verification route. `RequestWebsiteBackupVerificationAction` binds an attempt
to an exact snapshot under a backup-row lock and dispatches after commit;
`VerifyWebsiteBackupJob` and `VerifyWebsiteBackupScript` persist bounded
integrity, Laravel smoke, failure-stage, duration and trap-backed cleanup
evidence. The supported path uses a temporary MySQL database and Restic
directory on the existing managed server, never touches live data or
maintenance, fails closed for unsupported target modes and offers a new retry
after failure. Existing in-place restore execution, destinations, overwrite
safeguards, job serialization and dispatch timing remain unchanged.

This first execution slice supports Laravel/MySQL service roots with a stored
managed-server MySQL root credential. PostgreSQL, external isolated targets,
arbitrary runtime smoke checks, scheduled restore drills and provider/cloud
acceptance remain separate work. No production resources or the acceptance
drill were changed.

The fresh isolated full PHP suite at the Phase 4B feature commit passed **1,374 tests /
11,885 assertions**, with the unchanged `ProvisioningHardeningTest` baseline
failure (4 `localhost` occurrences instead of the test's expected 3). Phase
4B focused catalog/project/runtime/preview coverage passed 41 tests / 372
assertions; the adjacent preview/resource/configuration batch passed 56 tests /
518 assertions. PHP lint, required-PHP Composer validation/platform checks, full
Pint, Vite build, config-cache create/clear and `git diff --check` passed. This
is local application evidence, not provider-side readiness, cloud lifecycle or
the separate live drill. The Phase 4C lifecycle characterization passed **3
tests / 57 assertions**; the adjacent service-template, release, PostgreSQL
resource, preview cleanup, project-creation and preview-deployment batch passed
**41 tests / 408 assertions** with the supported isolated array session driver.
The Phase 5A timeline/history/log batch passed **16 tests / 134 assertions**.
The fresh full suite at `b5d1cab` passed **1,383 tests / 11,979 assertions**,
with the same unchanged `ProvisioningHardeningTest` `localhost` count failure.
The Phase 5B path-filter/evaluator/webhook/history/dashboard batch passed **57
tests / 976 assertions**. The per-service repository-root/deployment/preview/
rollback/backup/hooks/runtime/domain/security batch passed **60 tests / 586
assertions**. The fresh full suite at `72d7c69` passed **1,396 tests /
12,086 assertions**, with the same unchanged `ProvisioningHardeningTest`
`localhost` count failure. Required-PHP Composer platform checks, full Pint,
Vite asset build and `git diff --check` passed. The preview feature suite
passed **4 tests / 24 assertions**; the repository/deployment regression batch
passed **61 tests / 524 assertions**. The fresh full suite at `3940a28` passed
**1,400 tests / 12,111 assertions**, with the same unchanged
`ProvisioningHardeningTest` `localhost` count failure. Full Pint, changed-file
PHP lint, route registration and `git diff --check` passed. No frontend assets
changed. The new recovery-evidence feature plus managed-backup/release-audit
regression set passed **12 tests / 120 assertions**. The fresh strict isolated
full suite at `a9b8730` passed **1,408 tests / 12,199 assertions**, with the
same unchanged `ProvisioningHardeningTest` localhost-count failure. The
focused verification/recovery/managed-backup set passed **13 tests / 132
assertions**. Required-PHP Composer validation/platform checks, changed PHP
lint, full Pint, Vite, route registration, `git diff --check` and the
required-PHP asset/browser suite (**9 passed**) passed. The current exact next
task is Phase 7 inventory: connect environment, deployment, logs, health and
incidents through bounded, authorization-checked reads; provider/cloud
acceptance and the separate live drill remain outstanding.
The progress ledger is [here](verification/product-expansion-progress.md), the
template contract is [here](service-templates.md), and the roadmap is [here](NEXT_ROADMAP.md). Older handoff entries below are historical and are superseded by this checkpoint.

## Controller modernization current checkpoint — 2026-09-12

The controller modernization plan was executed through its local completion
gate on `main`. The implementation slices were merged fast-forward from the
temporary refactor branch and pushed after each commit. The current source
checkpoint is `94d361c`, followed by the verification-ledger documentation
commit `969e254`; this handoff update is the next documentation commit. The
user's untracked `docs/controller-modernization-luna-max-plan.md` remains
preserved and unstaged.

The refactor now uses concrete Form Requests, policies/gates, cohesive actions
and existing query/provider/service collaborators across the inventoried
controller areas. Direct controller writes and inline controller validation
were removed where a concrete responsibility boundary existed. Protocol-safe
callback validation, resource lookup guards, workflow-state checks and
integration availability checks remain at their required execution points.

Final local verification on isolated runtime settings:

- Full PHP suite: **1,320 passed / 11,411 assertions**.
- Full Pint, Composer validation/platform checks, Vite build and
  `git diff --check`: passed.
- `npm audit --audit-level=high`: **0 vulnerabilities**.
- Built-asset browser suite: **9 passed**, including no-JavaScript provider
  submission.
- Cached-route/seeded disposable HTTP runtime and versioned Livewire asset:
  **1 passed**.
- Accessibility browser check: **2 passed, 1 failed** on the unchanged tablet
  focus expectation for the absent `Search and navigate` button at 768px;
  mobile and desktop passed. The broad visual audit remains outstanding for
  the unchanged missing mobile Settings link.

The live paid-provider/cloud drill, deployment and external acceptance remain
separate outstanding work. No production resources, billing, credentials or
the acceptance-drill checkout were modified. See the [controller modernization
ledger](verification/controller-modernization-progress.md) for the per-slice
boundaries, contracts, commits and exact verification record.

## SOLID refactoring current checkpoint — 2026-09-12

The ordered BuildPusher SOLID/Laravel refactor is complete through the local Phase 4 verification gate in the isolated worktree `/root/Documents/Codex/2026-09-12/buildpusher-solid-and-laravel-refactoring-plan`, branch `refactor/solid-laravel-20260912`, based on commit `a137739`. The live checkout and the separate acceptance-drill checkout were not modified.

Completed cohesive slices cover configuration-as-code, provider management, recipe reports, websites, builds/repositories, and servers/environments. Controllers now coordinate HTTP boundaries while concrete query/export collaborators, actions and Form Requests own the extracted cohesive responsibilities. Existing policies, organization scoping, locks, transactions, leases, after-commit dispatches, provider contracts and queue semantics remain in place. See the [SOLID progress ledger](verification/solid-refactor-progress-2026-09-12.md) and [final verification record](verification/solid-refactor-verification-2026-09-12.md).

Final local verification:

- Full PHP suite with isolated SQLite/array overrides: **1,185 passed / 10,743 assertions**.
- Pint, changed-file PHP syntax, `git diff --check`, Composer validation and platform requirements: passed.
- Production Vite build and isolated asset browser suite: **9 passed** across light/dark mobile/tablet/desktop layouts, keyboard navigation, focus/Escape behavior and no-JavaScript provider submission.
- Cached Laravel runtime and real Livewire browser smoke: **1 passed**; the versioned Livewire asset returned HTTP 200 with JavaScript content type.
- Accessibility smoke: mobile and desktop passed; the unchanged tablet test still expects a `Search and navigate` button at 768px where the current UI does not render it. The broad visual audit was also not claimed as passed because its unchanged mobile `Settings`-link expectation was absent and the crawl was stopped.

Remaining work is external acceptance: the paid-provider/cloud drill and deployment have not been run. Do not treat this local verification as live acceptance, and do not copy credentials or alter production/billing/cloud state. The older handoff entries below are historical context.

## Isolated live-drill preparation — 2026-09-08

DigitalOcean provider ID 6 works for the required read preflight. The £10 total cap and deletion of all drill-created resources remain authorized. Fresh source-control checks returned HTTP 401 for GitHub IDs 2/5, GitLab ID 3 and Bitbucket ID 4. The user has been asked to connect one working source-control credential and name a disposable repository; never request the token in chat.

The live Free workspace already has five server records against a limit of one and lacks backup/resource entitlements. Do not change its subscription, remove existing resources, or disable its billing enforcement to run the drill. Instead an isolated detached checkout at `/root/.local/share/buildpusher/drill-20260908/app` is prepared at code commit `c1105b8`, with independent vendor/assets/storage, a new application key, its own SQLite database and test-only billing flags. It contains zero provider credentials and zero queued jobs. All 119 schema migrations passed; runtime assertions verified its code and database resolve inside the isolated checkout. Local HTTP readiness, login and homepage returned 200; the temporary localhost web process was stopped.

The isolated instance is not yet exposed for remote callbacks and has no cloud worker running. Do not create paid resources before completing its callback, source-repository and cleanup prerequisites. No cloud resources have been created and spending remains £0. See [the preparation record](verification/isolated-drill-preparation-2026-09-08.md).

## Scoped DigitalOcean connection check — 2026-09-08

The user's newly added DigitalOcean connection (provider ID 6) has a working scoped token. Real GET requests to droplets, sizes and SSH keys returned HTTP 200; account details alone returned HTTP 403. The old connection (ID 1) must not be confused with this new credential. Existing provider resources were observed and must not be altered by the disposable drill.

The connection tester now uses `GET /v2/droplets?per_page=1` instead of requiring account-details access. Its recorded endpoint matches the request. The new regression fails against the old account check and passes after the correction; connection/monitoring suites pass **17 tests / 221 assertions**. The actual application tester returned success/HTTP 200 with the new saved token. This validates read access, not untested create/delete permissions. No cloud resources were created and the authorized £10 budget is unspent.

The previous credential-401 checkpoint is historical. Resume the authorized drill with provider ID 6, a fresh resource inventory, bounded costs and cleanup of only drill-created resources. No further budget or cleanup confirmation is needed.

## Provider submission feedback correction — 2026-09-08

The user's continued silent reload had a separate cause from the JavaScript failure: credential monitoring was checked by default even for Free workspaces, and the controller's `plan` validation error was not rendered by the form. Live read-only inspection confirmed the workspace is Free, monitoring is unavailable and entitlement denials had occurred.

New-provider defaults and the rendered checkbox now respect monitoring entitlement. A Free workspace can save a provider with monitoring off; manual connection tests remain available. A shared provider error summary displays all validation errors, including `plan`; create/update redirects now flash success. Tokens are excluded from flashed validation input. Explicit requests for unauthorized monitoring still fail rather than bypassing billing enforcement.

Regression coverage includes the real POST and subsequent GET with the same session cookie, Free and entitled defaults, visible plan/field errors, success feedback and token non-disclosure. See [the verification record](verification/provider-submission-feedback-2026-09-08.md). No live provider credentials or billing settings were changed.

## Mobile navigation and provider form repair — 2026-09-08

The live route cache still registered old `/livewire/...` endpoints while pages emitted Livewire 4's `/livewire-75af7612/...` URLs. The actual JavaScript request returned HTTP 404, preventing Alpine navigation and provider selection from initializing. The old route cache was backed up outside the repository and rebuilt with PHP 8.5; the served runtime now returns HTTP 200. The signed-in navigation fixture, using the live runtime, passes open/close, Escape, focus restoration and scroll-lock checks at 320/390/768px.

Provider selection now uses native, required radio controls rather than Alpine-only divs and a hidden input. Provider identities, icons, edit selection, validation and encrypted-token storage remain intact. The mobile browser test submits the selected provider with JavaScript disabled; eight provider capability tests / 49 assertions pass. The live public runtime/menu smoke test passes. See [the runtime regression instructions](verification/mobile-navigation-provider-form-2026-09-08.md).

The DigitalOcean drill remains authorized under the £10 limit below. This UI repair did not replace or test a newly supplied credential, create cloud resources or spend money.

## DigitalOcean drill authorization and credential preflight — 2026-09-08

The user explicitly authorized the connected DigitalOcean account, a maximum **£10 total spend**, and deletion of all resources created for the test afterward. This authorization persists; do not ask again for the provider, budget or cleanup permission. Existing unrelated resources must remain untouched.

The connected DigitalOcean provider was found, but fresh authenticated GET requests to `/v2/account`, `/v2/droplets`, `/v2/sizes` and `/v2/account/keys` each returned **HTTP 401**. The saved healthy label is historical and does not prove the credential works now. No token or response body was printed, no resources were created, and no spend occurred. The user must refresh the credential in BuildPusher's provider settings; then rerun preflight, inventory existing resources, select and bound the test cost, and execute the already prepared configuration acceptance drill. Earlier requests below for target/spend limits are superseded; the current missing prerequisite is a working credential.

## Configuration rollout — 2026-09-08

The user resumed the feature sequence after accepting modernization, starting with the proposed configuration rollout. The six configuration migrations are now applied to the live SQLite database. Consistent private backups, a rehearsal on a database copy, rollback/reapply, integrity/foreign-key checks and hashes of all 71 existing tables establish data preservation. Live readiness now returns HTTP 200 with `status: ready`; maintenance is disabled and the worker/original timers are restored.

A missing configuration-delivery runner was found and corrected: the daemon installer now provisions `lessbuild-configuration.service` and its minute timer. The same generated units are installed on this host, use PHP 8.5, skip maintenance and have completed an empty-operation pass successfully. See [the rollout verification record](verification/configuration-rollout-2026-09-08.md).

The current feature's remaining live deployment drill still needs a disposable provider/server and explicit spending limits. An asynchronous question requests these from the user. Do not create paid resources without that information or move to preview environments while this gate remains unresolved. The [configuration-specific live drill](real-provider-acceptance.md#configuration-as-code-acceptance-drill) now specifies the required evidence for review/apply, idempotency, stale/foreign rejection, cancel/retry, remote failure recovery and whole-environment removal; the generic acceptance audit alone does not cover those paths. Earlier statements that the six migrations are pending are historical and superseded by this checkpoint.

## Latest-compatible dependency acceptance — 2026-09-08

The user explicitly accepted latest-compatible dependencies after the all-latest upstream conflict was explained. This supersedes the literal all-latest blocker in historical checkpoints below. Preserve the existing integrations and upstream constraints; unsupported overrides or replacements are not required. See [the acceptance verification record](verification/modernization-accepted-2026-09-08.md) for the refreshed lockfiles and final verification status. The modernization is complete under that accepted requirement: **1,178 tests / 10,705 assertions**, all 207 Unit/Feature files, 48 browser layouts, formatting, type/documentation audit, route caching, dependency resolution and security checks passed. Publication remains authorized; inspect Git history for the published checkpoint.

## Live PHP runtime repair — 2026-09-08

The reported Composer platform error was reproduced on the live login page: Caddy still used PHP 8.3 after the dependency upgrade. BuildPusher now has an isolated PHP 8.5.10-FPM service, and its existing worker/timer service commands use the matching PHP 8.5 CLI. See [the runtime repair record](verification/php-runtime-repair-2026-09-08.md) for host configuration and verification.

Login, public pages and the authentication redirect work again. Composer production platform requirements pass in the CLI and the actual FPM bootstrap. The readiness endpoint still returns HTTP 503 because the six previously deferred configuration migrations remain pending. No database migrations or new configuration operations were run. This runtime repair supersedes earlier statements that BuildPusher's live services had not been switched to PHP 8.5; system PHP and other applications remain unchanged.

## Queue correctness and dependency blocker — 2026-09-07

The follow-up after `abdd8df` fixes fail-fast load-balancer removal and manual provisioning command dispatch/lookup behavior. **107 tests / 946 assertions** across four relevant suites passed, as did scoped formatting and the signature/documentation audit. See [the requirement and verification record](verification/modernization-remaining-requirements-2026-09-07.md).

The full modernization goal is **blocked, not complete**. The same official-release constraint has persisted across three goal checkpoints: latest Laravel 13.30.1 and Ramsey UUID 4.9.3 still reject latest Brick Math 0.20.0. Fresh registry and Composer checks confirm it. The named refactor/documentation work and the concrete correctness follow-ups are implemented and verified; local edits cannot make the official all-latest graph resolvable. Resume dependency work when upstream constraints change or the user explicitly changes that requirement. Do not silently substitute latest-compatible completion.

Publication remains authorized. Inspect Git history/remote state for the published checkpoint. No deployment, real migration, cloud operation or next-roadmap feature was performed.

## Documentation and authentication checkpoint — 2026-09-07

The follow-up to `ed9182c` completes the missing method documentation and fixes a recovery-code consumption race. See [the verification record](verification/method-contracts-and-recovery-codes-2026-09-07.md). Publication to GitHub remains authorized; inspect the final commit and remote state for its exact publication identity.

- All **1,491 class methods across 423 app PHP files** now have PHPDoc, with zero missing native parameter/return types (constructor/destructor returns excluded). Added 851 docblocks and expanded 40 existing contracts; corrected the recipe-validation return annotation to admit its rule objects. Existing comments and executable behavior were preserved apart from the explicit authentication fix.
- Consuming recovery-code verification now returns the locked check/removal result, so two stale user instances cannot both accept one code. The regression failed against the old implementation; four authentication suites pass **21 tests / 157 assertions** after the fix. The preceding full-suite/browser results below were not rerun wholesale for this follow-up.
- The [dependency feasibility audit](dependency-latest-blockers-2026-09-06.md) independently confirms that all-latest official stable dependencies remain impossible under current Laravel/Ramsey, OAuth, Ignition and frontend-tool constraints. The broader modernization goal remains active; do not equate newest-compatible locks with literal all-latest completion.
- No dependency manifests, lockfiles, deployment configuration or database schema changed in this follow-up. No live migrations, paid-provider actions or deployment occurred. The CI template remains inactive pending GitHub workflow permission.

## Modernization checkpoint — 2026-09-06

Configuration as code was completed and published in `b6ee620`. The subsequent modernization refactors and dependency upgrades are verified; see [the implementation and verification record](laravel-modernization-2026-09-06.md). Inspect Git history for the publication commit rather than treating historical uncommitted-work notes below as current.

- Full suite: **1,165 tests / 10,643 assertions**, all 205 Unit/Feature files, zero failures/errors/skips/warnings/deprecations. Browser coverage: **48 layouts**. Formatting, route caching, clean asset build and dependency audits passed.
- Laravel 13 / Livewire 4 / PHP 8.5 / PHPUnit 13 / phpseclib 4 / Tailwind 4 / Vite 8; newest compatible dependencies are locked. Six PHP packages and several npm transitive dependencies retain documented upstream constraints. See the PHP/frontend records linked from the implementation record.
- Dedicated callback controllers, model bindings and ownership guards, all 14 scopes extracted, separate presenters, domain enums preserving string APIs, named billing listener, native method types and model documentation, shared CSV/IP helpers, receipt/status/history query improvements, callback concurrency/numeric-string fixes and revoked-session redirect compatibility are implemented.
- Existing comments/code were preserved or moved with their implementation. No `strict_types` declarations were added. The September 7 follow-up above closes the remaining PHPDoc coverage gaps.
- PHP 8.5 is required before deploying this checkout. The system PHP/FPM/services were not changed. Session serialization and browser/runtime prerequisites are documented. The six configuration migrations remain a separate rollout requirement; no paid-provider actions, real configuration operations or persistent-database migrations were run.
- GitHub publication is authorized by the user's push request. Publication does not deploy the application. No preview-environment backlog feature was started.
- GitHub refused the active CI workflow because the connection lacks `workflow` scope. Its complete definition is published as the inactive `docs/ci/verify.yml` template. The original commit with the active workflow is preserved on local branch `local/modernization-with-ci-20260906`; activation instructions are in `docs/ci/README.md`.

## Continuation checkpoint — 2026-09-06

This section supersedes the interruption and remaining-work lists in the historical handoff below. The continuation preserved all existing tracked/untracked changes and stayed on configuration as code.

Publication note: the user subsequently requested publication to GitHub `origin/main`. Statements below about uncommitted or unpushed work describe the verification checkpoint before that request; inspect Git history and remote status for the current publication state. Database migration and deployment remain separate.

- Whole-environment removal is implemented and verified through service, web and API workflows, including complete child plans, ownership/dependency guards, stale review/access rejection, rollback, retries, preserved remote targets/build history, and active-preview exclusion.
- Added explicit operation retry/cancel controls, durable retry history, current receipt status, semantic deployment deduplication and checks immediately before remote start. Failed operations are not silently rerun; stale pending operations can be canceled without touching saved configuration or remote services.
- Added SQLite write reservations and independent-process transaction races; fixed migration 050000 rollback to remove its index before the column. Migration 060000 adds retry identity and refuses lossy rollback after retry history exists.
- Hardened YAML parsing before expansion, runtime/type/name validation, managed/external resource handling, managed credential freshness and captured base-environment secrets. Symfony YAML is now a production dependency, and build payloads are hidden from model serialization.
- Updated the complete operator contract and OpenAPI document. The contract is `docs/application-configuration.md`; it contains the syntax, exact safety boundaries, recovery actions and rollout guidance.
- The six configuration migrations remain pending in the working application's database. No real configuration operations were processed, no infrastructure was provisioned, and no push/deployment was performed.
- Verification includes **1,060 tests / 10,179 assertions**, zero failures/errors/skips, real SQLite process races, migration rollout/rollback, web/API workflows, 24 rendered layouts covering upload/history, review and receipts, a production asset build and production-dependency dry run. See [the final verification record](verification/application-configuration-2026-09-06.md).
- Configuration as code is locally complete. No preview-environment backlog work was started. Keep the release/rollout boundaries in the verification record explicit before any deployment.

The material below preserves the original interruption context and prior visual requirements. It is historical; do not redo the now-finished removal/recovery work based on those earlier gap lists.

Prepared 2026-09-06 when the user requested a new chat with continuity. This is a working checkpoint, not a completion or release claim. Reinspect the current worktree before relying on it.

## Start here

- Actual repository: `/root/Documents/Codex/2026-08-30/clone-my-repo-work-on-it/deployer`.
- The previous chat's default directory was `/root/Documents/Codex/2026-09-05/go-to-the-deployer-folder`, which is NOT the repository. Use the actual repository explicitly.
- Product: BuildPusher, a Laravel deployment/infrastructure application, with Blade, Alpine/Livewire, Tailwind and Vite.
- Read this note, `docs/NEXT_ROADMAP.md`, `docs/application-configuration.md`, applicable `AGENTS.md` files, and `git status --short` before changing code.
- Preserve all existing tracked and untracked work. Do not reset, clean, or reclone over it. There is no need to push merely to continue in the same local folder.

## User priorities and constraints

The ongoing objective is: “I want you to work on these features. Don’t move to the next feature until the current feature has been maxed out.”

- Complete and verify the current feature before advancing the backlog. Narrow green tests do not establish feature completion.
- The ordered backlog is in `docs/NEXT_ROADMAP.md`: acceptance-audit correctness, configuration as code, complete preview environments, curated service templates, interactive troubleshooting.
- Acceptance-audit correctness has a local implementation/verification checkpoint. Configuration as code is the CURRENT feature and remains incomplete. Do not jump to previews.
- The live paid-provider release drill and other documented release gates remain deferred until release. Do not spend money, create paid infrastructure, activate billing or claim live verification without the required authorization.
- Recent explicit visual requests take priority when the user returns to them: Payeio-style mobile navigation, a charcoal header matching dark panels, and a full-width footer flush with the bottom.
- User asked for GitHub pushes earlier in the larger conversation, but the recent navigation/configuration work has NOT been pushed or deployed. Do not imply otherwise.
- The most recent request was to prepare this handoff, not continue implementing features in the old chat.

## Git checkpoint

Read-only checks at handoff showed branch `main`, one commit ahead of the locally cached `origin/main`. No fetch was performed for this handoff.

- HEAD `22215e5` — Verify coherent release drill evidence and preserve backup transport history.
- Previous `33e4af7` — Tighten release evidence checks and prioritize next development phase.
- Previous `fe500d4` — Expand deployment platform and production readiness.

Substantial work is uncommitted, including nearly all configuration-as-code services, models, migrations, tests, documentation and the mobile-navigation component. Tracked edits include routes, controllers, the scheduler, application layouts, dashboard and dashboard tests. `git diff` alone omits new untracked files: inspect them too.

## Latest completed local UI work

- `resources/views/components/layouts/sidebar.blade.php`: desktop-only sidebar, `desktop-navigation` ID.
- `resources/views/components/layouts/mobile-navigation.blade.php`: separate full-screen mobile menu, `primary-navigation` ID, focus trap/scroll lock, search, current workspace card, two-column navigation tiles, settings/support group, logout, close/Escape and desktop-resize handling.
- `resources/views/components/layouts/app.blade.php`: mobile brand/Menu header; charcoal `bg-gray-800` header and readable controls; full-width bottom quick-action bar using `inset-x-0 bottom-0`, safe-area padding and page-end clearance. Desktop header also charcoal. Footer no longer floats with outer margins or rounded outer corners.
- `resources/views/dashboard.blade.php`: welcome/workspace heading card inspired by Payeio.
- Latest UI verification BEFORE the subsequent unfinished configuration-removal changes: `php artisan test tests/Feature/DashboardTest.php --stop-on-failure` passed **21 tests / 217 assertions**; `npm run build` passed.
- Playwright rendered a real test-generated dashboard with built CSS/Livewire, and checked light/dark layouts at widths 320, 390 and 768: footer spans the viewport and touches the bottom, final page links are not covered, no horizontal overflow, header is `rgb(31, 41, 55)`. Open-menu charcoal header, Escape closing, desktop sidebar visibility and no JS errors were also checked.
- Temporary screenshots existed at handoff: `/tmp/buildpusher-charcoal-header-footer.png`, `/tmp/buildpusher-charcoal-menu.png`, `/tmp/payeio-mobile-menu.png`. Temporary files may disappear; they are not durable release evidence.
- Local browser sign-in with the reference account failed. Do NOT reset user passwords or seed the real database merely to obtain screenshots. A disposable PHPUnit in-memory fixture was rendered to `/tmp/buildpusher-mobile-preview.html` by `/tmp/MobileNavigationPreviewTest.php`, then served to Playwright with local assets instead.

Payeio design reference: `http://174.138.39.41:8004/login`. The old chat inspected the authenticated workspace dashboard and mobile menu, not just the public login page. User supplied credentials in that chat; they are intentionally NOT copied into this repository. Ask again if authentication is needed. Flow after normal login: click “Login as Payeio”, wait for “Continue to dashboard” to become visible, then click it. Inspection only: do not create or modify a reference workspace.

## Configuration-as-code architecture already in the worktree

The contract and completion criteria are in `docs/application-configuration.md`. Read the actual code; this overview is not proof of correctness.

- Version 2 YAML: logical environment names, explicit workspace website placements, runtimes, named processes/resources, secret references, adoption, child removal and optional `deploy: {repository: app}`. Version 1 workflows remain separate/supported.
- `ApplicationConfigurationDocument`: strict field/type validation, size/expanded-structure limits, sanitized errors. Parser-level expansion limits and full validator parity still require audit.
- `ApplicationConfigurationBindings`: workspace-scoped placement, secret-variable and repository ID maps; secret scope compatibility; deployment readiness and repository fingerprints.
- `ApplicationConfigurationPlanner` and `ApplicationConfigurationReviews`: mutation-free plans, explicit adoption, ownership identity checks, omission preservation, active-deployment checks, 15-minute encrypted reviews, keyed fingerprints of input/resolved bindings/current state, stale-review rejection.
- `ApplicationConfigurationTransaction` / `Reconciler` / `Variables`: recheck access and reviewed state under locks, atomic local changes, encrypted variables and version history, ownership records, durable application receipts and deployment intents. Same-review retries return the original receipt.
- `ApplicationConfigurationBuilds` / `Delivery` / `Results`: reserve one build per operation, immutable encrypted deployment snapshot, revalidate permission/target/repository/gates, approval handling, leased queue delivery, sanitized failure codes, build-outcome synchronization. Local save or enqueue is not remote success.
- Cross-review deployment deduplication references the latest matching operation using an intent digest and receipt link table. Tests previously covered pending/failed/successful reuse and changed runtime command; broader concurrency/repository-change cases remain.
- `ProcessConfigurationOperations` and scheduler: `php artisan buildpusher:configuration:process --limit=100`, every minute after operation tables exist. Queue delivery recovery reuses the same build; failed remote builds are not silently redeployed.
- Web `ApplicationConfigurationController` and `resources/views/scenes/projects/configuration.blade.php`: binding catalog without secret values, upload/review/apply/receipt screens, stale-review handling; no submitted commands flashed into session on errors.
- API methods in `ControlPlaneController`: plan/reviews/apply/application status under `/api/v1/projects/{project}/configuration`, manage token and workspace/security checks.
- Models: `ConfigurationReview`, `ConfigurationOwnership`, `ConfigurationApplication`, `ConfigurationOperation`.
- Five new migrations dated `2026_09_06_010000` through `050000` create reviews, ownerships, applications, operations and shared operation receipts. Prior work did not apply these to the live/local main database; recheck actual migration status before any rollout. Do not run the processor against real operations casually.

## Exact interruption point: environment removal is UNTESTED

Immediately before the handoff request, a patch added initial whole-environment removal. It was not tested, finalized or documented in the main contract yet. `docs/application-configuration.md` still says whole-environment removal is unimplemented; treat that as stale wording, not evidence the new patch is finished.

Initial intended syntax:

```yaml
version: 2
remove:
  environments: [staging]
```

Removal-only documents should accept empty bindings `{}`. A document may also declare other environments but cannot declare and remove the same slug.

Changes already written:

1. `ApplicationConfigurationDocument`: root `remove.environments`, optional/empty environment declarations when removal is present, bounded distinct list of valid names, declaration/removal conflict rejection.
2. NEW `ApplicationConfigurationRemovalPlan`: enumerate owned child removals/resource detachments and environment deletion; reject manual/stale/conflicting ownership, production/protected environments, active builds/outstanding configuration operations, attached schedules/tasks/load balancers and active previews. Explicitly reports `remote_data_deleted: false` and `remote_services_changed: false`.
3. `ApplicationConfigurationPlanner`: integrates that removal plan.
4. `ApplicationConfigurationReconciler`: removes reviewed local environment records after other changes and deletes ownership records; relies on local FK cascades. Remote websites, servers, workloads and data are not deleted or stopped.
5. `ApplicationConfigurationTransaction`: website locks now include existing environment website IDs as well as desired placement IDs.
6. API plan/review validation changed bindings from `required` to `present` array, allowing empty bindings for removal-only documents while still requiring the field.
7. Review UI warns that local config/secret-version history is deleted, remote services/data remain, and provider charges do not stop.

No environment-removal test file had been added at handoff. No tests or build were run after this patch. Its safety, syntax and integration must be verified before any real application or completion claim.

## Next work when the user asks to resume implementation

1. Inspect the interruption patch. Add focused removal tests: valid/remove-only/mixed schemas, duplicates/conflicts/unknown keys, read-only plan, each child shown, manual/foreign/stale ownership rejection, production/protected rejection, active builds and operations, automation/load-balancer/preview safeguards, post-review state/access changes, rollback, absent-target/same-review/new-review retries, and preservation of remote target records/build history.
2. Exercise equivalent web/API removal-only workflows with empty bindings and the explicit warning. Audit FK cascade effects and deployment/dependency races; do not infer concurrency safety from sequential tests.
3. Update the contract and operator docs with the tested removal behavior and precise remaining gaps.
4. Finish operator recovery/retry controls, broader deduplication/repository-change coverage, true database concurrency/deployment-start races, resource credential/managed-resource audit, parser pre-expansion limits and runtime-validator parity.
5. Run full configuration suites and the whole application regression suite, plus rendered UX checks. No complete full-suite passing result was recovered from the earlier long run; do not claim one.
6. Finish migration/rollout verification and requirement-by-requirement completion audit before moving to full-stack previews. Deferred live release gates remain deferred, not passed.

Useful commands, from the actual repository:

```sh
git status --short
php artisan test --filter='ApplicationConfiguration|ConfigurationOperation|ConfigurationOwnership'
php artisan test tests/Feature/DashboardTest.php --stop-on-failure
npm run build
git diff --check
```

`phpunit.xml` configures an isolated in-memory SQLite database and testing cache paths. Verify test isolation before running destructive migration tests. Local `playwright` is available in `node_modules`; Chromium was launched headlessly with `--no-sandbox`. Do not assume old server/browser/test sessions are still alive; inspect live handles before reusing or restarting them.

## Moving to a new chat

Use this same local repository so uncommitted/untracked work remains available. A handoff note supplies project state, not the complete old transcript. The new chat should explicitly read it. Do not keep two chats editing this worktree concurrently; stop/pause any old-chat long-running goal through the UI before resuming in the new chat. This handoff does not itself transfer or complete the goal.
