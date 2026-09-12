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

## Phase 2A — configuration input and API capability boundary

### Responsibility problem

The configuration controllers repeated document/binding validation and mixed
the project deployment policy with the stronger workspace-management rule
required by the configuration services. API capability checks also lived in a
controller helper, but Form Requests resolve before controller methods; moving
validation without relocating that boundary would let request validation run
before API entitlement, network and token checks.

### Boundaries applied

- `ProjectPolicy::viewConfiguration` and `manageConfiguration` now express the
  existing manager-only configuration capability separately from project
  deployment permission.
- `StoreApplicationConfigurationRequest` owns web document/binding rules,
  bounded depth-20 JSON decoding and explicit validated accessors. Its custom
  failure response flashes errors without submitted document or binding input,
  including parser failures raised after ordinary validation.
- API `ConfigurationInputRequest` owns the unchanged string-document and
  array-binding contract for both planning and review, including required
  presence of empty bindings for removal-only documents.
- `EnsureControlPlaneAccess` is route middleware for the six configuration API
  endpoints. It preserves the existing API entitlement, IP-range and Sanctum
  ability order before Form Request authorization and validation.
- Existing review, planner, reconciler, cancellation and retry services remain
  the operation boundaries. Their locks, transactions and relationship-based
  receipt semantics were not duplicated in controllers or new generic actions.

### Preserved contracts and safety guarantees

- Web routes retain their redirects, validation keys, error messages and
  no-`_old_input` behavior. API routes retain JSON envelopes, status codes,
  `present|array:placements,secrets,repositories` semantics and OpenAPI paths.
- Configuration management no longer relies on the project's deployment-only
  `update` policy. A developer/deployment role with a manage API token is
  rejected before malformed input is evaluated, with no review or other write.
- Review visibility, receipt recovery access, cross-project 404 concealment,
  cancellation/retry requester rules, `relatedOperations()` lookup, secret
  exclusion, ownership, claim, lease and stale-attempt behavior remain in the
  existing controller/service ordering.
- No jobs, events, remote calls, schema, serialized payload or dependency
  lockfile changed.

### Verification

- Fresh configuration suite: **177 passed, 1,736 assertions** across all
  `tests/Feature/ApplicationConfiguration*.php` files.
- Added coverage for web request-validation and JSON-parser failures without
  flashing document/binding input, non-manager denial before validation, and
  API management-role denial before malformed input.
- Existing web/API, removal-only, OpenAPI, receipt-performance, concurrency,
  ownership, environment-removal and retry tests all passed.
- Pint test and `git diff --check` passed.

### Commit and next task

Commit: `bd68cdf` — `refactor: extract configuration input boundaries`

**Phase 2A exit gate: complete.** Exact next task: characterize the no-input
apply/cancel/retry guards and review/application receipt visibility, then add
resource-specific policy abilities or request boundaries only where they
preserve parent/child 404 checks, requester-only apply/retry, manager recovery
cancel, exact error keys/messages and `relatedOperations()` semantics. Commit
that operation-authorization slice before beginning environment requests or
actions.

## Phase 2B — configuration operation authorization boundary

### Responsibility problem

The configuration operation endpoints still used unrestricted Request objects
for no-input contracts, inline replacement-input validation and actor
permission checks. Receipt visibility and recovery decisions were also
duplicated beside relationship lookups. Moving these concerns required
preserving the old ordering: manager access and deliberately concealed
parent/receipt/operation mismatches must be resolved before operation-body
validation, while requester/recovery policy decisions must retain their
existing response status.

### Boundaries applied

- Web ApplyApplicationConfigurationRequest,
  CancelApplicationConfigurationRequest and
  RetryApplicationConfigurationRequest own the no-replacement-input
  contract, with a shared base preserving the configuration-specific
  no-_old_input failure response.
- API operation requests own the corresponding empty-body contract while the
  control-plane middleware continues to run entitlement, network and token
  checks before request validation.
- ConfigurationReviewPolicy owns review visibility and requester-only apply
  decisions. ConfigurationApplicationPolicy owns receipt visibility,
  manager cancellation and requester-only retry decisions.
- Parent and relatedOperations() checks remain relationship/resource
  lookups in request authorization, not permission policies, because their
  deliberate 404 ordering is part of the endpoint contract.
- Controllers now authorize resource abilities, inject
  ApplicationConfigurationResults, and pass the validated request actor to
  the existing transaction-aware services. The services retain their
  defense-in-depth ownership, lock, state, idempotency and stale-attempt
  checks.

This applies single responsibility, interface segregation at the HTTP
boundary and dependency inversion for receipt serialization without adding
generic CRUD services or moving business-state rules into policies.

### Preserved contracts and safety guarantees

- Web and API operation routes, response formats, redirects, status codes,
  exact error keys/messages and empty-body semantics remain unchanged.
- Cross-project and unrelated operation identities still return 404 before
  replacement-input validation; unauthorized managers still cannot apply
  another reviewer's request, while workspace recovery can cancel a pending
  request.
- relatedOperations() remains the source of receipt-operation membership;
  no direct foreign-key assumption or schema change was introduced.
- Receipt refresh remains sanitized and now uses constructor injection.
  Existing transaction boundaries, leases, claims, retries, remote dispatch
  timing and no-secret behavior remain in the existing services.

### Verification

- Focused web/API, recovery, retry and removal suite: 43 passed, 318
  assertions.
- Complete configuration suite: 177 passed, 1,737 assertions.
- Added coverage confirming operation-validation failures do not flash
  _old_input; existing API/OpenAPI, authorization, receipt-performance,
  concurrency, ownership, environment-removal and retry tests passed.
- PHP syntax checks, Pint test and git diff --check passed.

### Commit and next task

Commit: f269fdf — refactor: extract configuration operation boundaries

**Phase 2B exit gate: complete.** The configuration web/API input and
operation boundaries are complete. Exact next task: inspect the remaining
EnvironmentController request/action boundaries for processes, variables,
resources, deployment controls, child deletion and environment deletion;
reuse existing Environment actions and preserve encryption, version history,
nested-resource 404s and production/removal safeguards.

## Phase 2C — environment child-operation input boundary

### Responsibility problem

The environment controller still owned variable, process and resource rules,
defaults, resource-variable parsing and managed-resource safeguards beside
direct child upserts. That coupled the HTTP boundary to encrypted/versioned
persistence and made entitlement denial ordering dependent on controller
execution.

### Boundaries applied

- StoreEnvironmentVariableRequest owns variable structure, scope defaults and
  the existing secret default.
- StoreEnvironmentProcessRequest owns process validation/defaults and retains
  worker entitlement denial before malformed input is evaluated.
- StoreEnvironmentResourceRequest owns resource input validation and retains
  resource entitlement denial before malformed input is evaluated.
- SaveEnvironmentProcessAction owns scheduler replica normalization and the
  process upsert.
- SaveEnvironmentResourceAction now owns resource-variable parsing, managed
  database prerequisites, object-storage restrictions and the resource
  upsert. Its optional pre-parsed argument preserves existing non-HTTP
  callers.
- Existing SaveEnvironmentVariableAction remains the persistence boundary for
  encrypted values, version rows and lock-protected updates.

This applies single responsibility and dependency inversion with concrete
operations. It does not duplicate the existing EnvironmentRequest,
DeploymentControlsRequest or EnvironmentPolicy, and it leaves route-scoped
child lookups and lifecycle-state rules in their current categories.

### Preserved contracts and safety guarantees

- Variable, process and resource validation keys, defaults, redirects,
  entitlement messages and persisted values remain unchanged.
- Scheduler definitions still use one replica; resource parser failures,
  managed database prerequisites and object-storage restrictions still return
  their existing validation feedback without a write.
- Encrypted variable/version persistence, resource credential encryption,
  runtime snapshots and deployment-control behavior are unchanged.
- No route, schema, job, remote call, serialized payload or dependency
  lockfile changed.

### Verification

- Focused environment child/runtime suite: **30 passed, 167 assertions**.
- Covered entitlement denial before malformed process/resource input,
  scheduler defaults, resource validation rollback, environment creation and
  update authorization, encrypted variable versioning, runtime snapshots,
  managed PostgreSQL resources and deployment controls.
- PHP syntax checks, Pint test and git diff --check passed.

### Commit and next task

Commit: 2434f0c — refactor: extract environment child operations

**Phase 2C exit gate: complete.** Exact next task: characterize the remaining
environment/project lifecycle writes, including environment creation/update,
deployment-control persistence and environment/child deletion. Extract only
cohesive actions that preserve protected-production, duplicate-production,
runtime-entitlement, scoped-child 404 and deletion response behavior.

## Phase 2D — environment lifecycle operation boundary

### Responsibility problem

After child input extraction, the environment controller still contained
runtime entitlement calculations, protected-environment permission checks,
production uniqueness checks, slug generation, deployment-control field
mapping and direct deletes. Those responsibilities made lifecycle behavior
hard to exercise outside HTTP and left persistence in the controller.

### Boundaries applied

- EnvironmentRuntimeEntitlements is a narrowly scoped collaborator for the
  shared create/update scaling and hibernation entitlement rules.
- CreateEnvironmentAction owns runtime entitlement ordering, policy-backed
  protected-environment permission, production uniqueness, slug generation and
  creation.
- UpdateEnvironmentAction owns update-time runtime entitlement checks,
  production uniqueness and persistence.
- UpdateDeploymentControlsAction owns the existing validated field mapping
  and lock/window/rollout persistence.
- DeleteEnvironmentAction owns the production deletion safeguard and
  persistence, with the controller mapping its domain rejection back to the
  existing HTTP 422 response.
- DeleteEnvironmentChildAction owns persistence for route-scoped variables,
  processes and resources. Existing scopeBindings() routes continue to
  provide the parent/child 404 boundary.
- ProjectPolicy::createEnvironment owns the actor decision for creating
  protected or approval-gated environments. The controller still explicitly
  authorizes the project update boundary before invoking the action.

This applies single responsibility, dependency inversion and policy-based
authorization without adding a generic CRUD service. The action order keeps
the old entitlement, permission, uniqueness and response behavior.

### Preserved contracts and safety guarantees

- Environment validation/defaults, redirects, flash messages, unique slugs,
  protected-production defaults, duplicate-production errors and paid runtime
  denial behavior remain unchanged.
- Deployment-control lock ownership, maintenance windows, strategy fields and
  timestamps are persisted exactly as before.
- Production deletion remains HTTP 422 with its existing message; non-
  production deletion succeeds, and route-scoped child mismatches remain
  HTTP 404 without a delete.
- No transaction, route, schema, observer, job, remote call or dependency
  lockfile changed. Existing encryption, versioning and configuration
  reconciliation safeguards remain in their established actions/services.

### Verification

- Environment/lifecycle, runtime, entitlement, deployment-control and
  configuration-removal/concurrency suite: **132 passed, 1,105 assertions**.
- Complete configuration suite after the lifecycle changes: **177 passed,
  1,737 assertions**.
- Added coverage for unique/protected environment creation, lifecycle and
  control persistence, production deletion status and scoped child 404s.
- PHP syntax checks, Pint test and git diff --check passed.

### Commit and next task

Commit: 23de929 — refactor: extract environment lifecycle operations

**Phase 2 exit gate: complete.** Configuration web/API input, review/receipt
authorization, environment child operations and environment lifecycle
operations are verified in the isolated checkout. Exact next task: begin
Phase 3 infrastructure operations with Backups, characterizing destination,
schedule, run and restore validation, policies, entitlements, queued jobs and
credential-safety behavior before extracting a cohesive action.

Phase 2 documentation close commit: `270335f` — `docs: close configuration and environment phase`.

## Phase 3A — backup operation boundary

### Responsibility problem

`BackupController` mixed workspace authorization and entitlement checks with
destination and schedule validation, encrypted destination creation, schedule
upserts, backup queue creation, duplicate detection, restore confirmation,
restore transactions and job dispatch. That made controller behavior the only
place where these writes and workflow safeguards could be exercised, and the
private destination guard duplicated a resource authorization rule.

### Boundaries applied

- `BackupDestinationPolicy` owns create/delete management decisions,
  `WebsiteBackupSchedulePolicy` owns create/delete schedule decisions,
  `WebsiteBackupPolicy` owns restore access, and `WebsitePolicy::backup` owns
  manual-backup access. Each preserves current-workspace scoping and the
  existing 403 behavior; policies perform no writes or remote calls.
- StoreBackupDestinationRequest, StoreBackupScheduleRequest,
  RunWebsiteBackupRequest and RestoreWebsiteBackupRequest own the existing
  validation rules and scoped IDs. Their `authorize()` methods preserve the
  old manager-then-entitlement-then-validation ordering, including the
  restore relation load before authorization and confirmation validation.
- CreateBackupDestinationAction owns encrypted destination persistence and
  generated repository-password setup.
- SaveBackupScheduleAction owns the existing website/destination keyed
  upsert. QueueWebsiteBackupAction owns active-backup detection, queued-row
  creation and immediate CreateWebsiteBackupJob dispatch.
- RequestWebsiteBackupRestoreAction owns completed-snapshot and active-
  deployment safeguards, the existing restore transaction and immediate
  RestoreWebsiteBackupJob dispatch. DeleteBackupDestinationAction and
  DeleteBackupScheduleAction own the remaining deletes; domain exceptions
  let the controller retain the original flash messages and statuses.
- `BackupController` now authorizes, consumes validated input, resolves the
  already-validated workspace resources, invokes an action and returns the
  existing response. The index read remains unchanged. Existing backup jobs,
  Restic integration and encrypted model casts were not duplicated.

This applies single responsibility, dependency inversion and policy-based
authorization with concrete Laravel Form Requests, policies and actions. It
does not add a generic repository, base action or provider abstraction.

### Preserved contracts and safety guarantees

- Backup routes, redirects, success/info/error flash text, validation keys,
  exact website-name confirmation and current-workspace/role behavior remain
  unchanged.
- Organization-scoped destination and website IDs remain enforced. Foreign
  managers and non-managers receive the existing denial response without a
  row or queued job. Active queued/running backups still produce the info
  response without a duplicate row or job.
- Encrypted access/secret credentials, generated repository passwords,
  schedule defaults/persisted values and backup status fields remain
  unchanged.
- In-use destinations still cannot be deleted and retain the existing error
  flash. Restore requests still require a completed snapshot, reject active
  deployments before creating a restore, create the restore in a transaction,
  and dispatch only after that transaction returns. The remote jobs and their
  retry/failure behavior are untouched.
- No routes, migrations, schemas, serialized job payloads, dependency
  lockfiles or remote calls changed.

### Verification

- Backup, entitlement, database-backup, database-safety and release-audit
  regression set: **25 passed, 189 assertions**.
- Broader backup/infrastructure safety set including domain, load-balancer
  removal, import and tenancy coverage: **34 passed, 256 assertions**.
- Added coverage proving a non-manager cannot create or queue a backup and
  that active-backup duplicate detection creates neither a row nor a job.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `2253bc2` — `refactor: extract backup operations`

**Phase 3A exit gate: complete.** Exact next task: begin Phase 3
infrastructure operations with Database management; inventory inspect,
database-user, clone and backup-related routes, then extract the smallest
request/policy/action slice while preserving command safety, ownership,
entitlements, remote dispatch and response semantics.

## Phase 3B — database management operation boundary

### Responsibility problem

`DatabaseController` mixed the shared environment-resource authorization
lookup with database availability checks, credential validation and password
generation, database-user persistence, clone target checks, clone creation,
and inspection/user/clone job dispatch. Its private helper also used the
request service locator and combined a deliberate missing-parent 404 with an
actor permission decision.

### Boundaries applied

- `EnvironmentResourcePolicy` owns view and manage decisions for a resource's
  current workspace. `DatabaseController::loadResource()` now performs only
  the existing relationship lookup and deliberate missing-parent 404; it no
  longer reads the current request or decides permissions.
- StoreDatabaseUserRequest and CloneDatabaseRequest own their concrete input
  contracts. Their authorization preserves the old source/resource lookup,
  manager, entitlement and validation ordering. The user request retains the
  unsupported-resource 422 before field validation; clone retains the
  existing later target compatibility checks.
- QueueDatabaseInspectionAction owns inspection dispatch.
  CreateDatabaseUserAction owns password generation, encrypted credential
  persistence and apply-job dispatch; DatabaseUserCreationResult carries the
  one-time plaintext password without exposing it from the model.
  QueueDatabaseUserRemovalAction owns removal dispatch.
- QueueDatabaseCloneAction owns workspace revalidation, same-type/non-self
  checks, production-target rejection, exact confirmation and clone row/job
  creation. DatabaseCloneResult distinguishes queued from confirmation
  rejection, while the controller maps domain failures to the existing 422
  or validation response.
- Existing CollectDatabaseSnapshotJob, ManageDatabaseUserJob and
  CloneDatabaseJob remain the remote execution and retry/idempotency
  boundaries. No new transaction was added around remote work.

This applies single responsibility, dependency inversion and policy-based
authorization with concrete Laravel policies, Form Requests, data objects and
actions. It avoids a generic resource repository or universal database
service.

### Preserved contracts and safety guarantees

- Database routes, redirects, response status codes, validation keys,
  confirmation messages and entitlement behavior remain unchanged.
- Viewers can inspect but cannot issue credentials; managers retain create,
  revoke and clone access. Foreign resources remain denied, unsupported
  resources retain HTTP 422, missing resource parents retain HTTP 404, and
  denied requests create neither rows nor jobs.
- Database-user username uniqueness, privilege/expiry values, encrypted
  passwords, one-time flash behavior and apply/remove job payloads remain
  unchanged.
- Clone requests still reject foreign, incompatible and production targets,
  require exact target-name confirmation, avoid creating a row on rejection,
  and dispatch the existing queued clone job only after creation.
- Inspection remains a unique-job dispatch, and the existing database jobs'
  identifier validation, remote command safety, status transitions, retries
  and sanitized failure handling are untouched.
- No routes, migrations, schemas, serialized job payloads, dependency
  lockfiles or remote commands changed.

### Verification

- New database operation authorization/workflow suite: **5 passed, 27
  assertions**.
- Database operation, platform expansion, entitlement and command-safety
  suite: **18 passed, 111 assertions**.
- Existing platform expansion and tenancy checks passed; PHP syntax checks,
  Pint test and `git diff --check` passed.
- The unique inspection-job test clears only the isolated test cache between
  cases because database refresh reuses IDs while Laravel's unique-job lock
  intentionally outlives a test case.

### Commit and next task

Commit: `b66e1f5` — `refactor: extract database operations`

**Phase 3B exit gate: complete.** Exact next task: continue Phase 3
infrastructure operations with Load balancers and domains; inventory resource
policies, requests, writes, remote apply/delete dispatch, organization-scoped
IDs and response semantics before extracting the smallest cohesive slice.

## Phase 3C — load-balancer operation boundary

### Responsibility problem

`LoadBalancerController` combined workspace and entitlement checks with
validation, environment/server resolution, dedicated-server safety, direct
balancer/node writes, and Caddy apply/removal dispatch. Its private `manage()`
helper also used the request service locator, making authorization harder to
reuse and test independently.

### Boundaries applied

- `LoadBalancerPolicy` owns create/manage decisions for the current workspace.
  StoreLoadBalancerRequest and StoreLoadBalancerNodeRequest own their scoped
  validation and retain manager/entitlement checks before malformed input.
- CreateLoadBalancerAction owns environment/server resolution inputs and the
  dedicated-server invariant. AddLoadBalancerNodeAction owns self-routing
  protection, node persistence and the existing apply dispatch.
- QueueLoadBalancerApplyAction owns explicit apply dispatch.
  DeleteLoadBalancerNodeAction owns delete-then-apply ordering.
  DeleteLoadBalancerAction owns remove-job dispatch before balancer deletion,
  preserving the identifiers required after the row is gone.
- The controller now authorizes, consumes validated input, resolves scoped
  models, invokes the focused operation and returns the existing response.
  Existing Caddy jobs continue to own remote rendering, validation, reload,
  status, retry and failure behavior.

This applies single responsibility, dependency inversion and policy-based
authorization using concrete Laravel requests, policy mapping and actions.
The dedicated-server and self-routing checks remain business rules in the
actions, while no generic CRUD abstraction was introduced.

### Preserved contracts and safety guarantees

- Load-balancer routes, redirects, success messages, validation keys,
  workspace scoping, high-availability entitlement checks and 403 behavior
  remain unchanged.
- Environment placement and server IDs remain organization-scoped;
  dedicated-server and self-routing violations remain HTTP 422 with their
  existing messages.
- Node creation still queues apply after persistence; node deletion still
  deletes before queuing apply; balancer deletion still queues remote removal
  before deleting the local record.
- Denied and entitlement-blocked requests create no rows and queue no jobs.
  Existing unique apply-job behavior, Caddy command generation, cleanup and
  failure handling were not changed.
- No routes, migrations, schemas, serialized job payloads, dependency
  lockfiles or remote commands changed.

### Verification

- Load-balancer operation, platform expansion, removal-job and entitlement
  suite: **20 passed, 116 assertions**.
- Added coverage for creation and full node/balancer lifecycle, dedicated and
  self-routing rejection, viewer denial before malformed input, entitlement
  denial and no-write/no-job behavior.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `d5835bd` — `refactor: extract load balancer operations`

**Phase 3C exit gate: complete.** Exact next task: continue Phase 3
infrastructure operations with Domains; characterize website lookup,
hostname normalization, DNS provider validation, Cloudflare sync failure
fallback, primary-domain protection and Caddy dispatch before extracting
domain operations.

## Phase 3D — domain operation boundary

### Responsibility problem

`DomainController` mixed current-workspace website lookup and website update
authorization with hostname normalization, provider-scoped validation, domain
persistence, optional Cloudflare synchronization, sanitized synchronization
fallbacks, primary-domain protection, DNS deletion and Caddy dispatch. The
controller's private helpers also made the synchronization and failure
semantics unavailable to other application entry points.

### Boundaries applied

- `WebsiteDomainRequest` resolves the submitted website through the current
  workspace relationship and delegates the existing `WebsitePolicy::update`
  decision. StoreWebsiteDomainRequest preserves hostname normalization and
  the exact hostname, redirect and organization/provider validation rules.
- IssueTemporaryWebsiteDomainRequest preserves the old ordering: website
  authorization and the missing `TEMPORARY_APP_DOMAIN` response occur before
  provider validation. The request boundary raises the existing redirect
  response for that configuration exception rather than changing it into a
  validation or authorization response.
- SaveWebsiteDomainAction owns domain persistence, actor identity, alias
  redirect normalization, optional DNS synchronization and the existing
  post-save Caddy dispatch. IssueTemporaryWebsiteDomainAction owns temporary
  hostname generation and delegates the shared save behavior.
- SynchronizeWebsiteDomainAction owns the existing sanitized DNS error status
  persistence. DeleteWebsiteDomainAction owns primary-domain protection,
  delete-before-local-removal ordering and post-delete Caddy dispatch. The
  controller maps operation outcomes to the existing redirects, flash keys,
  validation errors and 422 response.
- The existing `CloudflareDns` service remains the provider adapter, and
  ApplyWebsiteDomainsJob remains the remote Caddy rendering/reload boundary.
  No provider abstraction or generic CRUD action was introduced.

This applies single responsibility, dependency inversion and policy-based
authorization while keeping the actual resource policy (`WebsitePolicy`)
reusable. Primary protection and DNS failure handling remain business
operation outcomes rather than being misclassified as actor permissions.

### Preserved contracts and safety guarantees

- Domain routes, workspace-scoped website lookup, website update policy,
  validation keys, hostname normalization, provider organization/type scope,
  redirect persistence and temporary hostname format remain unchanged.
- Missing temporary-domain configuration still returns the original `domain`
  session error before provider validation. Unauthorized malformed requests
  remain forbidden and create neither rows nor jobs.
- Cloudflare success/failure behavior, sanitized stored error text, manual-DNS
  warning, encrypted credential handling, primary-domain 422 protection and
  no-delete-on-DNS-failure behavior remain unchanged.
- Domain deletion still removes the local row only after the provider delete
  succeeds and dispatches `ApplyWebsiteDomainsJob` afterward. The unique job,
  Caddy rendering, remote timeout/retry and failure behavior remain untouched.
- No routes, migrations, schemas, serialized job payloads, dependency
  lockfiles or provider requests changed.

### Verification

- Domain and adjacent website/tenancy regression set: **24 passed, 219
  assertions**.
- Added coverage for viewer denial before malformed validation, no-write and
  no-job denial, sanitized sync failure persistence, primary-domain
  protection, Cloudflare delete failure retention and successful deletion
  dispatch.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `7d12a23` — `refactor: extract domain operations`

**Phase 3D exit gate: complete.** Exact next task: inventory remaining
`ServersController`, `WebsitesController` and import writes, separating
resource reporting from lifecycle operations while preserving provisioning
attempt ownership, placement/relocation cleanup, encrypted environment data,
callback ordering and import assessment semantics.

## Phase 3E — server lifecycle operation boundary

### Responsibility problem

The existing server provisioning and retry actions were already reusable, but
`ServersController` still persisted display labels and recorded their activity
directly, and it owned the transaction that triggers the server observer's
provider/resource teardown. `ServerRequest` also duplicated the workspace
deploy decision instead of consuming the registered server policy.

### Boundaries applied

- `ServerPolicy::create` now owns the existing current-workspace `deploy`
  permission, and ServerRequest delegates to that policy. This keeps the
  request validation boundary while removing a second role decision.
- `UpdateServerDisplayNameAction` owns the visible-label normalization against
  the technical name, persistence and activity event. The controller passes
  the already normalized request value and retains the existing response.
- `DeleteServerAction` owns the existing transaction around model deletion.
  `ServerObserver` remains responsible for provider deletion, SSH-key
  cleanup, hosted website/repository teardown and previous-placement cleanup;
  the controller still maps thrown provider errors to the existing flash.
- Existing `CreateServerAction`, retry actions, provider resolver and jobs were
  reused unchanged. No generic server repository or provider abstraction was
  introduced.

This applies single responsibility and dependency inversion without moving
remote cleanup into a new transaction or changing observer execution order.
The unsupported-log-type 404 remains a resource/output guard rather than a
policy decision.

### Preserved contracts and safety guarantees

- Server create/update/delete routes, policy status, validation keys,
  display-label normalization, activity text, redirects and flash messages
  remain unchanged.
- Viewer requests are rejected before malformed server-creation validation
  and create neither rows nor jobs. Foreign-resource authorization remains
  unchanged.
- Server deletion still invokes provider and SSH-key cleanup before local
  teardown, preserves the full child-resource cleanup tree, rolls back local
  changes on provider failure and keeps already-absent provider resources
  idempotent.
- Provisioning creation, retry, lease/token, callback and serialized-job
  behavior remains in the existing actions/jobs and was not duplicated.

### Verification

- Server display-name, deletion, initialization/remote retry, creation-failure,
  provider lifecycle, inventory and shared-tenancy regression set: **36
  passed, 308 assertions**.
- Added coverage for policy-backed viewer denial before malformed input and
  no-write/no-job behavior.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `306ff7e` — `refactor: extract server lifecycle operations`

**Phase 3E server exit gate: complete.** Exact next task: characterize the
remaining direct writes in `WebsitesController`—runtime-log refresh,
retention, website creation, placement-cleanup retry and health-check
queueing—then extract only the cohesive operations not already covered by
the existing website actions.

## Phase 3F — website operation boundary

### Responsibility problem

`WebsitesController` already reused actions for website updates, deletion and
provisioning retry, but still mixed website creation, runtime-log snapshot
upserts, retention persistence, placement-cleanup retry state and manual
health-check eligibility/dispatch with HTTP response mapping. WebsiteRequest
also made the deployment permission decision directly instead of using the
registered website policy.

### Boundaries applied

- `WebsitePolicy::create` owns creation permission. WebsiteRequest now uses
  the create policy for POST and the update policy for resource updates;
  UpdateWebsiteLogRetentionRequest owns the retention contract and update
  authorization. Existing normalization/defaults and controller policy calls
  remain intact.
- `CreateWebsiteAction` owns monitoring entitlement enforcement, the existing
  plan-limit transaction, encrypted website creation/password generation and
  provisioning-job dispatch. WebsiteCreationResult carries the one-time
  plaintext password only to the HTTP session boundary; it is not logged or
  persisted in plaintext.
- QueueWebsiteLogRefreshAction owns active-state checking, snapshot
  `updateOrCreate` and unique refresh-job dispatch. UpdateWebsiteLogRetentionAction
  owns the validated setting write. QueueWebsitePlacementCleanupAction owns
  clearing the prior error and dispatching the captured placement job.
- QueueWebsiteHealthCheckAction owns server loading, eligibility outcomes and
  unique health-job dispatch. WebsiteHealthCheckResult keeps the disabled,
  inactive and queued outcomes explicit while the controller retains the
  original messages.
- Existing UpdateWebsiteAction now owns its monitoring entitlement check along
  with its existing lock, placement, health-reset and after-commit provisioning
  behavior. Existing provisioning/deletion actions and all remote jobs remain
  unchanged.

This applies single responsibility, dependency inversion and policy-based
authorization with concrete Laravel requests/actions/data objects. It avoids
generic CRUD services; route-constrained unsupported log types remain HTTP
resource guards.

### Preserved contracts and safety guarantees

- Website routes, redirects, validation keys, omitted-field defaults,
  hostname/path normalization, entitlement/plan errors and resource policy
  behavior remain unchanged.
- Viewer creation attempts are forbidden before malformed validation and
  create neither rows nor jobs. Free-plan monitoring requests retain the plan
  error and no-write/no-job behavior.
- Website creation still records the actor, stores encrypted environment and
  database values, flashes the generated password under the same session key,
  and queues `AddWebsiteJob` outside the plan-limit transaction.
- Runtime-log retention, snapshot status/error reset, unique refresh dispatch,
  active-website guard, health-check messages, unique health dispatch and
  previous-placement cleanup payload/order remain unchanged.
- Existing update transaction locks, active-deployment/provisioning guards,
  relocation state, cleanup retries, encrypted environment handling, stale
  callback protection and website deletion observer/job behavior remain in
  their existing operations.
- No routes, migrations, schemas, serialized job payloads, dependency
  lockfiles or remote commands changed.

### Verification

- Website placement, health/monitoring, observability, relocation, deletion,
  environment encryption, deployment serialization, shared tenancy and
  entitlement regression set: **55 passed, 563 assertions**.
- Added coverage for viewer denial before malformed website creation,
  monitoring entitlement denial, active/inactive runtime-log refresh behavior
  and no-write/no-job denial.
- Explicitly cleared the isolated test cache for unique health-job tests; this
  prevents refreshed database IDs from colliding with an intentional unique
  job lock across test cases.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `a35360a` — `refactor: extract website operations`

**Phase 3F website exit gate: complete.** Exact next task: refactor the
remaining import writes. Characterize session-bound server assessment
ownership/token/expiry/consumption, inspection failure handling, host
fingerprint confirmations, active-server directory probes, plan limits,
encrypted credentials, activity timing and cross-workspace denial before
extracting import operations.

## Phase 3G — import operation boundary

### Responsibility problem

`ImportServerController` mixed server-limit validation, remote inspection,
encrypted assessment persistence, session-token setup, confirmation validation,
assessment consumption and activity recording. `ImportWebsiteController` mixed
active-server lookup, remote directory probing, plan-limited website creation,
encrypted credential generation and activity recording. Their import requests
also duplicated deployment permission logic instead of using the resource
policies.

### Boundaries applied

- `InspectServerImportAction` owns the server-limit check, validated SSH
  configuration construction, remote discovery failure mapping and assessment
  persistence. `ServerImportAssessmentResult` carries the one-time plaintext
  session token only to the HTTP session boundary.
- `ConfirmServerImportRequest` owns confirmation rules but deliberately checks
  the assessment's session token, owner, expiry and consumed state in
  `authorize()` and throws 404. This is a session protocol guard, not a normal
  resource permission, and preserves the old concealment and validation order.
  The controller retains the typed assessment parameter so Laravel performs
  implicit route model binding before resolving the request.
- `ConfirmServerImportAction` retains the locked assessment claim, encrypted
  server creation, provisioning preparation, queued retry and consumed marker;
  it now records the existing activity after the transaction completes.
- `ImportWebsiteAction` owns the exact active-server directory probe, plan-limit
  transaction, active website creation and activity record. The controller now
  performs only scoped lookup, operation invocation and response mapping.
- ImportServerRequest and ImportWebsiteRequest now delegate creation permission
  to ServerPolicy and WebsitePolicy while preserving their validation and
  normalization contracts.

This applies single responsibility, policy-based authorization and dependency
inversion without adding generic CRUD abstractions or changing remote protocol
behavior.

### Preserved contracts and safety guarantees

- Import routes, redirects, statuses, validation keys, plan and connection
  messages, confirmation values, session key format and success flash text are
  unchanged.
- Assessment ownership, workspace identity, expiry, single-use consumption,
  404 concealment, locked claim ordering, stale-token rejection, encrypted
  configuration, host-fingerprint confirmation, provisioning preparation,
  after-commit retry dispatch and activity messages remain intact.
- Confirmation validation runs only after a usable assessment is established,
  so foreign, expired and consumed requests do not flash confirmation input.
- Website imports still resolve only active servers in the current workspace,
  execute the same shell-safe `/var/www` readability probe, enforce the same
  limit, generate encrypted credentials and do not queue provisioning.
- Viewer requests are denied before malformed validation and before discovery,
  remote probes, database writes or queued jobs. No routes, schemas, job
  payloads, persisted values or dependency lockfiles changed.

### Verification

- Import and adjacent server/website infrastructure regression set: **82
  passed, 694 assertions**.
- Added viewer-denial/no-side-effect coverage for both import endpoints.
- PHP syntax checks, Pint test and `git diff --check` passed.
- `ImportServerTest` now flushes only the isolated test cache in `setUp()` so
  the route's six-per-minute confirmation throttle cannot leak between test
  runs; this addresses test isolation and does not alter production behavior.

### Commit and next task

Commit: `70f2d57` — `refactor: extract import operations`

**Phase 3 infrastructure exit gate: complete.** Exact next task: begin Phase 4
by inventorying `AutomationController` and `Api/V1/ControlPlaneController`
deployment/scaling/scheduled-task/workflow/token endpoints, documenting their
request, response, authorization, entitlement and dispatch contracts, then
extract the smallest schedule operation with separate web/API input boundaries.

## Phase 4A — deployment schedule operation

### Responsibility problem

`AutomationController::deploymentSchedule` performed environment policy
authorization, paid-feature enforcement, cron/timezone validation, schedule
persistence and actor attribution in one HTTP method. The automation inventory
also confirmed that the API currently exposes workflow, runtime and scaling
operations rather than a separate schedule-create route; `WorkflowConfiguration`
remains the YAML/API-compatible schedule path.

### Boundaries applied

- `StoreDeploymentScheduleRequest` owns the schedule input contract and uses
  the environment update policy. It enforces the existing
  `scheduled_deployments` entitlement in `authorize()` so entitlement denial
  remains ahead of validation, matching the former controller order.
- `CreateDeploymentScheduleAction` injects `Entitlements`, rechecks the
  feature for non-HTTP callers, and owns enabled schedule persistence and
  creator attribution.
- `AutomationController` now passes validated input to the action and returns
  the existing redirect/flash response. No new API route or generic schedule
  abstraction was introduced.

This applies single responsibility, dependency inversion and Laravel Form
Request/policy conventions while retaining the existing workflow service for
the different YAML operation.

### Preserved contracts and safety guarantees

- The route, response status, redirect target, success message, validation keys,
  cron expression semantics, IANA timezone allowlist, enabled default and
  `created_by` value are unchanged.
- Environment policy denial still precedes entitlement and malformed input;
  free-plan entitlement denial still precedes validation and creates no row.
- The action performs no remote work and does not change workflow YAML,
  scheduled-run claiming, runtime dispatch, API envelopes or persistence
  schemas.

### Verification

- Automation, API, workflow, runtime, tenancy, entitlement and configuration
  regression set: **36 passed, 247 assertions**.
- Added valid schedule, viewer-denial/no-write and free-plan/no-write tests.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `936fa7c` — `refactor: extract deployment schedule operation`

Exact next task: characterize `AutomationController::scalingSchedule` and its
distinct replica-bound validation, then extract a separate request/action
while preserving the `scheduled_scaling` entitlement and no-write denial
behavior.

## Phase 4B — scaling schedule operation

### Responsibility problem

`AutomationController::scalingSchedule` duplicated the deployment schedule's
HTTP concerns while adding environment-specific replica bounds. It performed
policy authorization, entitlement enforcement, two-stage schedule/replica
validation, persistence and creator attribution in one method.

### Boundaries applied

- `StoreScalingScheduleRequest` owns environment-policy authorization, the
  `scheduled_scaling` entitlement ordering, cron/timezone validation and
  replica validation against the bound environment's current minimum and
  maximum values.
- `CreateScalingScheduleAction` injects `Entitlements`, rechecks the feature
  for non-HTTP callers, and owns enabled scaling-schedule persistence and actor
  attribution.
- `AutomationController` now passes validated input to the action. The API
  runtime scaling endpoint remains a separate contract because it accepts a
  replica count and returns a JSON 202 envelope rather than creating a schedule.

This applies single responsibility, interface discipline through distinct
request contracts and dependency inversion without sharing abstractions where
the web schedule and API runtime semantics differ.

### Preserved contracts and safety guarantees

- Scaling-schedule routes, redirects, status codes, flash text, validation keys,
  cron/timezone rules, enabled defaults and `created_by` values are unchanged.
- Policy denial still precedes entitlement and malformed input; the free-plan
  entitlement denial still precedes validation and creates no schedule.
- Replica values remain bounded by the environment's persisted minimum and
  maximum. Scheduled-run claiming and `ApplyEnvironmentRuntimeStateJob`
  behavior are untouched.

### Verification

- Automation, API, workflow, runtime, tenancy and entitlement regression set:
  **38 passed, 231 assertions**.
- Added valid bounded schedule, out-of-range replica, viewer-denial/no-write
  and free-plan/no-write tests.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `b4323c2` — `refactor: extract scaling schedule operation`

Exact next task: characterize scheduled-task creation, manual run overlap
guards, encrypted command handling, task deletion and output authorization;
then extract the smallest scheduled-task request/action while preserving its
run and job semantics.

## Phase 4C — scheduled-task creation operation

### Responsibility problem

`AutomationController::scheduledTask` repeated schedule validation beside task
specific validation, entitlement checks, encrypted-command persistence and
creator attribution. That left a substantial write operation in the HTTP
controller while manual runs, deletion and output remained separate concerns.

### Boundaries applied

- `StoreScheduledTaskRequest` owns environment policy authorization, the
  `scheduled_deployments` entitlement ordering, cron/timezone rules, command
  limits, timeout bounds and required overlap/alert booleans.
- `CreateScheduledTaskAction` injects `Entitlements`, rechecks the feature for
  non-HTTP callers, and owns enabled task persistence and creator attribution.
  The existing encrypted `ScheduledTask::command` cast remains the persistence
  boundary.
- `AutomationController` now passes validated attributes to the action. Its
  manual-run overlap guard, run creation/job dispatch, deletion, output
  response and authorization remain untouched for the next focused slices.

This applies single responsibility, dependency inversion and explicit Laravel
request boundaries without changing scheduled-task lifecycle semantics.

### Preserved contracts and safety guarantees

- Task routes, redirects, status codes, validation keys, cron/timezone rules,
  command and timeout limits, boolean requirements, enabled default, creator
  identity and success flash remain unchanged.
- Environment policy denial still precedes entitlement and malformed input;
  free-plan entitlement denial still precedes validation and creates no task.
- Commands remain encrypted at rest and hidden from the automation response;
  no run, retry, overlap, notification or job payload behavior changed.

### Verification

- Automation, API, workflow, runtime, tenancy and entitlement regression set:
  **40 passed, 236 assertions**.
- Added viewer-denial/no-write and free-plan/no-write coverage for task
  creation; existing encrypted-command coverage remains green.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `f6878ed` — `refactor: extract scheduled task operation`

Exact next task: extract manual scheduled-task runs, preserving the
`without_overlapping` queued/running guard, run creation, `last_queued_at`
update, job dispatch timing, entitlement and task-environment authorization.

## Phase 4D — manual scheduled-task run operation

### Responsibility problem

`AutomationController::runScheduledTask` combined task-environment policy
authorization, entitlement checks, non-overlap business rules, queued-run
persistence, task timestamp mutation and job dispatch. The controller also had
to translate the null-versus-run outcome into the existing redirect and flash
responses.

### Boundaries applied

- `QueueScheduledTaskRunAction` injects `Entitlements`, checks the task's
  queued/running overlap state, creates the queued run, updates
  `last_queued_at` and dispatches the existing `RunScheduledTaskJob`.
- The controller retains the resource policy check and maps the action's null
  result to the existing already-running validation response.
- The action intentionally preserves the existing non-transactional write and
  dispatch order; the unique worker job remains responsible for execution
  locking, retries and remote failure handling.

This applies single responsibility and dependency inversion without moving
worker lifecycle logic into the HTTP request or introducing a generic queue
abstraction.

### Preserved contracts and safety guarantees

- Task routes, redirects, status codes, success/error messages, queued status,
  timestamp behavior and job payload (`runId`) are unchanged.
- Policy denial occurs before the active-run query, so denied actors cannot
  create runs or dispatch jobs. The non-overlap guard still blocks both queued
  and running runs and leaves existing state unchanged.
- `RunScheduledTaskJob`'s unique lock, encrypted output, bounded history,
  incident notifications and remote command behavior are untouched.

### Verification

- Automation, API, workflow, runtime, tenancy and entitlement regression set:
  **43 passed, 250 assertions**.
- Added owner queueing/job-payload, active-run rejection and viewer-denial
  no-side-effect tests.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `9c5edf3` — `refactor: extract scheduled task runs`

Exact next task: extract scheduled-task deletion and then the paired deployment
and scaling schedule deletion actions, preserving policy ordering and current
404/redirect behavior; keep scheduled-run output as a read-only controller
response unless characterization shows a separate query boundary is needed.

## Phase 4E — automation deletion operations

### Responsibility problem

The three automation deletion endpoints still performed direct Eloquent
deletes in `AutomationController`, even after their resource-policy checks.
This left the project convention inconsistent across creation, execution and
deletion while coupling the controller to each model's persistence operation.

### Boundaries applied

- `DeleteScheduledTaskAction`, `DeleteDeploymentScheduleAction` and
  `DeleteScalingScheduleAction` own their concrete persisted-record deletes.
- The controller continues to authorize the schedule/task environment and
  returns the existing success response. No shared generic delete abstraction
  was introduced because the three model lifecycles are separate operations.
- Scheduled-task output remains a read-only response boundary with its existing
  view policy and no action extraction.

This applies single responsibility and keeps authorization in policies while
avoiding speculative interfaces or repositories.

### Preserved contracts and safety guarantees

- Delete routes, implicit bindings, authorization status, redirects and exact
  success messages are unchanged.
- Viewer denial occurs before any action invocation; owner deletion retains
  existing cascade behavior for task runs. No entitlement, job, output,
  workflow or schedule-run semantics changed.

### Verification

- Automation, API, workflow, runtime, tenancy and entitlement regression set:
  **45 passed, 265 assertions**.
- Added owner deletion and viewer/no-write coverage for all three automation
  record types.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `7ad26fa` — `refactor: extract automation deletion operations`

Exact next task: characterize web scaling changes and API scale/runtime
contracts together, then extract the smallest shared runtime transition
operation only where semantics match; preserve distinct request validation,
token abilities, entitlements, status envelopes and dispatch behavior.

## Phase 4F — runtime scaling and API parity

### Responsibility problem

The web and API runtime endpoints still combined request validation, policy and
entitlement ordering, environment persistence, queue dispatch and response
mapping in their controllers. The API controller also duplicated the API
entitlement, network-range and token-ability check already used by
`EnsureControlPlaneAccess`, which made moving API validation into Form Requests
liable to change the access-before-validation contract.

### Boundaries applied

- `ScaleEnvironmentRequest` and `RuntimeEnvironmentRequest` own the web input
  contracts and environment-policy boundary. The scaling request retains the
  scaling entitlement check before validation; runtime entitlement remains in
  the operation because it depends on the validated state.
- API `ScaleEnvironmentRequest` and `RuntimeEnvironmentRequest` retain the
  separate `replicas` and `state` contracts. Their authorization first uses
  `ControlPlaneAccess`, then the environment policy and (for scale) scaling
  entitlement, preserving the former API capability/network/policy/entitlement
  ordering before validation.
- `ControlPlaneAccess` is the shared API capability collaborator used by both
  `EnsureControlPlaneAccess` and `ControlPlaneController`; it preserves API
  entitlement, encrypted workspace IP-range matching, Sanctum abilities and
  exact 403 messages without putting these integration access checks in a
  resource policy.
- `UpdateEnvironmentScalingAction` owns canonical scaling persistence,
  clearing the hibernation marker and dispatching the existing unique runtime
  job. `QueueEnvironmentRuntimeStateAction` owns state-dependent hibernation
  entitlement and dispatch. Both actions are reusable without an HTTP Request;
  web and API response envelopes remain in their controllers.

This applies single responsibility and dependency inversion with concrete
Laravel requests, policies, a shared access collaborator and focused actions.
It keeps web/API request schemas distinct because their semantics differ and
does not add a generic runtime repository or strategy.

### Preserved contracts and safety guarantees

- Web routes retain redirects, flash messages, field names, nullable scaling
  behavior and validation keys. API routes retain `replicas` versus `state`,
  JSON 202 envelopes, status text and response fields.
- Policy and API token denial still precede malformed input. Foreign
  environments remain forbidden, and denied requests create neither database
  writes nor `ApplyEnvironmentRuntimeStateJob` instances.
- Scaling still persists the submitted minimum/maximum/desired values,
  clears `hibernated_at` and queues the job with `hibernate=false`. Runtime
  still validates before checking the state-specific hibernation entitlement,
  and queues the existing `hibernate` flag only after that check succeeds.
- Laravel's existing per-environment unique-job lock continues to coalesce
  overlapping runtime transitions. No transaction, remote call, job payload,
  route, schema, persisted value or dependency lockfile changed.

### Verification

- Runtime/API and adjacent automation, configuration, environment, tenancy,
  entitlement and platform regression set: **53 passed, 334 assertions**.
- Added coverage for web scale persistence/queueing, web runtime entitlement
  ordering, API access-before-validation, foreign-resource denial, exact scale
  and runtime envelopes, hibernation queueing, validation failure and no-side-
  effect behavior. Existing control-plane middleware/configuration tests also
  passed after the shared access extraction.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `49bd49a` — `refactor: extract runtime operations`

**Phase 4F exit gate: complete.** Exact next task: characterize automation
token creation, rotation and deletion—ownership, abilities, expiry, plan
entitlement, plaintext-token flash behavior and no-secret logging—then extract
the smallest request/action boundaries without changing token serialization or
feedback.

## Phase 4G — automation token operations

### Responsibility problem

`AutomationController` still validated token creation, decided workspace-owner
access, normalized duplicate abilities, generated expiry values and directly
created the credential. Rotation and revocation also performed direct deletes,
while owner checks and deliberate foreign-token 404 concealment lived in a
private controller helper. That left credential lifecycle writes outside the
project's action/policy convention and made the one-time plaintext boundary
harder to test independently.

### Boundaries applied

- `PersonalAccessTokenPolicy` owns owner-only issuance and the token-owner
  decisions for rotation/revocation. Its resource denials use
  `Response::denyAsNotFound()` so unrelated or wrong-morph tokens retain the
  existing 404 concealment; the class-level create decision retains the
  existing owner 403 for issuance and rotation.
- `StorePersonalAccessTokenRequest` owns token name, ability, expiry and
  one-year default validation. It invokes the policy and retains API
  entitlement denial before malformed input.
- `CreatePersonalAccessTokenAction` owns entitlement revalidation, ability
  de-duplication, expiry calculation and Sanctum token creation.
  `RotatePersonalAccessTokenAction` owns create-before-revoke replacement with
  the existing one-year expiry. `RevokePersonalAccessTokenAction` owns the
  already-authorized delete.
- The controller now passes validated fields to the creation action, invokes
  policy abilities for rotate/revoke and flashes the `NewAccessToken` plaintext
  only in the existing session response. AuthServiceProvider explicitly maps
  the Sanctum token model to the policy.

This applies single responsibility, policy-based authorization and dependency
inversion with concrete credential operations. It keeps token lifecycle
semantics specific rather than introducing a generic account repository.

### Preserved contracts and safety guarantees

- Token routes, redirects, validation keys, default/explicit-null expiry
  behavior, allowed abilities, duplicate normalization, flash messages and
  one-time plaintext display remain unchanged.
- Owner-only create/rotate still returns 403 before malformed input;
  foreign-owner and wrong-morph tokens still return 404 without mutation.
  Revocation remains available to the token owner without changing the
  workspace-owner rule for create/rotate.
- Rotation still creates the replacement before deleting the old token, keeps
  matching abilities and uses a one-year expiry. Stored token values remain
  hashed; plaintext is neither persisted nor logged by the actions.
- No route, schema, serialized token/API value, dependency lockfile or
  external integration changed.

### Verification

- Automation, bound-token, API configuration, platform and shared-tenancy
  regression set: **44 passed, 261 assertions**.
- Added coverage for default expiry, ability de-duplication, plaintext
  non-persistence, malformed-input denial ordering, free-plan denial, owned
  revocation and existing foreign/wrong-morph 404 behavior.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `c87e230` — `refactor: extract automation token operations`

**Phase 4G exit gate: complete.** Exact next task: characterize web and API
workflow application validation and authorization ordering, then introduce
separate workflow requests that reuse `WorkflowConfiguration` without changing
YAML parsing, atomic application, response envelopes or flash messages.

## Phase 4H — workflow request boundaries

### Responsibility problem

Both workflow endpoints still performed inline `required|string|max:50000`
validation in their controllers before invoking the already cohesive,
transaction-aware `WorkflowConfiguration` service. The web and API paths share
the YAML field but have different authorization and response boundaries; the
API path also needed control-plane entitlement/network/token checks before
project policy and validation.

### Boundaries applied

- `ApplyWorkflowRequest` owns web workflow validation and the project update
  policy, with an explicit accessor for the validated YAML.
- API `ApplyWorkflowRequest` owns the same string/size contract but first uses
  `ControlPlaneAccess` and then the project update policy, preserving API
  access-before-validation ordering.
- `AutomationController` and `ControlPlaneController` now pass validated YAML
  and actor identity to the existing `WorkflowConfiguration` service. No
  wrapper action was introduced because that service already owns the actual
  version-1 YAML parsing, plan checks, cohesive transaction and persistence.

This applies single responsibility and dependency inversion at the HTTP
boundary while avoiding a rename-only action abstraction. Web/API requests
remain separate because their authorization and response contracts differ.

### Preserved contracts and safety guarantees

- Web workflow routes retain redirects, flash text, validation keys and the
  existing form transport behavior. API routes retain the 200 JSON envelope,
  OpenAPI field name, 50 KB limit and control-plane token requirements.
- Policy and API capability denial still precede malformed input. The existing
  service continues to own YAML-specific validation, entitlement checks,
  atomic schedule/scaling/process application, encrypted workflow persistence
  and rollback behavior.
- No route, schema, job, serialized payload, persisted workflow value or
  dependency lockfile changed.

### Verification

- Automation, workflow, API configuration, configuration transaction and
  concurrency, environment, tenancy, entitlement and platform regression set:
  **67 passed, 453 assertions**.
- Added web/API success coverage, explicit validated persistence checks and
  malformed-input denial ordering for both request types.
- PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `dc57901` — `refactor: extract workflow request boundaries`

**Phase 4H exit gate: complete.** Exact next task: characterize the API
variables endpoint's parser, secret-safe failure behavior, organization
scoping and transaction/lock semantics, then extract its request and
transaction-aware action without flashing or serializing submitted secrets.

## Slice ledger

| Slice | Problem and boundary | Verification | Commit | Exact next task |
| --- | --- | --- | --- | --- |
| Phase 0 | Complete inventory and isolated baseline before source changes. | See baseline above. | `ea81610` — `docs: record controller modernization baseline` | Add the project creation pilot: characterize current tests, then introduce `StoreProjectRequest`, `ProjectPolicy::create` and `CreateProjectAction` without changing contracts. |
| Phase 1 | Project creation mixed permission, validation, template selection and transactional writes in `ProjectController`. | 26 focused tests passed, 166 assertions; Pint and diff checks passed. | `5c055ff` — `refactor: extract project creation operation` | Characterize configuration web/API ordering and extract the smallest configuration request/operation boundary; preserve secret-safe validation, receipt relationships, claims, leases and stale callbacks. |
| Phase 2A | Configuration web/API document and binding validation mixed with project deployment permission and controller API capability checks. | 177 configuration tests passed, 1,736 assertions; Pint and diff checks passed. | `bd68cdf` — `refactor: extract configuration input boundaries` | Characterize no-input apply/cancel/retry and receipt visibility, then extract only operation-specific policies/requests that preserve 404, requester and recovery semantics. |
| Phase 2B | Configuration operation bodies and receipt abilities remained inline around relationship lookups and transaction-aware services. | 43 focused tests passed, 318 assertions; complete configuration family 177/1,737; Pint and diff checks passed. | `f269fdf` — `refactor: extract configuration operation boundaries` | Inspect remaining environment request/action boundaries for processes, variables, resources, deployment controls and deletion safeguards. |
| Phase 2C | Environment child endpoints mixed validation, entitlement order, parsing, normalization and direct child writes. | 30 focused tests passed, 167 assertions; Pint and diff checks passed. | `2434f0c` — `refactor: extract environment child operations` | Characterize lifecycle writes and deletion safeguards before extracting cohesive environment actions. |
| Phase 2D | Environment lifecycle validation, entitlement checks, field mapping and direct deletes remained in EnvironmentController. | 132 affected tests passed, 1,105 assertions; complete configuration family 177/1,737; Pint and diff checks passed. | `23de929` — `refactor: extract environment lifecycle operations` | Begin Phase 3 with Backups: inventory policies, requests, actions, jobs, entitlements and credential safety. |
| Phase 3A | Backup destination, schedule, run and restore endpoints mixed validation, authorization, encrypted writes, duplicate detection, transactions and job dispatch in `BackupController`. | 25 focused regression tests passed, 189 assertions; broader infrastructure set 34/256; Pint and diff checks passed. | `2253bc2` — `refactor: extract backup operations` | Begin Phase 3 with Database management: inventory inspect/user/clone operations and preserve command safety, ownership, entitlements and dispatch behavior. |
| Phase 3B | Database inspection, credential, removal and clone endpoints mixed resource lookup/permission, validation, encrypted writes, safety rules and job dispatch in `DatabaseController`. | 5 new database-operation tests passed, 27 assertions; combined database/platform/entitlement/safety set 18/111; Pint and diff checks passed. | `b66e1f5` — `refactor: extract database operations` | Continue Phase 3 with Load balancers and domains: inventory resource policies, requests, operations, remote dispatch and scoped IDs. |
| Phase 3C | Load-balancer validation, authorization, placement invariants, direct node/balancer writes and Caddy dispatch remained in `LoadBalancerController`. | 20 focused tests passed, 116 assertions; Pint and diff checks passed. | `d5835bd` — `refactor: extract load balancer operations` | Continue Phase 3 with Domains: preserve website lookup, hostname normalization, Cloudflare outcomes, primary protection and Caddy dispatch. |
| Phase 3D | Domain validation, website authorization, DNS synchronization/deletion, primary protection and Caddy dispatch remained in `DomainController`. | 24 adjacent domain/website/tenancy tests passed, 219 assertions; Pint and diff checks passed. | `7d12a23` — `refactor: extract domain operations` | Inventory remaining server, website and import writes; separate reporting from lifecycle operations while preserving provisioning, relocation, encryption, callback and import semantics. |
| Phase 3E-server | Server display-label and deletion persistence remained in `ServersController`, and create permission was duplicated in `ServerRequest`. | 36 server/provider/retry/deletion/tenancy tests passed, 308 assertions; Pint and diff checks passed. | `306ff7e` — `refactor: extract server lifecycle operations` | Characterize remaining `WebsitesController` direct writes and extract cohesive runtime-log, retention, creation, cleanup-retry and health-check operations not already covered by existing actions. |
| Phase 3F-website | Website creation, runtime-log writes, retention, placement-cleanup state and health dispatch remained in `WebsitesController`; `WebsiteRequest` duplicated deploy permission. | 55 website/observability/relocation/deletion/tenancy/entitlement tests passed, 563 assertions; Pint and diff checks passed. | `a35360a` — `refactor: extract website operations` | Characterize and extract remaining server/website import assessment and import-write operations, preserving session token, expiry, locks, probes, encryption and activity timing. |
| Phase 3G-imports | Server and website import controllers mixed protocol validation, remote inspection/probes, assessment/website persistence, plan limits and activity side effects; import requests duplicated deploy permission. | 82 import/server/website/infrastructure regression tests passed, 694 assertions; Pint and diff checks passed. | `70f2d57` — `refactor: extract import operations` | Inventory AutomationController and API/V1/ControlPlaneController schedule, workflow, runtime and token contracts, then extract the smallest schedule operation with separate web/API request boundaries. |
| Phase 4A-deployment-schedule | Deployment-schedule creation mixed policy, entitlement ordering, cron validation and persistence in `AutomationController`; API has no separate schedule-create route and continues to use workflow YAML for that contract. | 36 automation/API/runtime/tenancy/entitlement/configuration regression tests passed, 247 assertions; Pint and diff checks passed. | `936fa7c` — `refactor: extract deployment schedule operation` | Characterize scaling-schedule replica bounds and scheduled-scaling entitlement, then extract its separate request/action and no-write denial tests. |
| Phase 4B-scaling-schedule | Scaling-schedule creation mixed policy, entitlement ordering, cron/timezone validation, environment replica bounds and persistence in `AutomationController`; API runtime scaling remains a distinct JSON operation. | 38 automation/API/runtime/tenancy/entitlement regression tests passed, 231 assertions; Pint and diff checks passed. | `b4323c2` — `refactor: extract scaling schedule operation` | Characterize scheduled-task creation, overlap guards, encrypted commands, deletion and output authorization, then extract its request/action boundary. |
| Phase 4C-scheduled-task | Scheduled-task creation mixed policy, entitlement ordering, cron/task validation, encrypted command persistence and actor attribution in `AutomationController`; manual runs and output remain separate operations. | 40 automation/API/runtime/tenancy/entitlement regression tests passed, 236 assertions; Pint and diff checks passed. | `f6878ed` — `refactor: extract scheduled task operation` | Extract manual scheduled-task runs while preserving overlap guards, run timestamps, job dispatch and task authorization. |
| Phase 4D-task-run | Manual scheduled-task execution mixed policy, entitlement, overlap checks, queued-run persistence, timestamp mutation and job dispatch in `AutomationController`. | 43 automation/API/runtime/tenancy/entitlement regression tests passed, 250 assertions; Pint and diff checks passed. | `9c5edf3` — `refactor: extract scheduled task runs` | Extract scheduled-task deletion, then paired deployment/scaling schedule deletion actions, preserving policy ordering and existing responses. |
| Phase 4E-automation-deletions | Three automation controllers directly deleted scheduled-task, deployment-schedule and scaling-schedule records after authorization. | 45 automation/API/runtime/tenancy/entitlement regression tests passed, 265 assertions; Pint and diff checks passed. | `7ad26fa` — `refactor: extract automation deletion operations` | Characterize web scale and API scale/runtime contracts, then extract only matching runtime transition logic. |
| Phase 4F-runtime-api | Web/API scale and runtime controllers mixed validation, policy/entitlement ordering, persistence, dispatch and response mapping; API access checks were duplicated. | 53 automation/API/runtime/configuration/tenancy/entitlement/platform tests passed, 334 assertions; Pint and diff checks passed. | `49bd49a` — `refactor: extract runtime operations` | Characterize token creation/rotation/deletion ownership, abilities, expiry, entitlement and plaintext feedback before extracting request/action boundaries. |
| Phase 4G-automation-tokens | Token creation, rotation and revocation mixed validation, owner checks, hashed credential creation, replacement ordering and direct deletes in `AutomationController`. | 44 automation/token/API/platform/tenancy tests passed, 261 assertions; Pint and diff checks passed. | `c87e230` — `refactor: extract automation token operations` | Characterize web/API workflow validation and authorization ordering before extracting separate workflow requests around `WorkflowConfiguration`. |
| Phase 4H-workflow | Web/API workflow controllers mixed basic YAML validation with policy/capability checks before invoking the existing transaction-aware `WorkflowConfiguration` service. | 67 automation/workflow/API/configuration/environment/tenancy/entitlement/platform tests passed, 453 assertions; Pint and diff checks passed. | `dc57901` — `refactor: extract workflow request boundaries` | Characterize API variables parsing, secret-safe failures, scoping and transaction/lock behavior before extracting its request/action boundary. |
