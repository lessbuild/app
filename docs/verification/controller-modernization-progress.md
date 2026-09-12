# BuildPusher controller modernization progress

Started: 2026-09-12  
Base: `main` / `a0b090798022483933ac28af4ad5a7134deac85b`  
Working branch: `refactor/controller-modernization-20260912`  
Working tree: `/root/Documents/Codex/2026-09-12/buildpusher-controller-modernization`

This ledger records the controller modernization slices independently from the
completed SOLID refactor recorded in `docs/verification/solid-refactor-progress-2026-09-12.md`.
The integration branch remains `main`; no integration has been performed yet.

## Phase 0 — isolation, inventory and baseline

### Isolation proof

- No repository-level `AGENTS.md` applies to this checkout. The only result
  found outside the application was a dependency instruction file under the
  old SOLID-refactor worktree's `vendor/` tree and it was not applicable.
- `main` was clean except for the user's preserved untracked
  `docs/controller-modernization-luna-max-plan.md` before this work began.
  The live checkout and its existing changes were not modified.
- The acceptance-drill checkout at
  `/root/.local/share/buildpusher/drill-20260908/app` remains untouched and
  detached at `c1105b8`.
- This worktree has its own `.env`, Composer and npm caches, application key,
  SQLite database, storage, framework cache, sessions, repository checkout
  directory, backup directory and Vite build output. No production credentials
  were copied.
- Locked dependencies were installed independently with Composer and npm;
  `composer.lock`, `package-lock.json` and application source dependencies were
  not upgraded.
- Local runtime-only directories are excluded through the shared repository's
  `.git/info/exclude`; they are not part of a commit.

Resolved runtime paths were verified before Artisan commands:

```text
db=/root/Documents/Codex/2026-09-12/buildpusher-controller-modernization/storage/database/controller-modernization.sqlite
storage=/root/Documents/Codex/2026-09-12/buildpusher-controller-modernization/storage
cache=/root/Documents/Codex/2026-09-12/buildpusher-controller-modernization/storage/framework/cache/data
session=/root/Documents/Codex/2026-09-12/buildpusher-controller-modernization/storage/framework/sessions
filesystem=/root/Documents/Codex/2026-09-12/buildpusher-controller-modernization/storage/app
queue=sync
```

All migrations completed and `Database\\Seeders\\DemoSeeder` completed in the
isolated runtime. `artisan key:generate` was run only in this worktree.

### Fresh baseline

Commands used the required PHP binary:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: \
  CACHE_STORE=array CACHE_DRIVER=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  /root/.local/share/buildpusher/php-8.5.10/bin/php artisan test \
  --fail-on-warning --fail-on-risky --fail-on-deprecation \
  --fail-on-phpunit-deprecation --do-not-record-test-run-history

/root/.local/share/buildpusher/php-8.5.10/bin/php vendor/bin/pint --test
npm run build
BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:assets
```

Results at `a0b0907`:

- PHP suite: **1,185 passed, 10,743 assertions**, 334.12 seconds.
- Pint: **passed**.
- Vite build: **passed**.
- Built-asset browser matrix: **9 passed**, approximately 3 minutes,
  including the no-JavaScript provider-submission case.
- Served Livewire runtime check on the isolated server at `127.0.0.1:8093`:
  **1 passed** in 52.9 seconds.
- Accessibility check, run alone to avoid Playwright artifact races:
  **2 passed, 1 failed**. The existing tablet expectation still cannot find
  the `Search and navigate` button after Escape and therefore cannot verify
  focus restoration at `tests/Browser/accessibility.spec.js:53`.
- The broad visual audit remains outstanding from the prior verification:
  it is waiting for the missing mobile Settings link. Neither browser issue
  is attributed to this refactor.

`git diff --check` passed before application changes. The first concurrent
accessibility/live-browser invocation produced a Playwright artifact cleanup
`ENOENT`; the isolated rerun was clean apart from the documented tablet focus
failure.

### Endpoint inventory

The authoritative baseline command was:

```sh
/root/.local/share/buildpusher/php-8.5.10/bin/php artisan route:list \
  --json --except-vendor
```

It reported **315 application routes**, **314 controller endpoints** and one
view route. The route sources are `routes/web.php`, `routes/auth.php` and
`routes/api.php`. The following inventory records every route family and its
boundary scope; route names are included where the application assigns them.

| Area | Endpoint families in the baseline | Middleware / boundary notes |
| --- | --- | --- |
| Public and health | `/`, `pricing`, `privacy`, `terms`, `docs`, `api-docs`, `openapi.json`, `favicon.ico`, `api/health`, `status`, `status/report.json`, `status/{slug}`, `status/{slug}/report.json`, status subscribe/confirm/unsubscribe | Public; signed/throttled subscription and verification paths; no actor policy. |
| Auth | register create/store; login create/store; logout; forgot-password create/store; reset-password create/store; verify-email notice/verify/send; confirm-password show/store; two-factor challenge create/store; social redirect/callback | Guest/auth/signed/password-confirm/throttle middleware; protocol and identity checks are not ordinary policies. |
| Account | account page/export/delete; profile/password update; session revoke/list/delete; sign-in list/export/delete; social disconnect/connect; two-factor enable/confirm/cancel/disable/recovery-codes | Authenticated; sensitive actions retain account throttles and named bags. |
| Organization and billing | organization index; invitations store/accept; workspace switch; member update/remove; notification/security settings; SSO connect/callback; workspace destroy; billing index/checkout/portal/cancel/resume | Authenticated; membership, owner and platform gates; destructive workflow stays in an operation. |
| Projects/configuration | project index/create/store/show/destroy; preview update; configuration create/store/review/apply/cancel/retry | Verified account; project policy plus configuration management/recovery abilities; review/application/operation lookups preserve `relatedOperations()`. |
| Environments | environment store/update/destroy; variable store/destroy; process store/destroy; resource store/destroy; deployment-controls update | Verified account, scoped child bindings and environment lifecycle invariants. |
| Providers | provider index/create/store/show/edit/update/destroy/export; connection checks/index/export/test; server catalog | Verified account; provider policy, encrypted credentials, monitoring entitlement and sanitized adapter calls. |
| Servers/imports | server index/create/store/edit/update/destroy/export/show; log download; initialization/provisioning retry; server command index/export/cancel/rerun/destroy/output; import create/store/review/confirm | Verified account, server policy, import assessment ownership/token and callback signatures. |
| Websites/imports | website resource index/create/store/show/edit/update/destroy; export; health checks/index/export/check; runtime log/show/refresh/retention; provisioning log/retry; placement cleanup; import create/store | Verified account, website policy, placement/relocation invariants, encrypted environment and callback integrity. |
| Builds and repositories | build index/show/export/log/compare/cancel/redeploy/approve/reject/rollback/note; promote; repository index/create/store/show/edit/update/destroy/export/deploy; webhook settings store/destroy/delivery export; repository callback | Verified account for web operations; build/repository policies, approvals, revision attestation, leases and idempotency. |
| Databases, domains and load balancers | database index/inspect/users store/delete/clone; domains index/store/temporary/sync/destroy; load-balancer index/store/apply/destroy/nodes store/delete | Verified account; resource policies, plan limits, organization-scoped IDs and business-state safeguards. |
| Backups | backup index; destination store/delete; schedule store/delete; website run; backup restore | Verified account; entitlement, destination/website ownership, confirmation and queued restore semantics. |
| Automation | automation index; workflow; deployment/scaling schedule store/delete; scale/runtime; scheduled task store/run/delete/output; token store/rotate/delete | Verified account; automation abilities, cron/timezone/bounds, token ownership and dispatch semantics. |
| Observability | observability index; metric rule store/delete; destination store/test/delete; status page store/update/delete; incident store/update; operational incident export/acknowledge/assign/note/resolve | Verified account; alert/status/incident policy boundaries, encrypted destinations and notification timing. |
| Activity, commands and notifications | activity index/export; commands index/export; notifications index/export; saved-filter store/delete; read/unread/read-all/clear-read/bulk/delete | Verified account; owner-scoped query collaborators and account-owned mutation actions. |
| Gallery, recipes, reports and feedback | recipe index/create/store/show/edit/update/destroy/duplicate/export; gallery index/show/compare/install/refresh/favorite/rating; report inbox/mine/status/export/review-updates/resolve-many; feedback index/store/update/delete | Verified account; recipe/report/feedback policies, anonymous reporter constraints, unread timing and encrypted content. |
| GitHub/SSO callbacks | GitHub App connect/callback/repositories and API webhook; enterprise SSO connect/callback | OAuth state, webhook signature/replay and integration availability checks remain integration concerns. |
| API v1 | `me`; projects list/show/workflow; configuration plan/review/apply/application/cancel/retry; deployments list/show/log/rollback/promote; environment deploy/scale/runtime/variables | `auth:sanctum`; token abilities remain in addition to resource policies; preserve JSON envelopes/statuses. |
| Provisioning callbacks | build status/log/failed/revision; server status/log/failed; website status/log/failed | Signed callbacks and locked attempt/stale-state ordering; do not move validation ahead of the lock without characterization. |

### Guard and write classification

The source inventory search covered `$request->validate()`,
`validateWithBag()`, `Validator::`, `abort*()`, `permits()`, role/ownership
comparisons, Eloquent writes, query-builder writes, relationship/pivot
mutations and private controller helpers. Baseline counts were:

- 242 controller lines containing validation or guard operations.
- 156 controller lines containing a detected model/relationship write.
- 70 controller files, 15 Form Request files, 8 policy files and 43 action
  files.

Detected validation/guard controllers: access requests, account deletion,
admin access requests/analytics, API control plane, application
configuration, authentication password/registration/two-factor flows,
automation, backups, build promotion/builds, provisioning callbacks, costs,
dashboard, databases, domains, enterprise SSO, environments, GitHub App and
webhook, imports, load balancers, notifications, observability and incidents,
organization, product feedback, projects, provider/catalog, gallery/ratings/
reports/recipes, repositories/webhook settings, server commands/servers,
sign-in history, status subscriptions, system health, two-factor account and
users, and websites.

Detected controller writes include all of the above resource-management areas
plus social account linking, password reset, recipe favorites and gallery
install counters. Existing actions already cover significant server, website,
repository, environment-variable/resource, recipe-report and deployment
workflows; later slices must reuse them rather than introduce duplicate
operations.

Guard categories are recorded before migration:

| Observed guard type | Classification |
| --- | --- |
| Current workspace membership, ownership, role and `permits()` checks | Policy or gate, preserving intentional 404 concealment where present. |
| Child ID does not belong to its bound parent | Scoped binding or relationship lookup; not a permission policy. |
| Production deletion, clone target type/state, active deployment, plan limit, duplicate or unavailable lifecycle state | Business operation/action; preserve original 409/422/404 response. |
| Callback signature, replay, OAuth state, callback token, raw-body size and stale-attempt checks | Middleware/integration verifier or callback operation, preserving execution ordering. |
| Unsupported log/provider/resource type or missing output | Route constraint/resource lookup/integration availability; preserve 404/422/503. |

Existing requests with `authorize(): true` requiring explicit review are:
`ResolveRecipeReportsRequest`, `ReopenRecipeReportsRequest`,
`StoreRecipeReportRequest`, `RecipeReportResolutionRequest` and
`User\\LoginRequest`. They are not changed mechanically: each will be traced
against its controller/policy boundary before authorization ordering changes.

### Initial responsibility map

| Slice | Current boundary problem | Intended boundary and safety focus |
| --- | --- | --- |
| Project creation | Controller validates, checks deploy permission, creates project/environment/process rows and chooses templates. | `StoreProjectRequest`, `ProjectPolicy::create`, `CreateProjectAction`; preserve preset defaults/allowlist, transaction, slug, protected production environment, entitlement-gated processes, actor, redirect and flash. |
| Configuration and environments | Remaining document validation, mixed visibility/management checks and child writes are interleaved with review/receipt lookup and callback-safe services. | Explicit web/API requests and abilities; reuse configuration services and existing environment actions; preserve no-secret old input, JSON/string bindings, receipt relationship, claims, leases and stale callbacks. |
| Infrastructure | Backup/database/domain/load-balancer and residual server/website/import controllers mix validation, tenancy, writes and dispatch. | Resource policies, operation-specific requests/actions and existing jobs/services; preserve encryption, plan limits, cleanup, remote-call timing and retry behavior. |
| Automation/API | Web/API contracts and token abilities are mixed with direct runtime/schedule/task writes. | Separate request contracts, shared operations only where semantics match, API capability checks plus resource policies, and existing dispatch behavior. |
| Observability/organization | Encrypted configuration, membership/destructive workflows and role checks are controller-local. | Resource policies/platform gates plus transaction-aware actions, retaining notification/invitation timing and owner/member distinctions. |
| Product/account | Remaining recipe/provider/report/feedback/notification/account/security writes and named validation bags are distributed across controllers. | Reuse existing actions/queries; preserve `profile`, `password`, `sessions`, `social`, `twoFactor` bags, secret non-disclosure, rate limits and security invariants. |
| Callbacks/integrations | Locked workflow callbacks validate and mutate at protocol-sensitive points. | Last-phase transition operations or dedicated validation collaborators only after ordering is characterized. |

### Phase 0 exit gate

**Complete.** Isolation is proven, the current baseline is fresh and recorded,
the route/guard/write inventory is captured, existing browser defects are
explicit, and the exact next slice is the project-creation pilot.

## Phase 1 — project creation pilot

### Responsibility problem

`ProjectController::create()` and `store()` both made the deployment-permission
decision, while `store()` also defaulted and validated input, selected a
template, generated a workspace-scoped slug, and created the project,
production environment and entitled processes inside a transaction. That
coupled HTTP concerns to a cohesive application operation and made the
transaction difficult to exercise without a request.

### Boundaries applied

- `ProjectPolicy::create` owns the existing workspace `deploy` permission.
- `StoreProjectRequest` owns the same field rules and the preset default. Its
  `input('preset', 'laravel')` behavior intentionally keeps omitted and
  explicit-null input distinct.
- `CreateProjectAction` receives an organization, actor and validated
  attributes. It owns template lookup, slug selection, persistence,
  entitlement-gated process creation and the existing transaction. It does not
  receive an HTTP request or return a redirect.
- The controller now authorizes the create view, passes validated input to the
  action and returns the existing redirect and flash message. Existing project
  show, delete and preview behavior remains outside this pilot.

This applies single responsibility and dependency inversion without adding a
generic CRUD abstraction: the request/policy/action boundaries each have a
concrete consumer and the existing `Entitlements` service remains injected.

### Preserved contracts and safety guarantees

- Existing deploy-role behavior, current-workspace tenancy and denial status
  remain enforced before project validation or writes.
- The configured preset allowlist, omitted `laravel` default, explicit-null
  validation failure, persisted attributes, slug suffixing and actor identity
  are unchanged.
- The protected `Production` environment, runtime template fields,
  entitlement-gated process defaults, transaction rollback, redirect target and
  success flash are unchanged.
- No jobs, events, remote calls or dependency lockfiles were changed.

### Verification

- Focused PHP suite: **26 passed, 166 assertions** across
  `ProjectCreationTest`, `ProjectEnvironmentTest`, `EnvironmentRuntimeTest`,
  `SharedOrganizationTenancyTest` and `EntitlementTest`.
- Added coverage for denied viewer/developer authorization, no-write denial,
  default/null/unknown preset behavior and transaction rollback.
- PHP syntax checks passed for all edited PHP files.
- Pint test passed and `git diff --check` passed.

### Commit and next task

Commit: `5c055ff` — `refactor: extract project creation operation`

**Phase 1 exit gate: complete.** Exact next task: characterize the existing
configuration web/API validation and authorization ordering, then extract the
smallest request and operation boundaries while preserving secret-safe failed
validation, string-versus-array bindings, `relatedOperations()` receipt
lookups, claims, leases and stale-callback behavior. Do not begin environment
extractions until the configuration slice is committed.

## Slice ledger

| Slice | Problem and boundary | Verification | Commit | Exact next task |
| --- | --- | --- | --- | --- |
| Phase 0 | Complete inventory and isolated baseline before source changes. | See baseline above. | `ea81610` — `docs: record controller modernization baseline` | Add the project creation pilot: characterize current tests, then introduce `StoreProjectRequest`, `ProjectPolicy::create` and `CreateProjectAction` without changing contracts. |
| Phase 1 | Project creation mixed permission, validation, template selection and transactional writes in `ProjectController`. | 26 focused tests passed, 166 assertions; Pint and diff checks passed. | `5c055ff` — `refactor: extract project creation operation` | Characterize configuration web/API ordering and extract the smallest configuration request/operation boundary; preserve secret-safe validation, receipt relationships, claims, leases and stale callbacks. |
