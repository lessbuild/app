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

## Phase 4I — API variable replacement operation

### Responsibility problem

`ControlPlaneController::variables()` combined control-plane access,
environment authorization, replacement-text validation, KEY=value parsing,
secret persistence, version creation, deletion of omitted variables and the
JSON response. The parser and transaction were cohesive application behavior,
but the controller was also the HTTP validation boundary and received the
unrestricted request.

### Boundaries applied

- `ReplaceEnvironmentVariablesRequest` preserves API control-plane access and
  environment update authorization before the existing bounded text rules,
  and exposes only the validated replacement text.
- `ReplaceEnvironmentVariablesAction` owns secret-safe line parsing and the
  existing atomic replacement operation, including row locks, current-version
  increments, actor attribution, encrypted variable writes and historical
  versions.
- The controller now coordinates the request, action and unchanged JSON
  envelope. No new generic repository or speculative variable abstraction was
  introduced.

This applies single responsibility and dependency inversion while preserving
the API-specific authorization/validation ordering and the existing
transaction semantics.

### Preserved contracts and safety guarantees

- The existing route, `manage` token ability, environment policy, 422
  `variables` key, parser message, 50 KB bound, 200 response envelope,
  `status=applied` value and count remain unchanged.
- Parsing still occurs before the transaction, so malformed input cannot
  delete or change existing values and submitted values are not included in
  validation responses. Valid values remain encrypted at rest.
- Omitted keys are deleted; retained rows keep `all` scope and secret status,
  increment their versions, record the actor and create version history. The
  existing relationship-scoped deletes, row locks and transaction boundary
  remain in place.

### Verification

- Automation, platform, environment-runtime and shared-tenancy regression
  set: **52 passed, 296 assertions**.
- Added coverage for encrypted-at-rest storage and malformed parser input
  leaving existing variables unchanged without exposing the submitted secret.
- PHP syntax checks, targeted Pint and `pint --test`, and `git diff --check`
  passed.

### Commit and next task

Commit: `28ab03e` — `refactor: extract API variables operation`

**Phase 4I exit gate: complete.** Exact next task: extract the remaining API
promotion request boundary, preserving deploy-token and build-policy ordering,
current-workspace target lookup, validation keys, 404 target behavior and the
existing queued/conflict response envelope.

## Phase 4J — API promotion request boundary

### Responsibility problem

`ControlPlaneController::promote()` still mixed API capability enforcement,
build visibility authorization, request validation, current-workspace target
lookup and response mapping around the existing transaction-aware
`PromoteBuildAction`. The target lookup is an organization-scoped resource
resolution rather than a write, so it remains explicit at the controller
boundary while the request owns only validated input and authorization.

### Boundaries applied

- `Api\V1\PromoteBuildRequest` enforces the existing deploy token capability,
  build visibility policy and target/note validation in the original order.
- The controller uses explicit request accessors, performs the existing
  current-workspace target query and `firstOrFail()` lookup, then invokes
  `PromoteBuildAction` and maps its unchanged result statuses to the existing
  API envelope.

This applies single responsibility and dependency inversion without moving
promotion invariants, locks, notifications or dispatch out of the existing
action.

### Preserved contracts and safety guarantees

- The route, deploy-token requirement, build policy, validation keys and
  nullable note behavior remain unchanged. A missing deploy capability still
  returns 403 before malformed input, and an unavailable scoped target still
  returns 404 before any write.
- The action continues to enforce transaction-time organization/deploy access,
  source attestation, forward-only promotion, target compatibility, active
  deployment protection, lock ordering, approval notifications and dispatch.
- The 202/409 status mapping, deployment serialization, persisted values and
  job compatibility are unchanged.

### Verification

- Build-promotion and automation/API regression set: **48 passed, 220
  assertions**.
- Added coverage for deploy-capability denial before malformed input and
  current-workspace target lookup returning 404 without a promotion write.
- PHP syntax checks, targeted Pint and `pint --test`, and `git diff --check`
  passed.

### Commit and next task

Commit: `92cc670` — `refactor: extract API promotion request`

**Phase 4J exit gate: complete.** Exact next task: begin Phase 5 with metric
alert-rule creation and deletion, introducing resource authorization, a
validated request and a cohesive operation while preserving alert entitlements,
workspace-scoped server IDs and no-write denial behavior.

## Phase 5A — metric alert-rule operations

### Responsibility problem

`ObservabilityController` combined metric-rule workspace permission checks,
alert entitlements, workspace-scoped server validation and direct create/delete
writes. The same controller also handled unrelated destinations, status pages,
incidents and read-side observability data.

### Boundaries applied

- `MetricAlertRulePolicy` owns create/delete actor decisions for the selected
  workspace.
- `StoreMetricAlertRuleRequest` owns the metric-rule field contract and
  organization-scoped server existence rule while preserving authorization and
  entitlement-before-validation ordering.
- `CreateMetricAlertRuleAction` and `DeleteMetricAlertRuleAction` own the
  alert entitlement check and cohesive persistence operation, including actor
  attribution and enabled defaults.
- The controller now coordinates the request/policy/action and retains the
  existing redirect and flash responses.

This applies single responsibility, interface segregation and dependency
inversion without introducing a generic repository or abstraction for unrelated
observability resources.

### Preserved contracts and safety guarantees

- Metric-rule route names, validation keys, metric/operator values, threshold
  bounds, cooldown options, workspace-scoped server IDs, entitlement message,
  redirects and flash text remain unchanged.
- Unauthorized actors still receive 403 before malformed input and cannot
  create or delete rows. Foreign server IDs remain validation failures, and
  deletion remains limited to a manager of the rule's current workspace.
- Rules are still created enabled with the request actor recorded; the action
  rechecks the alerts entitlement so the operation remains safe outside this
  controller. No schema, job, serialization or dependency lockfile changed.

### Verification

- Observability, entitlement and incident regression set: **22 passed, 172
  assertions**.
- Added coverage for create/delete policy boundaries, malformed-input denial,
  organization-scoped server validation and no-write behavior.
- PHP syntax checks, targeted Pint and `pint --test`, and `git diff --check`
  passed.

### Commit and next task

Commit: `ca9d515` — `refactor: extract metric alert rule operations`

**Phase 5A exit gate: complete.** Exact next task: characterize alert
destination creation, endpoint-type validation, encrypted credentials, test
delivery and deletion, then extract its request/policy/action boundaries while
preserving sanitized feedback and queued webhook behavior.

## Phase 5B — alert destination operations

### Responsibility problem

`ObservabilityController` mixed alert-destination authorization, paid-alert
entitlement checks, type-specific endpoint validation, encrypted credential
creation, test-payload construction/dispatch and deletion. The destination
delivery job already owns remote delivery and remains an integration boundary;
the controller should not construct that queued operation.

### Boundaries applied

- `AlertDestinationPolicy` owns create, test and delete decisions for a
  manager of the selected workspace.
- `StoreAlertDestinationRequest` owns common fields and the existing
  email/PagerDuty/HTTPS/Slack/Discord endpoint validation messages while
  preserving entitlement-before-validation ordering and redirect/input
  feedback.
- `CreateAlertDestinationAction`, `QueueAlertDestinationTestAction` and
  `DeleteAlertDestinationAction` own encrypted record creation, bounded test
  dispatch and entitlement-aware deletion respectively.
- The controller now coordinates these boundaries and retains the existing
  flash responses. `DeliverAlertWebhookJob` remains unchanged.

This applies single responsibility, interface segregation and dependency
inversion without introducing a provider strategy for validation variants or a
generic integration repository.

### Preserved contracts and safety guarantees

- Destination routes, field names, supported types/events, endpoint limits,
  provider-specific messages, redirects, queued payload shape and flash text
  remain unchanged. Endpoint and signing-secret values remain encrypted and
  hidden from serialized destination data.
- Foreign or non-manager actors still receive 403 before writes or queued
  delivery. Alert entitlements are checked before validation and rechecked by
  each operation; the delivery job still performs public-HTTPS validation,
  timeouts, signatures, retries and sanitized failure recording.
- Existing custom Slack/Discord redirect-with-input behavior is retained and
  no schema, job serialization, route or dependency lockfile changed.

### Verification

- Observability, entitlement and failure-notification regression set: **32
  passed, 273 assertions**.
- Added coverage for endpoint-type feedback, encrypted storage, test-delivery
  queueing without endpoint leakage, and foreign test/delete denial with no
  side effects.
- PHP syntax checks, targeted Pint and `pint --test`, and `git diff --check`
  passed.

### Commit and next task

Commit: `43334b5` — `refactor: extract alert destination operations`

**Phase 5B exit gate: complete.** Exact next task: characterize status-page
creation/update/deletion, slug collision handling, website pivot membership and
published-page behavior, then extract status-page requests, policy and
transaction-aware actions without changing public status responses.

## Phase 5C — status-page operations

### Responsibility problem

`ObservabilityController` combined status-page authorization, status-page
entitlements, website-ID validation, global slug collision handling, pivot
membership synchronization, transactional create/update writes and deletion.
Public status rendering is a separate read/integration boundary and was left
unchanged.

### Boundaries applied

- `StatusPagePolicy` owns create/update/delete decisions for managers in the
  selected workspace.
- `StoreStatusPageRequest` and `UpdateStatusPageRequest` own page validation
  and organization-scoped website component validation. Separate requests keep
  the original nullable-on-create versus sometimes-on-update slug contracts.
- `CreateStatusPageAction` owns entitlement enforcement, slug collision
  selection and atomic page/pivot creation. `UpdateStatusPageAction` owns
  atomic details/pivot synchronization, and `DeleteStatusPageAction` owns
  entitlement-aware deletion.
- The controller now coordinates these operations and retains the existing
  public-status URL and flash responses.

This applies single responsibility, dependency inversion and interface
segregation without changing public query or serialization code.

### Preserved contracts and safety guarantees

- Status-page routes, field names, slug rules, optional update slug behavior,
  workspace website scoping, global unique-slug fallback, redirects and flash
  text remain unchanged.
- Website pivot synchronization and page detail updates remain in their
  original transactions; foreign pages and component websites cannot be
  modified, and denied requests perform no pivot or page writes.
- Published/private behavior and public status-page responses, health
  aggregation and query-count guarantees remain unchanged. No schema, job,
  route or dependency lockfile changed.

### Verification

- Observability, public-status, entitlement and operational-incident
  regression set: **27 passed, 200 assertions**.
- Added coverage for slug collisions, atomic membership/update behavior,
  unchanged slugs, cross-workspace component rejection and policy denial with
  no mutation.
- PHP syntax checks, targeted Pint and `pint --test`, and `git diff --check`
  passed.

### Commit and next task

Commit: `d84fb6f` — `refactor: extract status page operations`

**Phase 5C exit gate: complete.** Exact next task: characterize status-incident
create/update validation, kind/status compatibility, resolution timestamps,
page scoping and subscriber notification timing, then extract requests and
actions without changing notification ordering.

## Phase 5D — status-incident operations

### Responsibility problem

`ObservabilityController` mixed status-incident validation, kind/status
compatibility, status-page ownership checks, resolution timestamp transitions,
direct persistence and subscriber notification calls. Create and update share a
real validation contract but have different route/resource authorization and
status-page-ID requirements.

### Boundaries applied

- `StatusIncidentRequest` is a concrete shared validation boundary for incident
  and maintenance fields, chronology and kind/status compatibility.
- `StoreStatusIncidentRequest` and `UpdateStatusIncidentRequest` own their
  distinct authorization, entitlement ordering and required-versus-optional
  page ID behavior.
- `StatusIncidentPolicy` owns create and update actor decisions for the current
  workspace.
- `CreateStatusIncidentAction` and `UpdateStatusIncidentAction` own scoped
  persistence, resolution timestamp rules, entitlement rechecks and the
  immediate post-persistence `StatusSubscriberNotifier` call.
- The controller now coordinates requests/actions and keeps the existing flash
  responses; public status rendering and subscriber routes remain unchanged.

This applies single responsibility, dependency inversion and interface
segregation using a shared contract only where create/update semantics truly
match.

### Preserved contracts and safety guarantees

- Incident/maintenance field names, accepted statuses, chronology rules,
  exact kind/status validation message, page scoping, redirects and flash text
  remain unchanged.
- Resolved/completed creates receive a resolution timestamp; updates retain an
  existing timestamp while resolved and clear it when returning to a live
  status. Notification dispatch still occurs after the write through the same
  notifier, with no remote call or transaction-order change.
- Foreign pages/incidents and non-managers still receive 403 before malformed
  input and cannot create/update rows. No schema, route, notification payload
  or dependency lockfile changed.

### Verification

- Observability, public-status, entitlement, incident-notification and
  operational-incident regression set: **30 passed, 231 assertions** using an
  in-memory cache to avoid persistent unique-job locks.
- Added coverage for compatibility validation, resolution transitions,
  notification ordering, scoped pages and no-write authorization denial.
- PHP syntax checks, targeted Pint and `pint --test`, and `git diff --check`
  passed.

### Commit and next task

Commit: `c91bd15` — `refactor: extract status incident operations`

**Phase 5D exit gate: complete.** Exact next task: audit the remaining
`ObservabilityController` read-side query and export boundaries, then begin
organization operations with invitation creation/acceptance while preserving
token/email identity, locks, seat limits and billing synchronization timing.

## Phase 5E — observability dashboard and operational incidents

### Responsibility problem

The observability dashboard assembled several bounded workspace queries directly
in `ObservabilityController`, while `OperationalIncidentController` combined
incident authorization, request validation, workflow-state checks, responder
membership, timeline writes, a transaction and CSV formatting. That left the
operational incident lifecycle difficult to invoke outside HTTP and made the
read/export boundaries inconsistent with the other inventory areas.

### Boundaries applied

- `ObservabilityDashboardQuery` owns the dashboard's existing workspace-scoped
  collections, eager loads and limits. The controller retains only the
  actor-facing permission flags and view response.
- `OperationalIncidentPolicy` owns current-workspace operations permission for
  acknowledge, assign, note and resolve, plus audit/operations permission for
  export. It is registered in `AuthServiceProvider`.
- `AssignOperationalIncidentRequest`,
  `StoreOperationalIncidentNoteRequest` and
  `ResolveOperationalIncidentRequest` own input rules and authorize before
  validation. Validated accessors keep unrestricted request data out of the
  actions.
- `AcknowledgeOperationalIncidentAction`,
  `AssignOperationalIncidentAction`, `AddOperationalIncidentNoteAction` and
  `ResolveOperationalIncidentAction` own the corresponding state/event writes.
  `OperationalIncidentOperationException` keeps business-state and responder
  membership failures out of policies while the controller maps them to the
  existing 422 responses.
- `OperationalIncidentQuery` and `OperationalIncidentExporter` own the scoped
  evidence query and its existing CSV stream. No generic repository or
  speculative abstraction was added.

This applies single responsibility and dependency inversion at both the HTTP
and read/reporting boundaries, while keeping policy decisions separate from
workflow invariants and persistence.

### Preserved contracts and safety guarantees

- Operational incident routes, redirects, success flashes, 403 authorization
  behavior, 422 messages, event types/messages, actor attribution and the
  resolution transaction remain unchanged.
- Authorization still precedes malformed assignment/note input, denied
  actors cannot write incident rows/events or queue work, invalid responders
  do not mutate the incident, and resolved incidents cannot be acknowledged.
- The dashboard keeps its original organization scoping, eager loads, ordering
  and result limits. The evidence export keeps its filename, CSV header,
  ordering, formula-prefix behavior, null conversion and private cache/type
  headers.
- No route, schema, serialized job payload, remote call, notification timing
  or dependency lockfile changed.

### Verification

- Focused observability regression set: **31 passed, 243 assertions** across
  `OperationalIncidentTest`, `ObservabilityTest`, `PublicStatusQueryTest`,
  `IncidentNotificationTest` and `EntitlementTest`.
- Added coverage for malformed unauthorized requests, no-write denial,
  invalid workspace responders, resolved-incident rejection and unauthorized
  evidence export.
- Edited-file PHP syntax checks, Pint test and `git diff --check` passed.

### Commit and next task

Commit: `26e0879` — `refactor: extract operational incident operations`

**Phase 5E exit gate: complete.** Exact next task: characterize organization
invitation creation and acceptance, including token/email identity, expiry and
single-use locking, seat limits, membership pivot behavior and billing
synchronization timing, then extract the smallest policy/request/action slice.

## Phase 5F — organization invitation operations

### Responsibility problem

`OrganizationController` mixed manager authorization, team entitlement
ordering, invite-email normalization and validation, domain/member business
rules, seat-limit locking, hashed-token persistence, notification delivery and
invitation acceptance. Acceptance also performed token/email checks twice,
locked the organization and invitation, changed the membership pivot, changed
the user's current workspace and dispatched billing-seat reconciliation. These
boundaries could not be exercised cleanly outside a controller.

### Boundaries applied

- `OrganizationPolicy::invite` owns the manager decision for the selected
  current workspace. `StoreOrganizationInvitationRequest` performs that
  policy check and the existing team entitlement check before validation, then
  normalizes the email and owns the invite input rules.
- `InviteOrganizationMemberAction` owns domain and existing-member safeguards,
  the existing `PlanLimits::withinLimit` organization lock, invitation
  upsert/token hashing and on-demand notification. The
  `OrganizationInvitationResult` keeps the plaintext token explicit at the
  notification boundary and never persists or flashes it.
- `AcceptOrganizationInvitationRequest` preserves the existing initial 403
  checks for expiry, token hash and invited-email identity. Its token accessor
  supplies only validated query input to `AcceptOrganizationInvitationAction`.
- `AcceptOrganizationInvitationAction` retains the initial check and the
  transaction-time recheck, organization/invitation `lockForUpdate` ordering,
  seat enforcement, `syncWithoutDetaching`, acceptance timestamp, user
  workspace update and post-transaction `SyncOrganizationSeatQuantityJob`.

This applies single responsibility and dependency inversion without turning
token identity into a policy or moving seat/business invariants into a policy.
The notification dispatcher is injected, and the existing PlanLimits service
remains the lock/limit collaborator.

### Preserved contracts and safety guarantees

- Invitation routes, redirects, success flash text, validation keys and
  existing 403/422 behavior remain unchanged. Uppercase invite addresses are
  normalized to the same lowercase persisted identity as before.
- Domain restrictions and existing-member checks reject before invitation
  persistence or notification. Unauthorized managers receive 403 before
  malformed invite input is evaluated and cannot create rows or send mail.
- Only the invited normalized email with the matching hashed token can join.
  Expired, accepted, wrong-email and wrong-token attempts remain denied.
  Acceptance still revalidates under locks, rolls back on seat denial, avoids
  duplicate pivots and queues billing reconciliation only after the membership
  transaction and workspace switch.
- No route, schema, dependency lockfile, notification payload or production
  billing call changed. The queued seat job remains the existing compatible
  class and payload.

### Verification

- Organization, invitation, entitlement, plan-limit and billing-listener set:
  **28 passed, 122 assertions**.
- Added coverage for policy-before-validation ordering, normalized email,
  domain/member rejection without writes or notifications, invited identity,
  one-time acceptance, seat-limit rollback and post-transaction seat-job
  dispatch.
- Full Pint test and `git diff --check` passed.

### Commit and next task

Commit: `be7d410` — `refactor: extract organization invitation operations`

**Phase 5F exit gate: complete.** Exact next task: characterize organization
member role updates, member removal and workspace switching, preserving owner
protection, scoped-member 404 behavior, pivot writes, current-workspace
selection and seat synchronization timing.

## Phase 5G — organization membership operations

### Responsibility problem

`OrganizationController` still made membership pivot changes and current
workspace updates directly. Member-role and removal endpoints also combined
manager checks with owner-protection and scoped-member lookup guards, while
role validation remained inline. Workspace switching used a controller-local
permission check and write.

### Boundaries applied

- `OrganizationPolicy::manageMembers` owns the current-workspace manager
  decision for member management, and `OrganizationPolicy::switch` owns view
  access to a switch target. The switch policy deliberately does not require
  the target to differ from the current workspace.
- `UpdateOrganizationMemberRequest` owns role validation and checks the
  manager policy before validation.
- `UpdateOrganizationMemberAction` and
  `RemoveOrganizationMemberAction` own owner protection, organization-scoped
  member existence, pivot mutation and (for removal) post-write seat-job
  dispatch. Owner failures remain operation exceptions mapped to 422;
  non-member targets remain model-not-found 404s.
- `SwitchOrganizationAction` owns the authenticated user's current-workspace
  write after policy authorization.

This applies single responsibility and dependency inversion while keeping
owner protection and child-scoped lookup out of permission policies. No
generic membership repository or universal action base was introduced.

### Preserved contracts and safety guarantees

- Switch, role-update and removal routes, redirects, success flashes and
  authorization behavior remain unchanged. Authorized members can switch to a
  different workspace; outsiders remain denied.
- The workspace owner cannot be changed or removed and those operations still
  return 422. A user that is not a member of the current workspace remains a
  concealed 404 target. Denied requests do not change pivots, user workspace
  selection or queue jobs.
- Role pivot updates preserve the existing role values. Member removal still
  detaches the pivot before dispatching the existing
  `SyncOrganizationSeatQuantityJob`; no billing call occurs in the request.
- No route, schema, lockfile or job payload changed.

### Verification

- Organization, invitation, entitlement, plan-limit and billing-listener set:
  **30 passed, 140 assertions**.
- Added coverage for authorized target switching, policy-before-malformed
  input, owner protection, scoped-member 404s, pivot updates, removal queue
  timing and no queue on rejected removal.
- Full Pint test and `git diff --check` passed.

### Commit and next task

Commit: `39f2830` — `refactor: extract organization membership operations`

**Phase 5G exit gate: complete.** Exact next task: characterize organization
notification/security settings, including normalization, IP self-lockout
protection, SSO entitlement/configuration rules and named validation behavior,
then extract request/action boundaries without changing security ordering.

## Phase 5H — organization notification and security settings

### Responsibility problem

The two settings endpoints still combined request structure validation,
workspace-manager authorization, network and email-domain normalization and
validation, SSO entitlement/configuration rules, encrypted persistence, and a
shared IP-range predicate inside `OrganizationController` and the security
middleware. That made the controller responsible for both HTTP input handling
and security-policy business invariants.

### Boundaries applied

- `UpdateOrganizationNotificationPreferencesRequest` and
  `UpdateOrganizationSecurityPolicyRequest` authorize through
  `OrganizationPolicy::manageSettings` before validation and expose only
  validated fields to their actions. The security request normalizes
  case-insensitive email-domain input before validation.
- `UpdateOrganizationNotificationPreferencesAction` owns the normalized
  preference write. `UpdateOrganizationSecurityPolicyAction` owns CIDR/domain
  checks, current-IP self-lockout protection, SSO entitlement enforcement,
  SSO completeness validation, and the encrypted policy update.
- `IpRangeMatcher` is the shared, dependency-injected value collaborator for
  the middleware, control-plane access service, and security action. The
  middleware's public compatibility helper remains available to
  `ControlPlaneAccess`.

This applies single responsibility, interface restraint, and dependency
inversion without adding a generic settings repository or moving protocol
checks into a policy. The custom CIDR/domain and SSO checks remain operation
invariants rather than being mislabeled as actor permissions.

### Preserved contracts and safety guarantees

- Existing settings routes, redirects, success flashes, validation keys,
  boolean/default behavior, allowed category/domain/IP formats, current-IP
  protection, and SSO issuer trimming remain unchanged.
- Manager access remains limited to the authenticated user's current
  workspace. Denied malformed requests return 403 before validation and do not
  write workspace settings.
- SSO changes still require the `sso` entitlement, enforcement still requires
  a complete issuer/client ID/secret configuration, and SSO secrets/IP ranges
  remain encrypted at rest and absent from persisted plaintext.
- The existing middleware and control-plane network checks use the same
  matching semantics. No route, schema, lockfile, queue payload, or remote
  call changed.

### Verification

- Organization and platform regression set: **21 passed, 112 assertions**.
- Coverage includes manager/viewer authorization ordering, no-write denial,
  IP self-lockout prevention, encrypted IP persistence, SSO completeness,
  free-plan entitlement denial, normalized SSO configuration and encrypted
  secret persistence.
- Full Pint test and `git diff --check` passed.

### Commit and next task

Commit: `a1be9af` — `refactor: extract organization settings operations`

**Phase 5H exit gate: complete.** Exact next task: characterize workspace
deletion's named `deleteWorkspace` validation bag, owner/current-workspace
authorization, two-factor/password ordering, teammate and active-workflow
guards, transaction and personal-workspace recovery, then extract the
request/action boundary while preserving 403/409/422 behavior.

## Phase 5I — workspace deletion and organization visibility

### Responsibility problem

Workspace deletion still combined owner/current-workspace authorization,
dynamic password and two-factor validation, recovery-code verification,
teammate and active-workflow safeguards, transactional deletion, personal
workspace recovery, and HTTP status mapping in `OrganizationController`.
The organization index also used an inline permission abort for resource
visibility.

### Boundaries applied

- `OrganizationPolicy::delete` owns the owner and current-workspace decision;
  `OrganizationPolicy::view` owns workspace visibility for the organization
  index. The policy methods do not write, query external services, or decide
  workflow-state safety.
- `DeleteOrganizationRequest` owns the dynamic confirmation, local-password
  and two-factor rules and preserves the `deleteWorkspace` error bag.
- `DeleteOrganizationAction` owns two-factor verification, teammate and
  active-build/command guards, the existing deletion transaction, current
  workspace clearing, and post-transaction personal-workspace recovery.
  `OrganizationDeletionOperationException` carries only the operation's
  existing 422/409 response status for the controller to map to HTTP.
- `OrganizationController` now coordinates authorization supplied by the
  request/policy, invokes the action, maps operation status, and returns the
  existing redirect/flash response.

This applies single responsibility and dependency inversion while preserving
the distinction between actor authorization, request validation, and business
state. It does not move destructive-workflow guards into a policy or create a
generic deletion service.

### Preserved contracts and safety guarantees

- Owner-only/current-workspace deletion remains 403 for unauthorized actors,
  including when the submitted payload is malformed. Invalid confirmation,
  password and two-factor responses retain the `deleteWorkspace` bag and
  password non-flashing behavior.
- Recovery-code verification still occurs before teammate and active-operation
  checks, and the existing two-factor service retains its single-use locking.
  Teammates still produce 422; active builds or server commands still produce
  409; neither path deletes records.
- The delete transaction still removes the workspace and clears the actor's
  current workspace before `PersonalOrganization` ensures a new personal
  workspace after commit. Routes, response text, persistence, observers,
  middleware ordering and external provider resources remain unchanged.

### Verification

- Organization deletion, account lifecycle and two-factor regression set:
  **28 passed, 163 assertions**.
- Organization visibility/tenancy regression set: **25 passed, 131
  assertions**.
- Coverage includes named-bag validation, malformed unauthorized deletion,
  invalid 2FA, teammate and active-command safeguards, successful recovery,
  policy-backed workspace visibility, and existing account/2FA behavior.
- Full Pint test and `git diff --check` passed.

### Commit and next task

Commits: `d253ca0` — `refactor: extract organization deletion operation`;
`35719ac` — `refactor: authorize organization workspace page`

**Phase 5 organization exit gate: complete.** Exact next task: begin Phase 6
with provider management by inventorying `ProviderController`, its request,
provider policies/adapters, connection testing/monitoring, inventory queries
and exports before separating shared reads and provider operations.

## Phase 6A — provider write operations and cloud catalog authorization

### Responsibility problem

The provider inventory, connection-history queries, metrics, and exporters
were already separate collaborators in the starting branch, so those reads
were retained rather than re-extracted. The remaining `ProviderController`
write endpoints still performed monitoring entitlement checks, workspace
creation, credential-preserving updates, provider-type attachment safeguards,
health resets, and soft deletion directly. The cloud server catalog also
combined provider authorization and supported-type classification in one
inline guard.

### Boundaries applied

- `ProviderPolicy::create` formalizes the existing pre-validation deployment
  gate used by `ProviderRequest`; the update endpoint retains its later
  `update` policy check so deployment-capable but non-manager actors preserve
  the established validation/authorization ordering. `ProviderPolicy::deploy`
  owns current-workspace cloud-provider access for the server catalog.
- `CreateProviderAction`, `UpdateProviderAction`, and `DeleteProviderAction`
  own provider persistence, monitoring entitlement checks, token omission,
  provider-type and attached-resource rules, health reset behavior, and soft
  deletion. `ProviderOperationException` carries an operation field and the
  existing input-preservation choice for controller response mapping.
- `ProviderServerCatalogController` now uses the provider policy for actor
  access and leaves unsupported provider classification as its existing 403
  resource-capability response. Existing `ProviderConnectionController`,
  `ProviderHealthMonitor`, adapters, history services, and probe semantics
  remain reusable integration boundaries.

This applies single responsibility and dependency inversion without creating
generic provider repositories or changing provider adapter contracts.

### Preserved contracts and safety guarantees

- Provider create/update/delete routes, redirects, validation keys, success
  feedback, provider-type normalization, monitoring defaults and entitlements,
  attached-resource errors, credential omission, health reset behavior and
  soft deletion remain unchanged.
- Encrypted provider tokens remain outside logs, exports and flashed token
  input. Denied create/update attempts return 403 before their relevant
  writes; no provider records are created or changed.
- Provider inventory/history pagination, ordering, filters, CSV escaping,
  connection-test rate limits, sanitized failures, monitoring leases/guards,
  failure thresholds, and DigitalOcean droplets-based probing remain in the
  existing services and adapters. No route, schema, lockfile, queue payload,
  or remote request contract changed.

### Verification

- Provider CRUD, entitlement, monitoring, connection, history, inventory,
  export, tenancy and cloud-catalog regression set: **75 passed, 688
  assertions**.
- Full Pint test and `git diff --check` passed.

### Commit and next task

Commit: `d257128` — `refactor: extract provider operations`

**Phase 6A exit gate: complete.** Exact next task: characterize the remaining
provider integration boundary—manual and automatic connection checks,
provider adapter result/failure contracts, idempotent cloud deletion, and
DigitalOcean droplets probing—then add/strengthen contract coverage and make
only a justified adapter or tester extraction if the semantics require it.

## Phase 6B — provider integration contract verification

### Responsibility problem

The provider adapters and connection tester were already cohesive integration
boundaries. Each cloud adapter translated provider-specific HTTP requests into
the shared `ServerProvider` contract, while `ProviderConnectionTester` kept
credential checks bounded and sanitized. There was no justified common adapter
extraction, but the shared deletion guarantee was not exercised against every
real adapter.

### Boundaries applied

- Retain `ServerProvider` as the capability contract and keep
  `DigitalOcean`, `HetznerCloud`, and `Vultr` responsible for their own API
  endpoints, response normalization and bounded failures.
- Add a real-adapter contract test covering the common idempotent deletion
  behavior: an already absent server or SSH key is success, while another
  provider failure is reported as failure.
- Retain `ProviderConnectionTester` and `ProviderHealthMonitor` as the
  existing manual/automatic check boundaries. Their fixed endpoints,
  DigitalOcean droplets probe, rate limits, leases, stale-result guards and
  sanitized messages already match the required semantics.

This verifies Liskov substitution at the behavior boundary without adding a
speculative base class, generic integration interface or provider repository.

### Preserved contracts and safety guarantees

- All three server providers continue to return the shared boolean deletion
  result and accept HTTP 404 as idempotent success; non-success statuses such
  as 503 remain failures.
- Provider-specific credentials, URLs, HTTP status handling, normalized cloud
  data, SSH-key reuse, cleanup ordering and failure messages remain unchanged.
- Manual and automatic connection checks continue to use bounded requests,
  fixed HTTPS endpoints and provider-safe feedback. DigitalOcean checks use
  the droplets endpoint so scoped deployment tokens do not require account
  access.

### Verification

- Real-adapter contract plus DigitalOcean response/reuse, cloud expansion,
  server deletion, cloud deletion action and provider connection regression
  set: **38 passed, 258 assertions**.
- The preceding provider CRUD and integration regression set remains **75
  passed, 688 assertions**.
- Pint test and `git diff --check` passed.

### Commit and next task

Commit: `0e1379a` — `test: codify server provider contract`

**Phase 6 provider exit gate: complete.** Exact next task: begin Phase 7A
recipe reports by inventorying `RecipeReportsController`, its routes,
validation, authorization, report/history queries, CSV output, mutations and
notification timing before extracting only the justified request/query/action
boundaries.

## Phase 7A — recipe report authorization and review updates

### Responsibility problem

The report feature already had cohesive query, CSV-export and lifecycle-action
boundaries from earlier work. The remaining controller responsibility issues
were a bulk notification write in `reviewUpdates()`, a private-report ownership
abort, a self-reporting permission abort, and a repeated contributor/report
relationship guard. The controller also needed to preserve the distinction
between a resource that is not available for reporting and an actor who is not
allowed to report or review it.

### Boundaries applied

- `ReviewRecipeReportUpdatesAction` owns the authenticated reporter's bulk
  notification update and depends on the existing `RecipeReportQuery` rather
  than taking an HTTP request or returning a response.
- `RecipeReportPolicy::view` owns private status-page ownership and uses
  `denyAsNotFound()` to preserve the existing 404 concealment. Its `review`
  ability owns contributor/report/recipe actor authorization and the same
  route-scoped 404 behavior.
- `RecipePolicy::report` owns the actor's authorship decision. The controller
  retains the publication check as a resource-availability guard, then invokes
  the policy. Existing lifecycle actions retain transaction-time ownership and
  relationship revalidation under locks.
- `RecipeReportsController` now coordinates policy checks, the review action,
  existing validated requests, existing queries/exporters and existing report
  actions. No report persistence write remains in the controller.

This applies single responsibility, dependency inversion and the guard
classification rule without moving publication/state/lock rules into policies
or introducing a generic report service.

### Preserved contracts and safety guarantees

- Private foreign report status and contributor operations remain 404, a recipe
  owner attempting to report remains 403, and an unpublished recipe remains
  404. Request validation still runs at the same boundary before the relevant
  controller authorization, preserving malformed-input behavior and error
  keys.
- Review-update notifications remain scoped to the authenticated reporter's
  unread gallery report updates, retain the existing count and flash messages,
  and do not mark unrelated notifications read.
- Existing report locks, transaction rollback, stale ownership checks,
  encryption/privacy projections, notification timing, pagination, filters,
  CSV headers/escaping, routes and status responses remain unchanged.

### Verification

- Recipe report, report-history, feedback-inbox and report-notification
  regression set: **60 passed, 608 assertions**.
- The controller write audit reports no direct create/update/save/delete or
  relationship mutation; the remaining `abort_unless` is the intentional
  unpublished-resource availability safeguard.
- Targeted Pint test and `git diff --check` passed.

### Commit and next task

Commit: `a5c99fd` — `refactor: move recipe report permissions and review`

**Phase 7A authorization/write sub-slice: complete.** Exact next task:
extract the two substantial GET-filter normalization boundaries from
`RecipeReportsController` into dedicated Form Requests (or a shared immutable
filter contract where the two shapes differ), preserving silent invalid-value
defaults, date-range swapping, query-string pagination and export semantics
before reviewing any remaining controller reads.

## Phase 7B — recipe report filter requests

### Responsibility problem

`RecipeReportsController` still normalized two substantial families of GET
filters itself: the contributor inbox and the reporter's private history. This
made HTTP input handling part of the controller's query orchestration and
allowed raw request data to cross into `RecipeReportQuery` and the exporters.

### Boundaries applied

- `RecipeReportInboxRequest` owns contributor-inbox filter defaults, trimming
  and length bounds, finite-value normalization, positive focus IDs and exact
  date-range normalization through `DateRange`.
- `RecipeReportHistoryRequest` owns reporter-history filter defaults, trimming,
  finite-value normalization and length bounds.
- Both requests expose typed filter arrays through `filters()`. Their
  `validationData()` validates normalized values without mutating the original
  query parameter bag, so Laravel pagination links and export URLs retain the
  existing query-string behavior.
- `RecipeReportsController` now consumes only `filters()` for the four GET
  report/history endpoints; `RecipeReportQuery` and the two existing exporters
  remain shared business/read collaborators.

This applies single responsibility and dependency inversion while keeping the
two request contracts separate because their filter shapes and defaults differ.
It does not introduce a generic request base class or change invalid filters
into user-facing validation errors.

### Preserved contracts and safety guarantees

- Invalid status, availability, update, reason, age, sort, date and focus
  values continue to fall back silently to the previous defaults. Search is
  trimmed and bounded identically, valid reversed dates are swapped, and
  omitted values remain equivalent to the prior request behavior.
- Report ownership scopes, encrypted-field projections, metrics, ordering,
  pagination query strings, CSV headers/escaping, download headers, routes and
  authentication behavior remain unchanged.

### Verification

- Recipe report, report-history, feedback-inbox and report-notification
  regression set: **60 passed, 608 assertions**.
- Targeted Pint test and `git diff --check` passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: `7b845e0` — `refactor: move recipe report filters to requests`

**Phase 7A recipe-report boundary: complete.** Exact next task: audit
`RecipeReportsController` and adjacent gallery/report controllers for any
remaining direct writes, inline permission guards, unclassified resource
lookups or query/export duplication; then begin the next product slice only
after classifying those findings.

## Phase 7C — build approval and operator-note boundaries

### Responsibility problem

`BuildsController` still owned approval/rejection note validation and named
error-bag selection, and its operator-note endpoint directly updated a build
and recorded activity. The existing approval/rejection actions already owned
their locked workflow transitions, while `BuildPolicy` already owned the
resource abilities.

### Boundaries applied

- `BuildApprovalRequest` owns the optional approval/rejection note rules and
  the `approval` error bag. Its authorization calls the existing `approve`
  policy before validation, matching the previous controller ordering for both
  endpoints.
- `BuildNoteRequest` owns operator-note validation and the `buildNote` error
  bag, including the existing blank-to-null normalization.
- `UpdateBuildNoteAction` owns the note change, unchanged-value decision,
  persistence and metadata-only activity recording. It receives an explicit
  build, actor and normalized note, not an HTTP request.
- `BuildsController` now coordinates validated input, existing approval/
  rejection actions, the new note action and the existing dispatch service.

This applies single responsibility and dependency inversion without moving
workflow eligibility, locks, approval notification timing or deployment
dispatch out of their existing operations.

### Preserved contracts and safety guarantees

- Approval/rejection and note routes, statuses, redirects, flash messages,
  validation keys, named bags, trimming/clearing behavior and activity text
  remain unchanged. Unauthorized actors are rejected before malformed note
  validation, as before.
- Approval actions retain transaction-time state checks, approver identity and
  notification behavior. Deployment cancel/redeploy/rollback, comparison,
  repository deployment, idempotency and callback-related behavior remain
  unchanged.
- The controller write audit shows no direct build persistence mutation; the
  comparison same-repository check remains a route/resource relationship
  safeguard, not an actor permission.

### Verification

- Build approval, note, promotion, redeployment, cancellation, comparison,
  history-filter/export and repository deployment/safety regression set:
  **65 passed, 530 assertions**.
- Targeted Pint test and `git diff --check` passed.

### Commit and next task

Commit: `ae1812d` — `refactor: extract build note and approval requests`

**Phase 7C build review slice: complete.** Exact next task: characterize
`BuildsController` and `RepositoriesController` inventory filter requests and
the remaining repository-create/configuration boundary, preserving scoped
IDs, webhook-secret availability, validation ordering and export semantics.

## Phase 7D — build and repository inventory filter requests

### Responsibility problem

`BuildsController` and `RepositoriesController` still normalized substantial
families of inventory and webhook-delivery GET filters beside query,
pagination and CSV orchestration. The repository controller also used its
private status catalog helper to prepare the create/edit view. This left raw
request interpretation in controllers and made the shared inventory/query
boundaries less explicit.

### Boundaries applied

- `BuildIndexRequest` owns build inventory filter defaults, trimming, bounded
  search, finite status/trigger values, positive repository IDs and date-range
  normalization.
- `RepositoryIndexRequest` owns repository inventory filter defaults,
  trimming, bounded search, finite status/provider/website values, positive
  IDs and date-range normalization. Its public `statuses()` catalog keeps the
  existing create/edit view contract explicit.
- `RepositoryWebhookDeliveryRequest` owns delivery-history filter defaults,
  trimming, bounded search, finite status values and date-range normalization.
- Each request exposes a typed `filters()` array. Public `validationData()`
  validates normalized values without changing the original query parameter
  bag, preserving pagination and export query strings.
- The controllers now pass only request-owned filters to the existing query
  collaborators and exporters; no generic filter base class was introduced.

This applies single responsibility and dependency inversion at the HTTP/read
boundary while preserving the existing query and export collaborators.

### Preserved contracts and safety guarantees

- Invalid filter values continue to fall back silently to the prior defaults;
  omitted values, trimming, date swapping, pagination names, ordering,
  organization scoping and resource IDs remain unchanged.
- Inventory metrics, eager loading, CSV headers/escaping, webhook-delivery
  history, download responses, route names and authorization behavior remain
  unchanged.

### Verification

- Build history/filter/export and repository inventory/insight/webhook/safety
  regression set: **38 passed, 375 assertions**.
- Targeted Pint test and `git diff --check` passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: `8938e7d` — `refactor: extract build repository filter requests`

**Phase 7D exit gate: complete.** Exact next task: extract the repository
creation/configuration boundary shared by create and update, preserving
tenant-scoped provider selection, GitHub App webhook-secret availability,
validation ordering, encrypted-secret behavior and the existing 503 response.

## Phase 7E — repository creation and provider webhook configuration

### Responsibility problem

`RepositoriesController` duplicated tenant-scoped provider lookup and the
GitHub App webhook configuration transition in both create and update. The
controller also translated missing integration configuration directly into a
503 response while the update action already owned the website/repository
locking transaction. This made a provider-specific integration rule harder to
exercise outside HTTP and left creation as a direct persistence operation.

### Boundaries applied

- `CreateRepositoryAction` owns provider lookup in the actor's current
  workspace, integration-aware attribute preparation and repository creation.
- `RepositoryWebhookConfiguration` is the concrete shared collaborator for
  GitHub App creation and provider-switch transitions. It enables and secrets
  App repositories, disables and clears the secret when switching away, and
  raises a domain-specific exception when the required configured secret is
  absent.
- `UpdateRepositoryAction` now receives the explicit actor, resolves the
  tenant-scoped target provider, delegates webhook preparation and retains its
  existing website/repository locks and transaction.
- `RepositoriesController` consumes validated attributes, invokes operations
  and maps only the integration-unavailable exception to the existing 503;
  redirects and validation-error mapping remain HTTP concerns.

This applies single responsibility and dependency inversion around a real
provider variant without introducing a generic repository or speculative
provider strategy.

### Preserved contracts and safety guarantees

- GitHub App repositories remain automatically subscribed with the configured
  encrypted secret; switching to a normal source provider still disables and
  clears the App webhook fields.
- Tenant-scoped provider selection, repository URL/branch and encrypted hook
  handling, update placement locks, active-deployment conflict validation,
  redirects and 503 status/message remain unchanged.
- Missing GitHub App configuration fails before repository creation or update,
  and does not queue work. The provider exception is kept out of the shared
  operation so non-HTTP callers are not coupled to redirects or responses.

### Verification

- Repository creation/update, GitHub App, webhook, deployment, provider
  capability, inventory and safety regression set: **57 passed, 486
  assertions**.
- Added no-secret tests for both App repository creation and switching an
  existing repository to an App provider; both prove no persistence side
  effect.
- Pint test and `git diff --check` passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: `2263941` — `refactor: extract repository creation operation`

**Phase 7E exit gate: complete.** Exact next task: characterize
`RepositoryWebhookSettingsController` and its GitLab/non-GitLab contracts,
then extract the request and cohesive enable/disable operations while
preserving policy ordering, GitLab `whsec_` validation, one-time secret flash,
encrypted persistence, pending-revision cleanup and redirect fragments.

## Phase 7F — repository webhook-settings operations

### Responsibility problem

`RepositoryWebhookSettingsController` correctly authorized the repository
before branching on provider type, but then owned conditional signing-token
validation, random secret generation, one-time secret disclosure and direct
enable/disable writes. The disable path also cleared pending webhook state
without a reusable operation boundary.

### Boundaries applied

- `RepositoryWebhookSettingsRequest` owns the request authorization call and
  GitLab-only `whsec_` token validation. Its `authorize()` executes before
  `rules()`, preserving the previous policy-before-validation behavior; other
  providers continue to accept an empty payload.
- `EnableRepositoryWebhookAction` loads the provider, selects the validated
  GitLab token or generates the existing 64-character secret, persists the
  enabled state and returns only the newly generated secret for presentation.
- `DisableRepositoryWebhookAction` owns disabling the webhook, clearing its
  encrypted secret and resetting all pending revision fields.
- The controller now coordinates the request/action and retains only the
  one-time session flash and existing redirect fragment/success response.

This applies single responsibility and dependency inversion while keeping
provider protocol validation separate from actor permission and HTTP response
concerns.

### Preserved contracts and safety guarantees

- Foreign actors still receive the existing policy denial before signing-token
  validation. GitLab token keys, `whsec_` decoding/length rules, provider
  branching, encrypted storage, generated-secret session key, secret display
  behavior, pending cleanup, redirect fragment and success text are unchanged.
- Callback verification, replay protection, delivery history and deployment
  queue behavior remain outside this settings operation.

### Verification

- Repository webhook, GitHub App and resource-authorization regression set:
  **19 passed, 151 assertions**.
- Targeted Pint test and `git diff --check` passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: `12e7927` — `refactor: extract repository webhook settings operations`

**Phase 7F exit gate: complete.** Exact next task: audit remaining recipe,
gallery, ratings/favorites, feedback and notification controllers for direct
writes, inline actor guards, named validation bags and duplicated report/query
semantics before extracting the smallest justified product slice.

## Phase 7G — recipe ratings and favorites

### Responsibility problem

`RecipeRatingsController` and `RecipeFavoritesController` mixed gallery
availability checks, actor eligibility, validation, relation-scoped writes,
idempotency and activity recording. Rating authorization also remained as
inline owner/installation guards, while the actual rating value was validated
inside the controller.

### Boundaries applied

- `RecipePolicy::rate` owns the actor decision that a user is not the recipe
  contributor and has an installed copy in the current workspace.
- `StoreRecipeRatingRequest` preserves the published-recipe 404, invokes the
  rating policy before validation and owns the 1–5 rating rules and explicit
  typed value. Publication is intentionally an availability check, not a
  policy permission.
- `SaveRecipeRatingAction` and `RemoveRecipeRatingAction` own the actor-scoped
  rating upsert/removal and activity wording.
- `SaveRecipeFavoriteAction` and `RemoveRecipeFavoriteAction` own the
  idempotent actor-scoped favorite writes and activity recording. The
  controller retains only the published-resource availability guard because
  favorites have no independent actor capability beyond the scoped relation.
- Controllers now map action outcomes to the existing back redirects and
  status messages; no rating/favorite persistence write remains in them.

This applies single responsibility, dependency inversion and the guard
classification rule without inventing separate policies for user-owned pivot
records.

### Preserved contracts and safety guarantees

- Unauthenticated, unpublished, author, uninstalled and foreign-user cases
  retain their existing redirect/404/403 behavior and validation ordering.
- Rating upsert idempotency, 1–5 rules, favorite idempotency, relation scoping,
  cascade cleanup, activity text/timing, gallery filters, route responses and
  status flashes remain unchanged.

### Verification

- Recipe rating/favorite/activity/gallery/report regression set: **59 passed,
  611 assertions**.
- Targeted Pint test and `git diff --check` passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: `bb9d3e1` — `refactor: extract recipe rating and favorite operations`

**Phase 7G exit gate: complete.** Exact next task: characterize
`RecipeGalleryController` install/refresh transactions and `RecipesController`
create/update/duplicate/delete lifecycle, preserving publication timestamps,
source-revision identity, install-count concurrency, encrypted scripts,
activity timing and existing 404/redirect outcomes.

## Phase 7H — gallery install and refresh operations

### Responsibility problem

`RecipeGalleryController` contained two transaction-heavy lifecycle workflows:
installing a published source as a private copy while preventing duplicates
and incrementing the source count, and refreshing an unpublished copy under
copy/source locks. It also recorded activity after those state transitions.
The controller therefore owned persistence, lock ordering and workflow state
alongside redirect mapping.

### Boundaries applied

- `InstallGalleryRecipeAction` owns the published-source lock, workspace-scoped
  duplicate lookup, private snapshot creation and one-time install-count
  increment. It records activity only after a new copy is committed and
  returns the existing or new copy.
- `RefreshGalleryRecipeAction` owns the copy/source lock order, published-copy
  rejection, snapshot replacement and post-commit activity. It returns the
  existing boolean outcome so the controller can preserve its status branch.
- `RecipeGalleryController` now supplies the authenticated actor and route
  model, invokes the action and retains only policy/availability checks and
  redirect/status mapping.

This applies single responsibility and dependency inversion to actual
transactional operations without changing the public gallery query boundary.

### Preserved contracts and safety guarantees

- Published-source 404 behavior, duplicate-install idempotency, source
  revision identity, encrypted script copy, install-count behavior, copy/source
  lock ordering, published-copy refresh rejection, activity timing/text,
  routes and status flashes remain unchanged.
- Refresh failures still leave the copy and activity state unchanged; public
  gallery availability and copy authorization remain outside the action as
  their classified HTTP/policy boundaries.

### Verification

- Gallery, activity, favorite and rating regression set: **21 passed, 214
  assertions**.
- Targeted Pint test and `git diff --check` passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: `72c9355` — `refactor: extract gallery install operations`

**Phase 7H exit gate: complete.** Exact next task: extract
`RecipesController` create/update publication persistence, duplicate creation
and locked deletion, preserving timestamp/revision rules, encrypted script
handling, notification cleanup, activity timing and existing responses.

## Phase 7I — recipe lifecycle operations

### Responsibility problem

`RecipesController` still combined validated recipe input with publication
timestamp/revision calculation, direct create/update/duplicate writes, report
notification cleanup, SQLite lock preparation and activity recording. Those
rules were cohesive application operations but were only reachable through
the controller.

### Boundaries applied

- `RecipePublication` owns the shared publication/category normalization and
  `published_at`/`gallery_revision_at` identity rules used by create and update.
- `CreateRecipeAction` and `UpdateRecipeAction` own recipe persistence and
  actor-attributed lifecycle activity for their respective operations.
- `DuplicateRecipeAction` owns bounded copy naming, encrypted-script copying
  and duplication activity.
- `DeleteRecipeAction` owns the existing SQLite writer reservation, recipe lock,
  report-notification cleanup, deletion and deletion activity inside the same
  transaction.
- `RecipesController` now coordinates policy checks, validated input, actions
  and existing redirects. Its inventory reads/filters/CSV remain separate for
  a later query/request slice.

This applies single responsibility and dependency inversion to real recipe
lifecycle boundaries without adding a universal save service or repository.

### Preserved contracts and safety guarantees

- Omitted publication fields, category clearing, publication timestamp reuse,
  gallery revision changes, name truncation, encrypted script persistence,
  duplicate semantics, ownership/authorization, notification cleanup, lock
  ordering, activity text/timing, routes and status flashes remain unchanged.
- Recipe deletion still rolls back notification and audit changes if the
  transaction fails; server recipe snapshots remain independent of lifecycle
  edits/deletion.

### Verification

- Recipe management, duplication, activity, gallery, report and report-
  notification regression set: **56 passed, 507 assertions**.
- Targeted Pint test and `git diff --check` passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: `8f594ac` — `refactor: extract recipe lifecycle operations`

**Phase 7I exit gate: complete.** Exact next task: move the remaining recipe
inventory and gallery filter normalization/CSV responsibilities into dedicated
request/query/export boundaries, preserving silent defaults, pagination,
ordering, metrics, eager loading and spreadsheet-safe output.

## Phase 7J — recipe inventory query and export boundaries

### Responsibility problem

RecipesController still interpreted inventory query parameters, built the
workspace-scoped recipe query repeatedly for listing metrics, and rendered the
private CSV stream. Those read concerns were reusable between HTML and export
but were coupled to the controller's HTTP methods.

### Boundaries applied

- RecipeIndexRequest owns normalized search/usage filter defaults and exposes
  the explicit filters() contract. Its public validationData() preserves raw
  query parameters for pagination links and exports while invalid filters
  continue to fall back silently.
- RecipeInventoryQuery owns current-workspace filtering and the related
  usage/assignment/latest-update metrics.
- RecipeInventoryExporter owns the CSV filename, headers, eager loading,
  lazy batch size and spreadsheet-safe cell escaping.
- RecipesController now coordinates the request, query collaborator and
  exporter; recipe lifecycle actions remain separate.

This applies single responsibility and dependency inversion to a real shared
read boundary without adding a generic repository abstraction.

### Preserved contracts and safety guarantees

- Workspace scoping, search/usage defaults, SQL wildcard escaping, ordering,
  pagination query strings, metrics, eager loading, lazy export batching,
  UTF-8 BOM, CSV headers/escaping, script exclusion, response headers and
  authentication behavior remain unchanged.

### Verification

- Recipe inventory filter/export/insight and management regression set:
  **18 passed, 134 assertions**.
- Targeted Pint test and git diff --check passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: 5852cea — refactor: extract recipe inventory queries

**Phase 7J exit gate: complete.** Exact next task: extract gallery index
filter normalization and the shared published-gallery query/metrics boundary,
preserving personal scopes, aggregate ordering, eager-loaded user state,
pagination and public-resource 404 behavior.

## Phase 7K — gallery query and filter boundaries

### Responsibility problem

RecipeGalleryController still normalized gallery query parameters and owned the
published-gallery query, personal collection scopes and aggregate metrics. That
made the HTTP controller responsible for reusable read composition in addition
to response orchestration.

### Boundary and principles

RecipeGalleryIndexRequest now owns the existing silent defaults and normalized
search, category, scope and sort values. RecipeGalleryQuery owns the published
gallery query and metrics; the controller retains eager loading, presentation
ordering, pagination and resource response behavior. This applies single
responsibility and dependency inversion without introducing a generic
repository or changing the existing Eloquent semantics.

### Preserved guarantees

- SQL wildcard escaping, category/scope/sort defaults and authentication
  behavior remain unchanged.
- Mine, favorites, reported, open/resolved, installed and updates scopes keep
  their existing user and publication boundaries.
- Aggregate counts, sort inputs, eager-loaded state, pagination and public
  resource 404 behavior remain unchanged.
- Install, refresh, compare and publication operations remain on their
  previously verified actions and controller response mappings.

### Verification

- Gallery regression set: **11 passed, 123 assertions**.
- Related favorites, ratings, reports and history set: **29 passed, 355
  assertions**.
- Targeted Pint test and git diff --check passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: b3985f2 — refactor: extract gallery inventory queries

**Phase 7K exit gate: complete.** Exact next task: audit remaining feedback and
access-administration controllers for direct writes, inline validation,
permission guards, named error bags and notification/history side effects.

## Phase 7L — product feedback operations

### Responsibility problem

ProductFeedbackController mixed feedback validation, selected-workspace role
checks, ownership decisions and encrypted feedback writes across store, update
and destroy endpoints.

### Boundary and principles

StoreProductFeedbackRequest and UpdateProductFeedbackRequest now own the exact
write validation rules. ProductFeedbackPolicy owns member submission, manager
review and submitter/manager deletion decisions. CreateProductFeedbackAction,
UpdateProductFeedbackAction and DeleteProductFeedbackAction own persistence and
the resolved-at transition. The controller keeps filtering, presentation and
existing redirects. This applies single responsibility and dependency
inversion while reusing the model's encrypted casts.

### Preserved guarantees

- Feedback remains private and workspace-scoped, with the existing 403 denial
  behavior for foreign reviewers and deletions.
- Validation keys, page URL restrictions, payload limits, status values,
  success messages and redirects remain unchanged.
- Review attribution, encrypted response storage and resolved/closed timestamp
  behavior remain unchanged.
- Policy authorization runs before review validation, so denied actors do not
  receive a different validation response or cause a write.

### Verification

- Product feedback regression and authorization-ordering set: **5 passed, 31
  assertions**.
- Targeted Pint test and git diff --check passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: 0aabe54 — refactor: extract product feedback operations

**Phase 7L exit gate: complete.** Exact next task: extract platform access
request administration, preserving platform-admin denial, accepted-request
immutability, invitation token lifecycle, notification timing and CSV output.

## Phase 7M — platform access-request review boundary

### Responsibility problem

AdminAccessRequestController combined a platform-only permission guard, review
payload validation, accepted-request lifecycle invariants, invitation-token
mutation, notification delivery and response mapping.

### Boundary and principles

The platform-admin gate now owns the non-resource administration decision, and
UpdateAccessRequestRequest owns review validation. ReviewAccessRequestAction
owns review persistence, accepted-record safeguards, invitation issuance and
notification dispatch. The controller retains filtered listing/export reads
and maps the action's business exception to the existing 422 response. This
keeps protocol-independent workflow rules reusable while preserving the
existing controller contract.

### Preserved guarantees

- Platform-admin requests still receive 403 before review validation; accepted
  records remain immutable and unaccepted records cannot be marked accepted.
- Invitation tokens are still hashed, rotated only on first invitation or an
  explicit resend, expired through the existing service, and cleared when
  leaving invited status.
- Invitation email routing, queued notification type, expiry period, review
  attribution, filtered ordering, private export headers and CSV escaping are
  unchanged.

### Verification

- Access-request administration and invitation regression set: **13 passed, 97
  assertions**.
- Targeted Pint test and git diff --check passed.
- No dependency or lockfile changes.

### Commit and next task

Commit: b4905c5 — refactor: extract access request review operation

**Phase 7M exit gate: complete.** Exact next task: extract the public
access-request intake boundary, preserving honeypot no-op behavior, normalized
email deduplication, pending-only updates, encrypted persistence and applicant
and administrator notification timing.

## Phase 7N — public access-request intake boundary

### Responsibility problem

AccessRequestController mixed public registration availability checks, honeypot
handling, applicant validation and normalization, email deduplication, pending
record updates, encrypted persistence and notification delivery.

### Boundary and principles

StoreAccessRequestRequest now owns the applicant rules and normalization, with
an explicit Laravel lifecycle exception to preserve the existing pre-validation
redirect for open registration and successful honeypot no-ops. The request's
applicant accessor exposes only validated fields. SubmitAccessRequestAction owns
deduplication, pending-only updates and applicant/administrator notifications;
the controller retains the public response and honeypot redirect. This applies
single responsibility and dependency inversion without changing anonymous
registration semantics.

### Preserved guarantees

- Open registration still redirects to account creation before validating the
  access form; closed-registration validation keeps its existing keys and
  errors.
- Honeypot submissions remain successful no-ops without persistence or
  notification side effects.
- Email lowercasing, trimming, SHA-256 deduplication, encrypted fields,
  pending-only replacement and accepted/declined decision preservation remain
  unchanged.
- New-record applicant/admin notifications retain their recipients, types and
  timing; duplicate and decided requests remain non-disclosing.

### Verification

- Public and administration access-request regression set: **13 passed, 97
  assertions**.
- Targeted Pint test and git diff --check passed on the isolated test
  configuration using in-memory SQLite, array cache/session and sync queue.
- No dependency or lockfile changes.

### Commit and next task

Commit: fa31c24 — refactor: extract access request intake operation

**Phase 7N exit gate: complete.** Exact next task: extract notification inbox
filter normalization/query/export responsibilities and notification state
operations, preserving ownership concealment, bulk counts, saved preferences,
CSV payload redaction and redirect messages.

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
| Phase 4I-api-variables | API variable replacement mixed control-plane access, environment authorization, bounded text validation, secret-safe parsing, versioned persistence and the JSON response in `ControlPlaneController`. | 52 automation/platform/environment-runtime/shared-tenancy tests passed, 296 assertions; Pint and diff checks passed. | `28ab03e` — `refactor: extract API variables operation` | Extract the remaining API promotion request boundary, preserving deploy-token and build-policy ordering, current-workspace target lookup, validation keys, 404 target behavior and the existing queued/conflict response envelope. |
| Phase 4J-api-promotion | API promotion mixed capability/policy checks, validation, tenant-scoped target lookup and response mapping around `PromoteBuildAction`. | 48 build-promotion/automation/API tests passed, 220 assertions; Pint and diff checks passed. | `92cc670` — `refactor: extract API promotion request` | Begin Phase 5 with metric alert-rule creation and deletion, introducing resource authorization, a validated request and a cohesive operation while preserving alert entitlements, workspace-scoped server IDs and no-write denial behavior. |
| Phase 5A-metric-rules | Observability metric-rule endpoints mixed workspace permission, alert entitlement, scoped server validation and direct persistence/deletion. | 22 observability/entitlement/incident tests passed, 172 assertions; Pint and diff checks passed. | `ca9d515` — `refactor: extract metric alert rule operations` | Characterize alert destination creation, endpoint-type validation, encrypted credentials, test delivery and deletion, then extract its request/policy/action boundaries while preserving sanitized feedback and queued webhook behavior. |
| Phase 5B-alert-destinations | Alert-destination endpoints mixed authorization, entitlement, type-specific endpoint validation, encrypted credential creation, test dispatch and deletion. | 32 observability/entitlement/failure-notification tests passed, 273 assertions; Pint and diff checks passed. | `43334b5` — `refactor: extract alert destination operations` | Characterize status-page creation/update/deletion, slug collision handling, website pivot membership and published-page behavior, then extract status-page requests, policy and transaction-aware actions without changing public status responses. |
| Phase 5C-status-pages | Status-page endpoints mixed authorization, entitlement, scoped website validation, slug collision handling, pivot synchronization, transactions and direct writes. | 27 observability/public-status/entitlement/operational-incident tests passed, 200 assertions; Pint and diff checks passed. | `d84fb6f` — `refactor: extract status page operations` | Characterize status-incident create/update validation, kind/status compatibility, resolution timestamps, page scoping and subscriber notification timing, then extract requests and actions without changing notification ordering. |
| Phase 5D-status-incidents | Status-incident endpoints mixed shared validation, kind/status rules, page scoping, resolution transitions, direct writes and subscriber notification timing. | 30 observability/public-status/entitlement/incident-notification/operational-incident tests passed, 231 assertions; Pint and diff checks passed. | `c91bd15` — `refactor: extract status incident operations` | Audit remaining `ObservabilityController` read/export boundaries, then begin organization invitation operations while preserving token/email identity, locks, seat limits and billing synchronization timing. |
| Phase 5E-observability-incidents | The observability dashboard and operational-incident controller mixed bounded reads, policy decisions, validation, workflow guards, timeline writes, transactions and CSV formatting. | 31 observability/public-status/incident/entitlement tests passed, 243 assertions; Pint and diff checks passed. | `26e0879` — `refactor: extract operational incident operations` | Characterize organization invitation creation and acceptance, including token/email identity, expiry and single-use locks, seat limits, membership pivots and billing synchronization timing. |
| Phase 5F-organization-invitations | Organization invitation endpoints mixed policy, entitlement, normalization, validation, domain/member rules, seat locking, hashed-token persistence, notifications and locked acceptance. | 28 organization/invitation/entitlement/plan-limit/billing tests passed, 122 assertions; Pint and diff checks passed. | `be7d410` — `refactor: extract organization invitation operations` | Characterize member role updates, member removal and workspace switching, preserving owner protection, scoped-member 404 behavior, pivot writes, current-workspace selection and seat synchronization timing. |
| Phase 5G-organization-membership | Member role/removal and workspace switch endpoints mixed policy checks, scoped member lookup, owner safeguards, pivot/current-workspace writes and seat dispatch in `OrganizationController`. | 30 organization/invitation/entitlement/plan-limit/billing tests passed, 140 assertions; Pint and diff checks passed. | `39f2830` — `refactor: extract organization membership operations` | Characterize notification/security settings, including normalization, IP self-lockout protection, SSO entitlement/configuration rules and named validation behavior. |
| Phase 5H-organization-settings | Organization settings endpoints mixed manager authorization, request validation/normalization, IP/domain/SSO invariants, encrypted persistence and shared IP matching in the controller and middleware. | 21 organization/platform regression tests passed, 112 assertions; Pint and diff checks passed. | `a1be9af` — `refactor: extract organization settings operations` | Characterize workspace deletion's named `deleteWorkspace` validation bag, owner/current-workspace authorization, two-factor/password ordering, teammate and active-workflow guards, transaction and personal-workspace recovery, then extract the request/action boundary. |
| Phase 5I-organization-deletion | Workspace deletion and organization-index visibility mixed policy decisions, dynamic named-bag validation, 2FA verification, workflow safety guards, transactional deletion/recovery and HTTP status mapping in `OrganizationController`. | 28 deletion/account/2FA tests passed, 163 assertions; 25 organization/tenancy tests passed, 131 assertions; Pint and diff checks passed. | `d253ca0` + `35719ac` — `refactor: extract organization deletion operation`; `refactor: authorize organization workspace page` | Begin Phase 6 provider management: inventory provider reads/exports, connection testing/monitoring, request validation, policies, adapters, entitlements and encrypted credential safety. |
| Phase 6A-provider-operations | Provider CRUD mixed entitlement checks, credential-preserving updates, attachment safeguards, health resets and direct writes; cloud catalog access used a combined inline guard. | 75 provider/entitlement/monitoring/history/inventory/export/cloud-catalog tests passed, 688 assertions; Pint and diff checks passed. | `d257128` — `refactor: extract provider operations` | Characterize manual/automatic connection-check and adapter contracts, idempotent cloud deletion and DigitalOcean droplets probing; strengthen contract coverage and extract only a justified integration boundary. |
| Phase 6B-provider-contracts | Real provider adapters shared an idempotent deletion contract without a common test proving 404-success versus other-failure behavior. | 38 provider/integration/deletion tests passed, 258 assertions; Pint and diff checks passed. | `0e1379a` — `test: codify server provider contract` | Begin Phase 7A recipe reports: inventory report/history queries, filters, CSV, authorization, mutations, locks and notification timing before extracting justified boundaries. |
| Phase 7A-report-auth-and-review | RecipeReportsController retained a notification bulk write and actor guards alongside already-extracted report queries, exporters and lifecycle actions. | 60 recipe report/history/inbox/notification tests passed, 608 assertions; Pint and diff checks passed. | `a5c99fd` — `refactor: move recipe report permissions and review` | Extract the reporter and contributor GET-filter normalization boundaries into Form Requests or immutable filter contracts without changing silent defaults or export/pagination behavior. |
| Phase 7B-report-filter-requests | RecipeReportsController normalized contributor-inbox and reporter-history GET filters alongside query and export orchestration. | 60 recipe report/history/inbox/notification tests passed, 608 assertions; Pint and diff checks passed. | `7b845e0` — `refactor: move recipe report filters to requests` | Audit recipe/gallery/report controllers for remaining direct writes, inline permission guards, unclassified lookups and query/export duplication before the next product slice. |
| Phase 7C-build-review | BuildsController validated approval/operator notes and directly wrote operator notes alongside already-extracted workflow actions. | 65 build/repository/deployment tests passed, 530 assertions; Pint and diff checks passed. | `ae1812d` — `refactor: extract build note and approval requests` | Characterize build/repository inventory filter requests and repository creation/configuration boundary, preserving scoped IDs, webhook-secret availability, validation ordering and exports. |
| Phase 7D-build-repository-filters | Build and repository controllers normalized inventory and webhook-delivery filters alongside query/export orchestration. | 38 build/repository filter, export, insight, webhook and safety tests passed, 375 assertions; Pint and diff checks passed. | `8938e7d` — `refactor: extract build repository filter requests` | Extract the repository creation/configuration boundary shared by create and update, preserving tenant-scoped provider selection, webhook-secret availability, validation ordering, encrypted-secret behavior and the 503 response. |
| Phase 7E-repository-creation | Repository create/update duplicated tenant provider lookup and GitHub App webhook configuration around direct creation and an existing locked update action. | 57 repository/GitHub App/webhook/deployment/provider/inventory/safety tests passed, 486 assertions; Pint and diff checks passed. | `2263941` — `refactor: extract repository creation operation` | Extract repository webhook-settings request and enable/disable operations, preserving GitLab token validation, one-time secret flash, encrypted persistence, pending cleanup and redirect behavior. |
| Phase 7F-repository-webhook-settings | Repository webhook settings mixed GitLab protocol validation, generated-secret disclosure, encrypted enable/disable writes and pending-state cleanup in the controller. | 19 repository webhook/GitHub App/authorization tests passed, 151 assertions; Pint and diff checks passed. | `12e7927` — `refactor: extract repository webhook settings operations` | Audit recipe, gallery, ratings/favorites, feedback and notification controllers for remaining direct writes, inline actor guards, named bags and duplicated report/query semantics. |
| Phase 7G-recipe-ratings-favorites | Rating/favorite controllers mixed availability/actor guards, validation, relation-scoped persistence, idempotency and activity recording. | 59 recipe rating/favorite/activity/gallery/report tests passed, 611 assertions; Pint and diff checks passed. | `bb9d3e1` — `refactor: extract recipe rating and favorite operations` | Extract gallery install/refresh and recipe lifecycle operations, preserving publication timestamps, source revisions, install-count concurrency, encrypted scripts, activity timing and response outcomes. |
| Phase 7H-gallery-install-refresh | Gallery install/refresh transactions and activity recording remained inside `RecipeGalleryController`. | 21 gallery/activity/favorite/rating tests passed, 214 assertions; Pint and diff checks passed. | `72c9355` — `refactor: extract gallery install operations` | Extract recipe create/update publication persistence, duplicate creation and locked deletion, preserving timestamps, revisions, encrypted scripts, notification cleanup, activity timing and responses. |
| Phase 7I-recipe-lifecycle | Recipe controller mixed publication metadata rules, direct create/update/duplicate writes, locked deletion, report-notification cleanup and activity recording. | 56 recipe management/duplication/activity/gallery/report tests passed, 507 assertions; Pint and diff checks passed. | `8f594ac` — `refactor: extract recipe lifecycle operations` | Extract recipe inventory and gallery filter normalization/CSV/query boundaries, preserving silent defaults, pagination, ordering, metrics, eager loading and spreadsheet-safe output. |
| Phase 7J-recipe-inventory | Recipe inventory controller mixed filter normalization, repeated workspace queries/metrics and private CSV rendering. | 18 recipe inventory/filter/export/insight/management tests passed, 134 assertions; Pint and diff checks passed. | `5852cea` — `refactor: extract recipe inventory queries` | Extract gallery filter normalization and the shared published-gallery query/metrics boundary, preserving personal scopes, aggregate ordering, eager-loaded state, pagination and public 404 behavior. |
| Phase 7K-gallery-query | RecipeGalleryController mixed filter normalization, published-gallery scope composition and aggregate metrics with HTTP response orchestration. | 40 gallery/favorite/rating/report/history tests passed, 478 assertions; Pint and diff checks passed. | `b3985f2` — `refactor: extract gallery inventory queries` | Audit remaining feedback and access-administration controllers for direct writes, inline validation, permission guards, named error bags and notification/history side effects. |
| Phase 7L-product-feedback | ProductFeedbackController mixed validation, workspace-role/ownership checks and encrypted feedback writes. | 5 product-feedback authorization/validation/persistence tests passed, 31 assertions; Pint and diff checks passed. | `0aabe54` — `refactor: extract product feedback operations` | Extract platform access-request administration, preserving platform-admin denial, accepted-request immutability, invitation token lifecycle, notification timing and CSV output. |
| Phase 7M-access-review | AdminAccessRequestController mixed platform-admin authorization, review validation, accepted-state invariants, invitation mutation and notification dispatch. | 13 access-request/invitation administration tests passed, 97 assertions; Pint and diff checks passed. | `b4905c5` — `refactor: extract access request review operation` | Extract public access-request intake, preserving honeypot no-op behavior, normalized email deduplication, pending-only updates, encrypted persistence and notification timing. |
| Phase 7N-access-intake | AccessRequestController mixed registration availability, honeypot handling, applicant validation/normalization, deduplication, encrypted persistence and notifications. | 13 public/administration access-request tests passed, 97 assertions; Pint and diff checks passed. | `fa31c24` — `refactor: extract access request intake operation` | Extract notification inbox filters/query/export and state operations, preserving ownership concealment, bulk counts, saved preferences, payload redaction and redirects. |
