# BuildPusher product expansion progress

Latest continuation: September 17 provider-backed preview-stack acceptance
completed on the isolated dev runtime. The signed webhook, independent preview
configuration, managed PostgreSQL/Valkey readiness, revision update, health
failure recovery, close/reopen generation isolation and exact cleanup evidence
are in [the dedicated verification record](preview-stack-acceptance-2026-09-17.md).
The disposable server, source website, repository, project and preview
resources were removed. The next task is final cross-feature verification and
release handoff, with the remaining production, monitoring, billing/SSO,
GitHub App, other-provider and live-acceptance gates kept explicit.

Status: Local product-expansion implementation through Phase 9 and the
authorized disposable provider deployment/rollback/backup/restore/cleanup
drill are complete. The fixed server-host diagnostic, minimal persisted
troubleshooting-session authorization/lifecycle boundary, bounded server-side
transport/process-ownership boundary, durable encrypted frame relay, bounded
broker ownership command and policy-authorized troubleshooting session
lifecycle HTTP boundary are complete locally; the typed category-aware
control-plane report is also complete. Supervisor installation wiring is
complete in the daemon installer contract. Deterministic disposable host
checks have verified the real application SSH adapter across five sequential
local connections, normal transient-systemd disconnect cleanup and local
worker-loss restart cleanup. An isolated Debian 12 LXD system container with
systemd, OpenSSH and its own network namespace has also verified installed-
style normal stop, worker-loss restart, network-partition cleanup and
new-session-only reconnect. A full VM could not fit in the available
workspace disk, so this is host-equivalent local evidence rather than a VM or
cloud-provider claim. The interactive terminal route/UI remains deliberately
gated. Final local release-gate checks are recorded below; production and
provider-specific acceptance gates remain separate.
Phase 7G's organization-owned named investigation views and Phase 7F's
disabled-by-default revision-aware post-deployment
observation aggregate, leased execution, bounded build-detail read surface and
environment evidence integration are also complete. The read-only
context, finite troubleshooting filters, validated share link, stable alert
metadata and shared website health probe are implemented and tested.
Preview safety, trust/secret boundaries,
responsive navigation, first-deployment guidance, recorded configuration
authoring/comparison, explicit provider observations, a template-driven preview
stack manifest, callback-backed local resource readiness, retryable
ownership-aware cleanup, atomic concurrent-preview quotas, explicit
initialization/resource credential boundaries, normalized provider readiness
observations, the versioned curated service-template contract, the Node
resource composition, the lifecycle characterization, deployment evidence
timeline, incident links from deployment cards, the conservative monorepo
path-filter slice, per-service
repository-root execution boundary and read-only multi-target impact preview
are complete. No additional service is published without lifecycle support.
The representative provider-backed Laravel preview stack is now also verified
through the real signed webhook and disposable DigitalOcean lifecycle; this
does not imply acceptance for every provider, production integration or the
separate live drill.
Phase 7D characterization confirms that the existing notification saved-filter
preference is not an appropriate cross-resource observability store, and the
initial shareable link is deliberately stateless and non-secret. Phase 7F now
stores an opt-in, encrypted-build-payload snapshot as a separate
revision-bound observation aggregate after a successful deployment. It is
disabled by default, requires the existing monitoring entitlement when
enabled, queues a unique leased worker after commit and supersedes older active
observations for the same website/repository under a locked transaction. The
worker probes outside the transaction, retries unexpected failures within a
bounded queue budget, recovers due work and expired leases every minute and
rejects stale claim/revision/target results. Build detail shows bounded status
metadata only; claim tokens and remote error text are not rendered.
Provider-backed preview-stack acceptance, the separate live drill and broader
runtime/target recovery coverage remain outstanding. The local installed-host-equivalent
troubleshooting lifecycle gate is recorded below; no production or
acceptance-drill resource was used.

The fixed server-host diagnostic now has its own policy-authorized,
asynchronous snapshot boundary. It requires the stored SSH host identity,
executes only a versioned application-owned scalar probe, persists typed checks
rather than raw output, and keeps one latest result per server. Leased queue
claims, bounded transport retries, terminal sanitized failures and attempt
tokens prevent duplicate work, secret disclosure and stale completions. The
existing arbitrary server command, metrics, log, provisioning and
provider-health paths remain separate. Interactive terminal execution is not
implied by this diagnostic.

Date: 2026-09-16

Integration branch: `main`

Planning reference inspected: `e3006c2` (`main`)

## Working rules

This ledger is the source of truth for the product-improvement slices. Each slice
must state the user problem, the existing implementation reused, the proposed
responsibility boundary, preserved contracts, verification evidence, commit and
push status, and the exact next task before another slice starts.

The canonical checkout remains the live application checkout. Phase 0 was run in
the separate temporary clone:

`/tmp/buildpusher-product-expansion-uHhkwZ`

The temporary clone has its own Composer `vendor/`, npm modules, `.env`,
application key, SQLite database, storage, cache, sessions and built assets. No
production credentials were copied and the acceptance-drill checkout was not
used or modified. The disposable SQLite database resolved to:

`/tmp/buildpusher-product-expansion-uHhkwZ/storage/database/product-expansion.sqlite`

Before Artisan was run, the resolved paths were checked and were all below the
temporary clone:

```text
base=/tmp/buildpusher-product-expansion-uHhkwZ
database=/tmp/buildpusher-product-expansion-uHhkwZ/storage/database/product-expansion.sqlite
storage=/tmp/buildpusher-product-expansion-uHhkwZ/storage
cache=/tmp/buildpusher-product-expansion-uHhkwZ/storage/framework/cache/data
sessions=/tmp/buildpusher-product-expansion-uHhkwZ/storage/framework/sessions
filesystem=/tmp/buildpusher-product-expansion-uHhkwZ/storage/app
```

The required runtime was used for application commands:

`/root/.local/share/buildpusher/php-8.5.10/bin/php`

The only untracked file in the canonical checkout before this ledger was the
user-supplied plan at `docs/controller-modernization-luna-max-plan.md`; it was
preserved and not staged.

## Phase 0 — inventory and fresh baseline

### Entry-point inventory

The current source contains 389 routes when package/framework routes are
included, 315 named routes, 70 controllers, 117 Form Requests, 25 policies,
181 actions and 27 jobs. The route and class counts are an inventory aid, not a
target for arbitrary extraction.

| Feature family | Current user journey and entry points | Existing boundaries and evidence | Classification and gap | Proposed improvement and completion criteria |
| --- | --- | --- | --- | --- |
| Authentication, account, organizations and billing | Registration/login, email verification, password reset/change, sessions, 2FA, social accounts, invitations, membership/security settings, workspace switching, account deletion and billing checkout. Main entry points are the `Auth/*` controllers, `TwoFactorAuthenticationController`, `UsersController`, `OrganizationController`, `Account*Controller` and `BillingController`. | `app/Actions/Account`, `app/Actions/Organization`, `app/Actions/Billing`; account, organization, token and security requests; `OrganizationPolicy`; session/2FA services; Cashier integration. Covered by registration, session-revocation, 2FA, organization, entitlement and billing feature tests. | Existing and locally verified. Production mail, approved Stripe activation, SSO configuration and live identity-provider acceptance remain external. | Preserve password/session/2FA invariants, named error bags, membership pivots, invitation locks, owner protection and entitlement behavior while later improving adjacent journeys. Completion requires local regression coverage plus separately recorded external acceptance. |
| Projects, environments and configuration as code | Create and manage projects, environments, processes, variables and resources; author, review, apply, cancel and retry configuration through web and API; inspect receipts and recover operations. Entry points are `ProjectController`, `EnvironmentController`, `ApplicationConfigurationController` and `Api\\V1\\ControlPlaneController`. | `app/Actions/Project`, `app/Actions/Environment`; configuration requests; `ProjectPolicy`, `EnvironmentPolicy`, `EnvironmentResourcePolicy`, `ConfigurationApplicationPolicy` and `ConfigurationReviewPolicy`; `ApplicationConfigurationPlanner`, `ApplicationConfigurationReconciler`, `ApplicationConfigurationTransaction`, `ApplicationConfigurationDelivery`, `ApplicationConfigurationExecution` and `WorkflowConfiguration`; configuration jobs and extensive feature/API/OpenAPI tests. | Existing and locally verified with known product gaps. Desired state, recorded state and observed remote state need a clearer authoring/editor, dependency overview, safe environment comparison and explicitly read-only drift report. | Add guided authoring, schema/structure feedback, readable change summaries, dependency list/table and secret-safe comparisons. Corrective changes must use review/apply. Preserve reviewed-input identity, secret-version revalidation, ownership/adoption, no-op identity, removal safeguards, atomic claims, leases, stale callbacks, API envelopes and YAML compatibility. |
| Providers, cloud inventory and imports | Add/update/delete cloud providers, test connections, inspect provider inventory, import existing servers/websites and provision servers. Entry points include `ProviderController`, `ProviderConnectionController`, `ProviderServerCatalogController`, `ServersController`, `ImportServerController` and `ImportWebsiteController`. | `app/Actions/Provider`, `app/Actions/Server`, `app/Actions/Web`; `ProviderRequest`, `ServerRequest`, import requests; provider/server/website policies; `ProviderConnectionTester`, `ProviderHealthMonitor`, `ServerProviderResolver`, provider contracts/adapters and provisioning jobs. Inventory, scoped-token, connection, server lifecycle and import tests exist. | Existing and locally verified for fakes and local workflows. Live provider credentials, real provisioning, provider cleanup and monitoring heartbeat acceptance are outstanding. | Improve first-deployment preflight so missing permissions, invalid credentials and entitlement limits are distinct and actionable. Preserve encrypted tokens, secret exclusion, provider-specific probes, retries, leases, ownership and sanitized failures. Verify each adapter through shared behavioral contract tests before extending variants. |
| Websites, repositories, builds and deployments | Create/import websites, connect repositories, deploy, approve/reject/promote/rollback/cancel, switch releases, inspect build progress/logs and configure webhooks. Entry points include `WebsitesController`, `RepositoriesController`, `BuildsController`, `BuildPromotionController`, callback controllers and repository webhook controllers. | `app/Actions/Web`, `app/Actions/Repository`; website/repository/build/callback requests; `WebsitePolicy`, `RepositoryPolicy`, `BuildPolicy`; `DeploymentRequest`, `RepositoryDeploymentPlan`, `DeploymentFailureGuidance`, health services, source-provider contracts and deployment/provisioning jobs. Deployment serialization, revision, callback, health, rollback and failure-guidance tests are present. | Existing and locally verified with clarity gaps. Build details expose progress and guidance, but a unified request/provision/build/migration/health/traffic timeline and explicit monorepo change impact need inventory confirmation. | Add a bounded deployment timeline and failure links without changing strategies or claiming percentage traffic splitting. Then confirm or add per-service roots/path filters and conservative unknown-change behavior. Preserve immutable revisions, approvals, webhook idempotency, locks, cancellation, stale attempts, retained artifacts and the distinction between application rollback and database recovery. |
| Preview deployments | A pull-request webhook opens or updates a preview, provisions a website/repository/environment, queues the build, reports to GitHub, closes/expires and cleans up. Settings are managed from project preview routes. The core entry point is `PreviewDeploymentLifecycle`; the project preview action/request and repository webhook path complete the flow. | `PreviewDeployment`, `PreviewDeploymentLifecycle`, `PreviewEnvironmentConfiguration`, `PreviewTrustPolicy`, `PreviewInitializationLifecycle`, `PreviewResourceCredentials`, `UpdateProjectPreviewsAction`, preview settings request, `ProjectPolicy`, `AddWebsiteJob`, `ReportGitHubPreviewJob`, `DeploymentRequest`, `PlanLimits`, `Entitlements`, `PreviewStackCatalog`, `ConfigurePreviewStackAction`, `QueuePreviewStackCleanupAction`, `CleanupPreviewStackJob` and `PreviewStackCleanupScript`; `PreviewDeploymentTest` covers open/update/close, initialization, settings, entitlement ordering, source-secret exclusion, legacy-preview sanitization, provider trust metadata, idempotent child declarations, cleanup/retry behavior and capacity release. `PreviewDeploymentConcurrencyTest` covers the last-slot race. `DeploymentHooksTest` and `PreviewResourceCredentialsTest` cover encoded initialization scripts, marker/retry behavior, stage compatibility and credential preservation. `ServerProviderContractTest` and `ApplicationConfigurationEnvironmentObservationTest` cover normalized readiness output. | Existing with completed configuration, trust, local readiness, ownership-aware cleanup, atomic quota and explicit initialization/resource credential boundaries. New and revised previews receive explicit preview-owned configuration instead of copied source environment text. Updated/reopened code execution is limited to the configured target branch and configured target repository; forks and unknown trust metadata are denied. Supported Laravel presets persist queue/scheduler process declarations and planned managed PostgreSQL/Valkey children; new Valkey resources receive encrypted, shell-escaped credentials; initialization is a curated, one-time, retryable command. Unsupported presets and legacy passwordless resources remain unchanged. The existing manager-authorized observation now exposes normalized provider lifecycle/readiness for supported server adapters; provider-side cloud acceptance remains outstanding. | Phases 1 and 3A–3F establish safe configuration, trust/secret approval, navigation, first-deployment guidance, a template-driven local stack manifest, callback-backed resource states, durable exact-identity cleanup, transactionally serialized concurrent-preview quotas, explicit preview initialization/resource credential boundaries and a one-time provider-readiness observation. Completion still requires provider evidence and separate cloud acceptance. |
| Databases, load balancers, domains and backups | Manage database users/clones/inspection, backup destinations/schedules/runs/restores, load balancers/nodes and website domains. Entry points are `DatabaseController`, `BackupController`, `LoadBalancerController` and `DomainController`. | `app/Actions/Database`, `app/Actions/Backup`, `app/Actions/LoadBalancer`, `app/Actions/Domain`; resource requests and policies; database/backup/restore/load-balancer jobs; provider contracts and command-safety services. Managed-backup, restore, database safety, domain and load-balancer lifecycle suites cover local behavior. | Existing and locally verified with recovery-evidence gaps. Backup and restore workflows exist, but backup completion is not the same as verified recovery; isolated restore smoke tests, cleanup visibility and control-plane/application-data scope need a clearer product surface. | Add recovery verification indicators, destination/overwrite review, integrity and application smoke checks, failure stage/duration and cleanup status, reusing existing jobs/actions. Preserve encrypted credentials, organization-scoped IDs, incompatibility guards, ownership, duplicate protection, dispatch timing, retries and partial-remote failure behavior. Keep remote calls outside new local transactions. |
| Automation and runtime control | Configure deployment/scaling schedules and scheduled tasks, queue runs, change process/runtime/scaling settings and manage scoped personal API tokens. Entry point is `AutomationController` plus runtime/environment/API routes. | `app/Actions/Automation`, environment actions; automation/runtime/scale/token requests; token and environment policies; `WorkflowConfiguration`, `DeploymentLauncher` and scheduled-task jobs. Automation, token, runtime and concurrency tests exist. | Existing and locally verified. Product clarity and API/web parity should be improved without merging distinct request contracts. | Improve schedule validation/help and runtime feedback, preserve cron/timezone/overlap behavior, token ownership/expiry/rotation, capability checks, entitlements, bounds, organization scoping and dispatch semantics. Keep web min/max scaling distinct from API replica-count requests. |
| Logs, metrics, health, alerts, incidents and status | Inspect server/website logs and metrics, health history, alert rules/destinations, operational incidents and public status pages/subscriptions. Entry points include `ObservabilityController`, `OperationalIncidentController`, health/log controllers and status controllers. | Observability actions, health/log services, `OperationalIncidentQuery`/exporter, `DeploymentFailureGuidance`, `WebsiteHealthMonitor`, `ProviderHealthMonitor`, incident notifier, observability requests and resource policies; focused observability, health, log, incident and status tests exist. | Existing and locally verified with correlation gaps. Evidence is spread across resources; users need an environment view linking deployments, logs, health checks and incidents, plus safe saved/shareable investigations, grouping and retention limits. | Add deterministic environment-context diagnostics, bounded filters and links to related deployment/configuration changes. Label correlations as possible unless evidence establishes causation. Recheck authorization on saved/shareable URLs and preserve redaction, polling bounds, alert deduplication, observation timing and notification semantics. |
| Recipes, gallery, reports, ratings, favorites and product feedback | Create/edit/install recipes, browse the gallery, attach recipes to provisioning, rate/report/review recipes and submit product feedback. Entry points include `RecipesController`, gallery/ratings/reports/favorites controllers and `ProductFeedbackController`. | `app/Actions/Recipe`, `app/Actions/ProductFeedback`; recipe/report/gallery/rating/feedback requests; `RecipePolicy`, `RecipeReportPolicy`, `ProductFeedbackPolicy`; encrypted script snapshots and provisioning integration; gallery, inventory, report, notification and rating tests exist. | Existing and locally verified. The product-template direction is broader than user recipes: a curated service catalog needs support/version/lifecycle metadata, not a speculative marketplace. | Keep user-contributed recipe isolation and notification timing. For curated templates, use explicit versioned definitions and reviewable installation/upgrade/recovery behavior; do not silently reinterpret existing recipes or expose encrypted script data. |
| API, CLI, MCP and external integrations | Consume OpenAPI/API control-plane operations, CLI/MCP commands, GitHub App installation/webhooks, OAuth and enterprise SSO callbacks. Entry points include `Api\\V1\\ControlPlaneController`, GitHub/App, repository webhook, OAuth and SSO controllers/requests. | Shared actions and services, API-specific requests, API token policies/capability middleware, provider contracts, signed webhook/callback requests and CLI/MCP command handlers. API/OpenAPI, webhook, OAuth, SSO and token tests exist. | Existing and locally verified for local fakes. API/web parity and callback ordering must remain explicit; GitHub App production configuration and live provider acceptance are outstanding. | Share operations only where semantics match. Preserve token capability checks in addition to resource policies, raw-body/signature verification, replay protection, callback status behavior, response envelopes, queued-job serialization and secret-safe errors. |
| Server diagnostics and future interactive troubleshooting | Review system health, queue bounded server commands/log refreshes, view retained output and inspect provisioning diagnostics. Entry points are `SystemHealthController`, `ServerCommandsController`, `CommandsController` and server callback routes. | Server actions/requests/policies, `OperationalDiagnostics`, `ServerCommandAction`, `ServerCommandExecution`, command/provisioning log jobs and health snapshots. Diagnostics, command, provisioning-log and retry tests exist. | Existing with a clear scope boundary. Current execution is queued, bounded command/output capture; it is not an interactive terminal session. | First improve structured runtime/process/storage/connectivity diagnostics. Only then design a separate interactive transport with connect/execute permissions, short-lived authorization, revalidation, concurrency/idle limits, resize/disconnect/expiry cleanup and audit metadata. |
| Costs, resource usage and entitlements | Review estimated infrastructure cost, set workspace budget, inspect server sizes/CPU/usage signals and see plan limits/entitlements. Entry points are `CostController`, plan/entitlement surfaces and provider size catalogs. | `UpdateInfrastructureBudgetAction`, cost request, `PlanLimits`, `Entitlements`, `Size` model/provider catalog and cost/plan tests. Current UI reports size-based estimates and low-use signals. | Existing and locally verified with attribution/source gaps. Current estimates must not be presented as measured provider billing or a spending cap; resource/environment allocation and price timestamps are limited. | Add explicit estimate/measured/provider-bill distinctions, source timestamps, unknown costs, preview lifetime/quota visibility and review-only cleanup recommendations for explicitly owned temporary resources. Preserve plan defaults, billing enforcement, budget semantics and no automatic provider deletion. |

### Cross-cutting authorization, validation and side-effect inventory

- The application already has substantial Form Request, policy and action coverage;
  the inventory found 117 requests, 25 policies and 181 actions. Remaining
  controller boundaries must be assessed by behavior rather than by class count.
- Validation/authorization searches covered `validateWithBag`, request validation,
  `Validator::`, `abort`, `abort_if`, `abort_unless`, `permits` and `can` across
  controllers and requests. Existing ordering is intentional in several places:
  entitlements and authorization may run before validation to avoid flashing
  secrets, while callbacks may validate only after loading a locked attempt.
- A direct model-write scan found the remaining controller-level calls that need
  continued characterization rather than mechanical extraction: notification
  state deletion and configuration review creation through existing collaborators.
  Controllers also coordinate many existing action/service calls, relationship
  lookups and dispatches that are not represented by a direct `Model::create()`
  scan.
- Classify every guard before changing it. Actor permission/ownership belongs in a
  policy or gate; route-child mismatch belongs in scoped binding/relationship
  lookup; invalid workflow state belongs in the operation; webhook/OAuth/signature
  checks belong in protocol middleware/verifiers; missing resources retain their
  current HTTP lookup semantics. Deliberate 404 concealment and exact 403/404/409/
  422 ordering are compatibility requirements.
- For every write slice, verify no writes or jobs occur on denial, tenant and role
  combinations, omitted versus null fields, named error bags, secret exclusion
  from responses/logs/session old input, transaction rollback, locks, leases,
  retries, idempotency and stale callback protection.

### Existing implementation states

The following labels are used consistently in this ledger:

- **Existing and verified:** implemented and covered by current local regression
  evidence. This does not mean live cloud or paid-provider acceptance.
- **Existing with known gaps:** implemented locally, but a product contract,
  safety boundary, observability concern or recovery journey remains incomplete.
- **Proposed extension:** a new capability not assumed to exist merely because a
  nearby feature exists.
- **External acceptance outstanding:** requires production mail, provider,
  monitoring, GitHub App, billing or other approved external configuration and is
  never counted as a local pass.

### Inspiration recorded for adaptation

The planning references were reviewed as workflow inspiration, not as parity
claims:

- [Render preview environments](https://render.com/docs/preview-environments):
  disposable PR services/datastores, initialization and cleanup/expiry concepts.
- [Render Blueprint specification](https://render.com/docs/blueprint-spec):
  declarative topology and validation concepts.
- [Coolify service catalog](https://coolify.io/docs/services/overview):
  discoverable service templates with operational guidance.
- [Laravel Forge deployments](https://laravel.com/forge/docs/sites/deployments):
  deployment and recovery visibility ideas.
- [Railway logs](https://docs.railway.com/observability/logs): service/deployment
  context and bounded operational log workflows.

BuildPusher will reuse its own configuration, actions, policies, provider
contracts, jobs and persistence model rather than copying another product's
architecture.

## Baseline evidence

All commands below ran in the isolated clone on `main` at `e3006c2`.

| Check | Result | Notes |
| --- | --- | --- |
| Required PHP runtime | **Passed** | PHP 8.5.10 from `/root/.local/share/buildpusher/php-8.5.10/bin/php` was used for application commands. The host PHP 8.3 was not used for the suite. |
| Disposable database bootstrap | **Passed** | `migrate:fresh --seed --force` completed all 119 migrations and the demo seed against the temporary SQLite database. |
| Full PHP suite | **1,319 passed, 1 failed; 11,410 assertions** | The baseline failure is `Tests\\Feature\\ProvisioningHardeningTest > website database user is local only`: `tests/Feature/ProvisioningHardeningTest.php:93` expected 3 occurrences of `localhost`, received 4. No product-expansion code was changed before this result. |
| Pint | **Passed** | `/root/.local/share/buildpusher/php-8.5.10/bin/php vendor/bin/pint --test`. |
| Composer manifest/platform | **Passed** | `composer validate --no-check-publish` and `composer check-platform-reqs` passed. PHP 8.5 emitted deprecation notices from the system Composer libraries; no dependency or lockfile change was made. |
| Asset build | **Passed** | `npm run build` completed with Vite. |
| Asset/browser baseline | **9 passed** | `BROWSER_PHP_BINARY=/root/.local/share/buildpusher/php-8.5.10/bin/php npm run test:assets`; light/dark widths 320/390/768/1440 plus no-JavaScript provider submission; 5.3 minutes. |
| Route inventory | **Recorded** | `route:list --json` reported 389 total routes and 315 named routes, including package/framework routes. |
| Canonical working tree | **Preserved** | `main` was at `e3006c2` with only the pre-existing untracked user plan; the acceptance-drill checkout was not touched. |

The historical context of 1,320 passing tests and 11,411 assertions is not used as
the current baseline. The one current failure is carried forward as a baseline
finding and must not be hidden by a feature slice. It is separate from the first
preview-safety change unless investigation proves otherwise.

### Browser follow-ups already documented

The current repository's prior verification records document two browser issues
that were not silently rewritten during this baseline:

- The tablet accessibility expectation looks for a `Search and navigate` control
  at 768px after Escape, although the current breakpoint does not render that
  control. The test/application contract still needs adjudication; weakening the
  assertion is not an acceptable fix.
- The broad visual audit remains outstanding because its mobile crawl waits for a
  `Settings` link that is absent from the current menu.

The bounded asset suite passed, but it does not by itself resolve either broader
follow-up. Phase 1 navigation work must determine whether each discrepancy is an
application defect or an outdated expectation and record the decision.

## Prioritized delivery sequence

1. **Phase 0 — complete.** Inventory, isolation and fresh baseline recorded here.
2. **Phase 1 — preview safety and everyday usability.** Characterize preview
   configuration sources; establish explicit safe preview configuration and
   trust/secret boundaries; then address navigation, feedback and first-deploy
   guidance.
3. **Phase 2 — configuration authoring and environment overview.** Add the
   reviewable authoring, summaries, dependency view, secret-safe comparison and
   read-only observable drift reporting described above.
4. **Phase 3 — complete preview environments (local slice complete).** Phases 3A through 3F now
   declare the representative Laravel worker/scheduler/PostgreSQL/Valkey stack,
   record callback-backed planned/provisioning/ready/failed resource states and
   capture exact owned identities for retryable close/expiry cleanup and
   transactionally serialize concurrent-preview quotas, run curated
   revision-bound initialization once, protect managed Valkey credentials and
   expose one-time normalized provider lifecycle/readiness observations.
   Local implementation is complete; completion still requires provider-side
   and cloud evidence.
5. **Phase 4 — curated service templates.** Phase 4B now composes the generic
   Node preset with the existing managed PostgreSQL/Valkey, readiness and
   cleanup paths while preserving the versioned Laravel contract. Next,
   characterize template installation/upgrade execution and add only templates
   with installation, readiness, upgrade, restore and deletion evidence.
6. **Phase 5 — deployment clarity and monorepo support.** Improve timeline and
   change-impact visibility while preserving existing deployment strategies.
7. **Phase 6 — verified backup recovery.** Separate backup completion from
   verified application/control-plane recovery and make cleanup visible.
8. **Phase 7 — connected observability and troubleshooting.** Connect deployment,
   environment, log, health and incident context with bounded queries. The
   completed local slices now include revision-bound observation outcomes and
   expiring organization-owned investigation handoff views; provider/cloud
   acceptance remains separate.
9. **Phase 8 — structured diagnostics, then interactive troubleshooting.**
   Characterize and improve bounded runtime/process/storage/connectivity
   diagnostics before designing sessions with a clear host transport/security
   model.
10. **Phase 9 — resource usage and cost visibility.** Distinguish estimates,
    measured use and provider billing; show safe review-only cleanup signals.

The excluded scope remains Kubernetes orchestration, a plugin marketplace,
percentage-based multi-region traffic management, automatic database rollback and
autonomous AI remediation.

## Phase 1A — preview-owned configuration (completed slice)

### Concrete responsibility problem

`PreviewDeploymentLifecycle` currently handles webhook classification, preview
lookup, entitlement and quota checks, transaction/locking, website/repository/
environment construction, build dispatch, GitHub reporting and cleanup. Its
`create()` method also copies the source website's environment text into a
preview. That combines lifecycle orchestration with configuration policy and can
carry production secrets into an environment that may execute fork or untrusted
branch code.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** separate the safe preview configuration decision
  from lifecycle coordination and persistence.
- **Dependency inversion:** inject a small concrete configuration collaborator or
  immutable data object at the application boundary; do not make it depend on an
  HTTP request or service location. An interface is not justified until there are
  real alternate configuration providers.
- **Laravel boundary:** keep webhook verification and route/controller behavior in
  their existing boundaries; keep transaction-time ownership, locks, idempotency,
  dispatch timing and stale-attempt behavior in the lifecycle operation.

### Characterization and implementation

Trace and test all possible sources of preview configuration before changing
behavior:

- website environment text and encrypted/decrypted model behavior;
- project/environment variables and secret versions;
- provider credentials and deploy keys;
- build and post-deployment commands;
- initialization hooks and resource credentials;
- trusted-branch, fork and revision identity from the verified webhook;
- existing preview records, reopen/repoint behavior and approval state.

The first implementation slice made the allowed preview configuration explicit
through `PreviewEnvironmentConfiguration`. It omits source secrets by default,
generates an independent application key, derives local database values from the
preview-owned website identity and preserves the existing `APP_ENV=preview`/
preview marker, hostname, ownership, dispatch and no-op/idempotency contracts.
`PreviewDeploymentLifecycle` remains responsible for webhook and lifecycle
coordination; the collaborator owns only configuration construction.

This is an intentional security behavior change, not a silent structural
rewrite: a new preview no longer inherits arbitrary source environment text.
Existing previews are not migrated in bulk. A revised non-close event sanitizes
the existing preview before queuing its new revision; an operator must close and
recreate a legacy preview that receives no event before using it with untrusted
code. The compatibility note is also recorded in
`docs/application-configuration.md`.

### Remaining Phase 1 characterization

The following remain outside the completed safety slices: dependent-resource
credential provisioning and the multi-service preview lifecycle. Trusted
target-repository/fork policy was added in Phase 1B, explicit secret-scope
approval was added in Phase 1C, responsive navigation was completed in Phase 1D
and first-deployment guidance was completed in Phase 1E below.

### Phase 1 exit criteria

- **Completed in Phase 1A:** behavioral tests prove source secret text is not
  copied into a new preview website, including encrypted-at-rest source values;
  a revised legacy preview is sanitized before its new revision is queued.
- **Completed in Phase 1B:** trusted target-branch, target-repository and fork
  decisions are explicit; denied inputs create no website, environment,
  repository, preview record or queued job. Fork execution remains disabled
  until host-level isolation is proven.
- **Completed in Phase 1C:** managers can approve only selected runtime/all
  secret variables for the exact current revision. The approval stores variable
  identities and versions, not values; rotation, reclassification, revision
  changes and closure invalidate it, and preview-owned credentials remain
  protected. Resource configuration is not a source of preview secrets.
- Repeated webhooks remain idempotent, changed revisions supersede stale work,
  close/reopen does not permit stale cleanup to delete a current preview, and all
  existing preview tests remain green.
- No secret appears in response bodies, logs, GitHub reporting payloads, session
  old input or generated diagnostics.
- **Completed in Phase 1D:** the tablet palette-focus discrepancy and missing
  mobile Settings destination are fixed and covered by focused browser evidence.
- **Completed in Phase 1E:** first-deployment guidance distinguishes source
  credential rejection, insufficient provider permissions, unavailable/unchecked
  provider checks and plan entitlement denial, with links to the existing
  settings, connection-check and billing pages. Manual deployment entitlement
  denial is rechecked inside its transaction and creates no build or job.

## Phase 1B — trusted pull-request admission (completed slice)

### Concrete responsibility problem

`RepositoryWebhookVerifier` authenticated webhook signatures and normalized the
pull-request source branch, but it did not carry the target branch, target
repository or fork identity into the preview workflow. Consequently,
`PreviewDeploymentLifecycle` could provision any signed pull-request payload
with a usable revision, even when it targeted another branch, came from a fork or
omitted the provider metadata needed to establish trust.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** provider adapters normalize protocol metadata;
  `PreviewTrustPolicy` owns only preview admission decisions; the lifecycle
  continues to coordinate persistence, locks, dispatch and cleanup.
- **Dependency inversion:** the lifecycle receives the concrete trust policy
  through constructor injection. No HTTP request, provider SDK or service locator
  is passed into business coordination.
- **Liskov substitution:** GitHub, GitLab and Bitbucket preview payloads now map
  to the same target/fork fields and are exercised through the same lifecycle
  admission contract.

### Implementation and preserved behavior

`VerifiedRepositoryWebhook` now carries target branch, target repository and
nullable fork status. The verifier derives these from GitHub base/head
repositories, GitLab target/source project IDs and target project identity, and
Bitbucket destination/source repositories. `PreviewTrustPolicy` requires an
exact configured target branch and repository, rejects forks and rejects missing
trust metadata. Close events bypass execution admission so cleanup remains
available when a provider omits code-execution metadata.

The denial is an intentional preview security change. It does not change webhook
signature validation, push deployment handling, preview identity, revision
serialization, existing locks, idempotency, queue timing or stale cleanup
behavior. Fork execution remains disabled until host-level isolation is proven;
the policy cannot be bypassed by future secret approval.

### Verification and remaining work

The preview suite covers same-repository admission for GitHub, GitLab and
Bitbucket, wrong target branch, mismatched target repository, forked requests,
missing target metadata and missing source metadata, with assertions that no
preview resources or jobs are created on denial. The adjacent repository webhook
and provisioning callback suites remain part of the slice regression. Secret
scope is intentionally documented separately in Phase 1C.

## Phase 1C — revision-bound preview secret approval (completed slice)

### Concrete responsibility problem

The safe preview baseline removed implicit source-environment copying, but the
product had no explicit way for a manager to authorize a narrowly defined set
of runtime secrets for a trusted preview. A project-wide switch would not bind
that decision to a pull-request revision, and copying resource configuration
would risk forwarding dependent credentials without an independent lifecycle.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `ApprovePreviewSecretsAction` owns the locked
  approval write; `PreviewDeploymentPolicy` owns workspace permission;
  `PreviewSecretApprovalResolver` owns version-checked secret reads;
  `PreviewEnvironmentConfiguration` owns safe environment-file construction;
  the lifecycle only coordinates them.
- **Dependency inversion:** the lifecycle receives the resolver and configuration
  collaborator through constructor injection; no controller request or secret
  lookup is passed into queued work.
- **Interface segregation:** no new interface was introduced because this
  workflow has one concrete application consumer and no provider variant.

### Implementation and preserved behavior

`preview_deployments.source_environment_id` records the selected nonpreview
source. Managers can submit secret names through the scoped project preview
route. The action rechecks current workspace management access under the
preview lock, verifies that every selected variable is secret and runtime/all,
and stores only the exact revision, source environment, variable IDs and
current versions. Protected preview-owned keys (`APP_*`, the preview marker and
`DB_*`) are rejected.

The resolver applies a scope only when the revision, source environment,
variable identity, current version, secret classification and runtime scope all
still match. A later verified webhook then places the approved values in the
encrypted preview environment. No approval values are stored in plaintext, old
input or responses; website environment text, provider credentials, commands
and resource configuration remain excluded. Rotation, stale page revisions,
new revisions and closure fail closed. Existing webhook trust, idempotency,
locks, queue timing, safe baseline, cleanup and serialized values remain intact.

### Verification and remaining work

The preview suite now covers safe-by-default behavior, manager authorization,
exact revision approval, stale page rejection, secret rotation, protected
preview credentials, resource-secret exclusion and closure revocation. The
focused preview suite passed 17 tests and 136 assertions. The adjacent
preview, webhook, provisioning, project/environment and configuration recovery
suites passed 189 tests and 1,571 assertions; the asset build, Pint and
`git diff --check` passed. The full isolated PHP suite passed 1,332 tests with
one unchanged baseline failure and 11,510 assertions: `ProvisioningHardeningTest`
still expects three `localhost` occurrences and receives four at line 93. The
approval is applied on the next verified event; dependent Postgres/Valkey
provisioning and independent resource credential approval remain Phase 3 work.

## Phase 1D — responsive navigation and focus restoration (completed slice)

### Concrete responsibility problem

At 768px, the command palette could be opened with `Ctrl+K` even though the
responsive header did not render a palette trigger. Escape therefore had no
visible control to receive focus, and the mobile navigation omitted the existing
desktop Settings shortcut. Palette focus restoration also did not consistently
return to the mobile quick action at narrow widths.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** the layout owns responsive navigation and its
  keyboard focus lifecycle; the browser tests own the observable accessibility
  contract. No controller, policy or business action was involved.
- **Progressive enhancement:** native links and buttons remain usable without
  additional application state or a JavaScript-only route.
- **Accessibility boundary:** each responsive layout supplies a named palette
  trigger, and one resolver restores focus to the first visible trigger after
  the dialog closes.

### Implementation and preserved behavior

The tablet header now exposes an accessible `Search and navigate` trigger from
the existing 640px breakpoint. The mobile quick-action Search button is also a
focus restoration target. Desktop, tablet and mobile palette close paths use a
shared visible-trigger resolver, while the existing command-palette query,
keyboard shortcut, focus trap, scroll lock and search route remain unchanged.

The mobile Settings link points to the existing account settings destination.
Account remains the only current navigation destination on account pages, so
the addition does not alter route names, authorization or persisted state.

### Verification and remaining work

`DashboardTest.php` and `LocalUiAssetTest.php` passed 36 tests and 737
assertions. The isolated accessibility browser suite passed 3 tests across
mobile, tablet and desktop in 26.2 seconds. The isolated mobile visual crawl
passed 1 test in 1.2 minutes. The broad visual audit passed mobile and tablet;
its desktop navigation assertion was corrected to target the intentional
desktop sidebar and then passed 1 desktop test. The Vite build, Pint and
`git diff --check` passed.

## Phase 1E — first-deployment preflight guidance (completed slice)

### Concrete responsibility problem

The repository page displayed the technical preflight snapshot but did not turn
failed or incomplete checks into a clear recovery path. A user could not tell a
rejected credential from an insufficient provider scope, could not reach the
right existing settings page from each check, and manual deployment did not
recheck the existing `deployments` entitlement at the transaction boundary.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `DeploymentPreflight` continues to own the stable
  technical risk snapshot; the injected `DeploymentPreflightGuidance` service
  owns only view-facing next steps and safe links.
- **Dependency inversion:** the guidance service receives `Entitlements` and
  Laravel's URL generator through its constructor; it makes no remote provider
  call and does not depend on a controller request.
- **Business-operation boundary:** `DeployRepositoryAction` rechecks the
  deployment entitlement inside its existing transaction before any write, while
  the controller still only coordinates the response.

### Implementation and preserved behavior

The repository page now reports completed prerequisites and remaining blockers,
links server/website/source/environment/health/recovery/webhook issues to the
existing pages, and adds a plan-access explanation with a billing link. Provider
guidance uses only the retained HTTP status and health state: 401 is described
as credential rejection, 403 as insufficient access, and transport/unchecked
states remain actionable without displaying response bodies, tokens or errors.
The persisted `risk_assessment` shape and existing deployment preflight rules
remain unchanged. Manual deployments now honor the same existing
`deployments` entitlement already enforced by configuration operations; the
default plan definitions still include Git deployments. A denied request rolls
back before creating a build or dispatching a job, and the existing redirect,
flash, approval and queue behavior remains unchanged when access is allowed.

### Verification and remaining work

`RepositoryDeploymentTest.php`: 11 passed, 70 assertions, including 401/403
classification and entitlement denial with no build/job side effects. Adjacent
deployment, preflight snapshot, environment, authorization, deployment-control
and configuration-delivery coverage: 32 passed, 269 assertions. The isolated
browser visual audit passed mobile/tablet and the corrected desktop contract;
the Vite build, Pint and `git diff --check` passed. The unchanged baseline
`ProvisioningHardeningTest` localhost-count failure remains documented above.
The exact feature commit and push are recorded in the slice ledger below.

## Phase 2D — explicit provider observation (completed slice)

### Concrete responsibility problem

The configuration page could show recorded server metadata and compare two
recorded environments, but it could not show whether the provider currently
returned the same supported server identity and network metadata. Treating the
local comparison as provider drift would have been misleading, while adding
provider calls to every page load would have made a local read unexpectedly
slow and side-effectful.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `ApplicationConfigurationEnvironmentOverviewQuery`
  remains responsible for recorded local topology; the new
  `ApplicationConfigurationEnvironmentObservationQuery` owns only the explicit
  provider-backed read and safe field comparison; `ServerProvider` adapters
  remain responsible for authenticated requests, normalization, timeouts and
  provider response failures.
- **Dependency inversion:** the observation query receives the existing
  `ServerProviderResolver` through constructor injection and calls the shared
  `ServerProvider::server()` capability; it does not construct HTTP clients,
  access a request, or use a service locator.
- **HTTP boundary:** `ObserveApplicationConfigurationRequest` authorizes the
  manager ability and validates a project-scoped environment ID before the
  controller invokes the read. The GET action is explicit and read-only.

### Implementation and preserved behavior

The configuration page now offers **Observe provider** for each recorded
environment. A manager can request a one-time read for a workspace-owned server
with a provider identifier. The result compares only the normalized fields
already returned by the shared server-provider contract: provider identifier,
name, region, size, image and public/private addresses. It distinguishes
observed, unavailable and unknown outcomes, and labels differences as
informational. No observation is persisted, queued, reconciled or sent to an
apply workflow. The ordinary authoring page and local comparison remain free of
provider requests.

The query scopes the environment, server and provider to the project's
organization before decrypting the provider token or making a remote request.
It selects only the server metadata and provider credential columns required for
this boundary; server keys, passwords and unrelated configuration are not
hydrated. Missing placements or identifiers do not call a provider. Provider
exceptions are converted to a safe unknown result without exposing response
bodies, credentials or exception text. Existing routes, configuration review /
apply behavior, persisted values, queue behavior and provider adapters remain
unchanged.

### Verification and remaining work

`ApplicationConfigurationEnvironmentObservationTest.php` plus the overview,
comparison and web regressions passed 12 tests and 110 assertions. Coverage
includes normalized observed metadata, informational differences, no
persistence, missing placement, sanitized provider failure, authorization before
malformed input, same-project validation and out-of-project query scoping. The
full Pint check passed. The feature commit and documentation push are recorded
in the slice ledger below. This is local adapter evidence only; it does not
establish live provider acceptance or comprehensive remote drift detection.

## Phase 3A — template-driven preview stack manifest (completed slice)

### Concrete responsibility problem

`PreviewDeploymentLifecycle` created only a website, repository and environment,
so the preview record did not describe the worker, scheduler or dependent data
services that the existing deployment snapshot and provisioning foundations can
already represent. Adding those records directly to webhook orchestration would
mix template selection, entitlement policy and child persistence with lifecycle
locking and dispatch.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `PreviewStackCatalog` resolves only explicitly
  declared template topology; `ConfigurePreviewStackAction` owns the cohesive
  local child-declaration operation; `PreviewDeploymentLifecycle` remains the
  webhook transaction and dispatch coordinator.
- **Dependency inversion:** the action receives the catalog, entitlement
  service and existing process/resource save actions through constructor
  injection. It does not accept an HTTP request, make remote calls or use a
  service locator.
- **Open/closed and reuse:** supported presets opt into `preview_resources` in
  `config/application-templates.php`; presets without that declaration do not
  silently acquire Laravel-specific services. Existing process/resource actions
  continue to own casts, encryption and managed connection-variable creation.

### Implementation and preserved behavior

Laravel, Laravel + Inertia and Laravel API templates now declare queue and
scheduler processes plus managed PostgreSQL and Valkey preview resources. The
preview action persists those children by stable name inside the existing
preview transaction, preserves an existing resource status, and is safe to
re-run for repeated webhook revisions. The PostgreSQL configuration uses the
preview website's generated encrypted database password; no source website
environment text or source secret is copied. Unsupported presets remain
unchanged, and worker/resource entitlements still gate their respective child
records.

This slice deliberately records the initial resource declaration as local
**planned** state. Phase 3B subsequently adds durable **provisioning**,
**ready** and **failed** states from the existing deployment callbacks; those
states are lifecycle evidence and do not claim an independent provider health
check. Valkey remains the existing loopback-bound, unauthenticated
managed-resource configuration. Explicit initialization secrets and quotas
remain the next Phase 3 slices. No route, response, YAML schema, persisted
existing value, queue timing or job serialization contract changed.

### Verification and remaining work

The focused preview/catalog and adjacent environment suites passed **30 tests
and 236 assertions**. The authoritative isolated full PHP suite passed **1,347
tests and 11,633 assertions** with the required PHP 8.5 runtime, `APP_DEBUG=true`
and an in-memory SQLite database. Pint, changed-file linting and
`git diff --check` passed. No migration or asset change was part of this slice;
the existing browser regression baseline remains separately recorded.

Feature commit `290577c` is pushed to GitHub `origin/main`. Local tests do not
establish cloud/provider acceptance or the separate live acceptance drill.

## Phase 3B — preview resource readiness (completed slice)

### Concrete responsibility problem

Phase 3A persisted the supported preview stack, but every managed resource
remained `planned` even after the deployment scripts had reached resource
initialization or had failed. The lifecycle, callback actions and queued job
needed a shared, monotonic status boundary without duplicating provider calls or
changing the existing signed callback protocol.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `PreviewStackReadiness` owns preview-resource
  status transitions; `RepositoryDeploymentPlan` exposes the existing resource
  stage; `PreviewDeploymentLifecycle`, callback actions and the queued job keep
  their coordination responsibilities.
- **Dependency inversion:** readiness receives the deployment-plan collaborator
  and is constructor-injected into lifecycle and callback actions. The queued
  job resolves it only in its existing failure hook so its serialized
  constructor payload remains compatible.
- **Liskov/idempotency:** progress is monotonic, repeated callbacks are safe,
  already-ready resources are not downgraded, and non-preview builds are
  unaffected.

### Implementation and preserved behavior

Preview resources enter `provisioning` immediately before the existing build
dispatch. A signed progress callback at the resource configuration stage marks
planned/provisioning resources `ready`; a signed build failure or queued-job
failure marks only resources still being initialized as `failed`. The update is
scoped to the exact preview environment, uses single atomic updates and leaves
manual/previously-ready resources intact. Existing deployment stage ordering,
callback signature checks, stale-attempt guards, retry behavior, queue timing,
routes, response formats, YAML schemas, persisted existing values and job
serialization are unchanged. Remote cleanup and quota enforcement are not
silently implied by these statuses.

### Verification and remaining work

The focused `PreviewDeploymentTest.php` and `PreviewStackReadinessTest.php`
passed **21 tests and 170 assertions**. Adjacent deployment/resource coverage
passed **24 tests and 157 assertions**. The authoritative isolated full PHP
suite passed **1,351 tests and 11,652 assertions** with the required PHP 8.5
runtime, `APP_DEBUG=true` and in-memory SQLite. Pint, changed-file linting and
`git diff --check` passed. This is local callback evidence only; it does not
establish cloud/provider acceptance or independent remote health verification.

Feature commit `f780685` is pushed to GitHub `origin/main`. The exact next task
was Phase 3C: define preview-stack ownership and implement retryable,
stale-attempt-safe close/expiry cleanup before adding atomic concurrent-preview
quotas; that slice is recorded below.

## Phase 3C — preview-stack ownership and retryable cleanup (completed slice)

### Concrete responsibility problem

Preview closure and expiry previously delegated only to the generic website
cleanup path. That path did not know which preview processes, PostgreSQL
identities or Valkey containers were created by the preview stack, and it could
not safely recover after partial remote work. Re-discovering children after a
close/reopen would also risk an old cleanup targeting a newly created stack.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `PreviewStackCleanupManifest` captures safe,
  non-secret ownership identities; `QueuePreviewStackCleanupAction` owns the
  locked local capture and dispatch decision; `CleanupPreviewStackAction` owns
  remote execution; `PreviewStackCleanupScript` owns exact shell generation;
  `CleanupPreviewStackJob` owns leases, retries and stale-claim protection.
  `PreviewDeploymentLifecycle` only coordinates terminal preview events.
- **Dependency inversion:** remote cleanup receives the existing runner/provider
  contract through constructor injection. The job serializes only the cleanup
  record ID, so provider credentials and other mutable services do not enter
  queued payloads.
- **Liskov/idempotency:** repeated close/expiry events create at most one
  cleanup record, exact absent-resource operations are safe, and only the
  matching lease token may complete or fail a cleanup attempt.

### Implementation and preserved behavior

Preview processes and managed resources declared by the supported preview stack
are explicitly marked preview-owned. On close or expiry after the preview is
idle, the application captures the original environment, website, server and
deployment-slug identities plus only exact generated process/resource
identifiers in a durable cleanup record. The unique queued job claims a
time-limited lease, renders bounded cleanup for exact worker/scheduler units,
the generated PostgreSQL database/role and the generated Valkey
container/volume, and records visible retryable failure. Manual or shared
children are not eligible for deletion, invalid identities fail closed, and no
secret or credential is stored in the manifest.

Cancellation, watchdog completion and failed publish paths re-enter the same
terminal lifecycle. Queueing occurs before generic website soft deletion and
after the local transaction commits, preserving existing Caddy/application/
MySQL cleanup, synchronous-queue behavior, deployment serialization, stale
attempt protection, response formats and job compatibility. A reopened preview
cannot retarget an old cleanup because the job uses its captured identities.
Existing ownership rows default to false in the migration, so historical
children are intentionally fail-closed rather than guessed to be preview-owned.

### Verification and remaining work

The focused cleanup/lifecycle suite passed **23 tests and 219 assertions**. The
authoritative isolated full PHP suite passed **1,357 tests and 11,718
assertions** with the required PHP 8.5 runtime, `APP_DEBUG=true` and in-memory
SQLite. Migration fresh/rollback/reapply rehearsal passed; required-PHP
Composer validation/platform checks, full Pint, Vite asset build, changed-file
lint and `git diff --check` passed. The isolated Playwright asset suite passed
**9 tests**. Coverage includes idle/active close behavior, ownership filtering,
duplicate requests, leases, retry and stale claims, reopen safety, cancellation,
watchdog and failed-publish cleanup paths, authorization and partial failures.

Feature commit `74165bd` is pushed to GitHub `origin/main`. This evidence covers
the local runner/script contract and application state transitions; it does not
establish live cloud cleanup, independent provider readiness or the separate
acceptance drill. The exact next task was Phase 3D: enforce atomic
concurrent-preview quotas; that slice is recorded below before the next task of
defining explicit initialization/secrets.

## Phase 3D — atomic concurrent-preview quotas (completed slice)

### Concrete responsibility problem

Preview capacity was checked before the lifecycle transaction and the
transaction locked only the project. Two pull requests for different previews
could therefore observe the same last website slot and both create resources.
The existing website-limit response and preview entitlement behavior also had
to remain unchanged, and closed previews needed to release capacity.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `PlanLimits` owns configured usage and limit
  calculation, `Organization::previews()` provides the organization-scoped
  read boundary, and `PreviewDeploymentLifecycle` coordinates the atomic
  create/reopen workflow. No generic quota repository or reservation service
  was introduced.
- **Dependency inversion:** the lifecycle continues to receive `PlanLimits`
  through its existing constructor binding; the quota decision is not coupled
  to an HTTP request or controller.
- **Liskov/idempotency:** existing previews are looked up under the same
  transaction before capacity is consumed, so repeated events update or
  reopen their own record without consuming another slot. A true independent
  process race test verifies that only one contender can claim the final slot.

### Implementation and preserved behavior

`PlanLimits` now reports active `preview_deployments` usage through the
organization's projects and previews. A preview counts while its lifecycle
record is not closed; a closed record releases capacity only when it has a
closure timestamp. A legacy closed record without that timestamp counts
fail-safe rather than being assumed safe to ignore. Configured limits are
`0` for Free and Starter, `5` for Pro, `10` for Team, `20` for Business and
unlimited for Unlimited. These are config-as-code limits; no pricing UI or
entitlement was silently changed.

The lifecycle now increments an internal organization lock version and acquires
the organization row and project row inside the retrying transaction before
checking both website and concurrent-preview capacity. The version increment
is a write-side lock for SQLite and drivers without effective `FOR UPDATE`
support; it is not domain usage or a reservation counter. Active usage remains
derived from preview records, so closure, project cleanup and legacy data cannot
leave a stale allocation. The new migration defaults the lock version to zero
and requires no data backfill.

The existing `preview_limit_reached` response is preserved. Denied capacity
creates no preview website, preview record or queued job. Existing trust and
secret checks, preview identity, update/reopen behavior, website-capacity
behavior, transaction timing, cleanup ownership, queue dispatch and serialized
job payloads remain unchanged.

### Verification and remaining work

Focused quota coverage passed **18 tests and 172 assertions** in
`PreviewDeploymentTest.php`; the independent-process race passed **1 test and
7 assertions**. The broader quota/plan/billing batch passed **34 tests and 224
assertions**; adjacent cleanup/runtime/deployment coverage passed **19 tests and
150 assertions**, and project/tenancy coverage passed **9 tests and 49
assertions**. The fresh isolated full PHP suite passed **1,359 tests and
11,742 assertions** with the required PHP 8.5 runtime, `APP_DEBUG=true` and
in-memory SQLite. Migration fresh/rollback/reapply, Composer validation and
platform checks, full Pint, Vite asset build and `git diff --check` passed.

Feature commit `d722bad` (`feat: enforce concurrent preview quotas`) is pushed
to GitHub `origin/main`. This proves local transactional serialization and
application behavior; it does not establish provider-side quota enforcement,
cloud lifecycle behavior or the separate live acceptance drill. The exact next
task was Phase 3E: define explicit preview initialization and secret/resource
credential boundaries, then characterize independent provider-readiness
evidence.

## Phase 3E — explicit preview initialization and resource credentials (completed slice)

### Concrete responsibility problem

The template-driven preview stack had durable local resource declarations and
callback-backed readiness, but it did not define how a fresh application would
receive sample data or how a managed Valkey service would authenticate. Adding
those behaviors directly to the existing deployment script or lifecycle would
mix template policy, shell rendering, credential generation and callback state,
and could silently copy or rotate credentials for an already-running preview.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `PreviewStackCatalog` resolves the curated
  template declaration; `PreviewInitializationLifecycle` owns durable attempt
  state and exact callback identity; `PreviewInitializationScript` renders the
  bounded shell hook; `PreviewResourceCredentials` owns the Valkey credential
  boundary; and the existing environment/deployment actions continue to own
  persistence and dispatch.
- **Dependency inversion:** preview lifecycle coordination receives the new
  collaborators through constructor injection and reuses the existing
  `RepositoryDeploymentPlan`, `DeploymentRequest` and environment actions. No
  service-location call or generic deployment framework was introduced.
- **Liskov/idempotency:** initialization is tied to the exact repository,
  environment, revision and build attempt. Repeated callbacks, retries and
  stale builds cannot complete or fail a newer attempt; the shell marker makes
  a successful command safe to observe again.
- **Interface segregation:** no provider contract was changed because this
  slice does not claim independent provider health or introduce a new remote
  capability.

### Implementation and preserved behavior

Supported Laravel presets now explicitly declare the curated command
`php artisan db:seed --force`. A new preview stores a pending internal state;
the first queued build carries only the curated command, revision and attempt
identity in Build's encrypted environment payload. The command runs after the
candidate release is active, inside the existing post-deployment stage, and
writes `/shared/.buildpusher-preview-initialized` only after success. The
command is base64-encoded before entering the generated shell and failures
persist only a generic safe message. A missing marker after an interrupted
remote process permits an at-least-once retry, so template initialization
commands must be safe to repeat.

Initialization state is persisted as `not_configured`, `pending`, `running`,
`succeeded` or `failed`, with attempts, current build identity, failure text and
completion time. A failed or stopped attempt can retry the same revision with a
new attempt number. A successful first initialization is not repeated on later
revisions. Status callbacks and terminal reconciliation require the current
exact build/revision identity, so stale callbacks cannot overwrite a newer
attempt. Build dispatch remains outside the local transaction, preserving
synchronous queue behavior; the build creation, preview claim and initialization
claim are committed together before dispatch.

New preview-owned Valkey resources receive a random `REDIS_PASSWORD` in their
encrypted resource configuration, and the resource script shell-escapes it for
the new container's `--requirepass` option. Existing preview resource
declarations preserve their current password, including legacy passwordless
state, so an ordinary revision update cannot make the application and an
already-running container disagree. The existing generated preview database
credential, independent application key, source-secret exclusion, ownership
flags, cleanup manifest and resource readiness transitions remain unchanged.

The migration defaults existing preview rows to `not_configured`; it does not
retroactively execute initialization or copy data. A later revision change can
opt an existing preview into the curated initialization state, while existing
resource credential state is preserved. Templates without an explicit command
remain unconfigured. This is an intentional feature boundary: preview data is
sample data by default, not copied production data, and no independent provider
readiness is implied by the local state machine.

### Verification and remaining work

The Phase 3E focused preview/deployment/resource batch passed **37 tests and
375 assertions**. Adjacent preview cleanup, cancellation, deployment,
watchdog and provisioning-callback coverage passed **56 tests and 473
assertions**; callback-integrity coverage passed **5 tests and 34 assertions**.
The compatibility follow-up for historical passwordless resources passed **31
tests and 307 assertions** including the new direct credential-boundary unit
tests. The fresh isolated full PHP suite at the feature commit passed **1,362
tests and 11,785 assertions**, with the one unchanged baseline failure in
`ProvisioningHardeningTest::test_website_database_user_is_local_only` (the
test expects three `localhost` occurrences and the current script contains
four). No Phase 3E failure occurred.

Migration fresh/rollback/reapply rehearsal, changed-file PHP lint, full Pint,
required-PHP Composer validation/platform checks, Vite asset build and
`git diff --check` passed. The feature commit `cf5da72` and the compatibility
fix `fa114f0` are both fast-forwarded through canonical `main` and pushed to
GitHub `origin/main` on 2026-09-13. This evidence covers local application
state, encrypted snapshots and shell contracts; it does not establish remote
Valkey/PostgreSQL/process readiness, cloud cleanup or the separate live
acceptance drill. Phase 3F is recorded below; its provider-readiness result is
an opt-in, one-time observation and does not establish remote application,
managed-service or cloud acceptance.

## Phase 3F — independent provider-readiness evidence (completed slice)

### Concrete responsibility problem

The existing manager-authorized environment observation already used the
provider contract to read selected server metadata, but `CloudServerData`
discarded the provider lifecycle state. That made an observed server look like
an undifferentiated metadata response and left callers unable to distinguish a
provider-reported running instance from a stopped or otherwise unavailable
instance. Adding provider conditionals to the configuration query or preview
lifecycle would mix adapter response translation with application presentation
and could incorrectly treat local preview callbacks as proof of remote health.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** DigitalOcean, Hetzner Cloud and Vultr adapters
  translate their own response fields; `CloudServerData` carries the shared
  immutable transient result; the existing observation query and Blade view
  present it. No polling, persistence or reconciliation responsibility was
  added.
- **Dependency inversion:** the observation path continues to resolve the
  existing `ServerProvider` contract through the injected resolver. No new
  provider interface or service-location call was introduced.
- **Liskov substitution:** the shared provider contract now has behavioral
  coverage for ready and non-ready server responses across all three existing
  adapters, including each adapter's normalized raw lifecycle state.
- **Interface segregation:** the existing provider capability is sufficient;
  readiness is a field on its existing server result rather than a speculative
  health-monitoring interface.

### Implementation and preserved behavior

`CloudServerData` now exposes the transient `providerStatus` and one of
`ready`, `not_ready` or `unknown`. DigitalOcean reports `ready` only for an
`active` droplet with a public address; Hetzner requires `running` and a public
address; Vultr accepts its existing `power_status` (or fallback `status`) of
`running` or `active` with a public address. A missing lifecycle value is
`unknown`; a provider-reported non-ready state is `not_ready`. The raw state is
escaped display data and is not persisted.

The existing opt-in, manager-authorized observation query now carries these
fields to the configuration page. The UI labels the result **Provider
readiness** and **Provider lifecycle** and explicitly keeps it separate from
BuildPusher's recorded local state. It remains a one-time read with the
existing organization scoping, no-provider-call behavior for unavailable
placements, sanitized provider failures and no-write semantics. It is only
provider server readiness: it does not prove SSH access, application health,
PostgreSQL/Valkey/process health, remote drift or successful preview cleanup.
Existing constructor callers remain compatible because the new data fields are
optional and no routes, schemas, persistence values, queue payloads or
provider mutation behavior changed.

### Verification and remaining work

The focused Phase 3F provider contract and observation batch passed **10 tests
and 84 assertions**. The fresh isolated full PHP suite passed **1,366 tests
and 11,808 assertions**, with the one unchanged baseline failure in
`ProvisioningHardeningTest::test_website_database_user_is_local_only` (the
test expects three `localhost` occurrences and the current script contains
four). No Phase 3F test failed.

Required-PHP Composer validation/platform checks, full Pint, Vite build and
`git diff --check` passed. The feature commit `8a116dc` was fast-forwarded
through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13.
This is local adapter and presentation evidence; provider credentials, real
remote server transitions, application-level health and the separate live
acceptance drill remain outstanding. The exact next task was Phase 4A: start
the smallest curated service-template slice with explicit version,
compatibility/readiness and recovery metadata. That slice is recorded below;
its next task is Node/resource composition.

## Phase 4A — versioned curated service-template contract (completed slice)

### Concrete responsibility problem

The application-template configuration supplied runtime defaults and preview
children, but it did not describe the operational contract that makes a
published service template supportable. Project creation also discarded which
template revision supplied those defaults, so a later template edit could not
be reviewed against a project's installed baseline. Treating raw configuration
arrays as the contract would spread version, compatibility, readiness and
recovery assumptions across controllers, actions and preview code.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `ApplicationTemplateCatalog` normalizes trusted
  configuration; `ApplicationTemplateDefinition` and
  `ServiceTemplateMetadata` carry immutable application/operational data;
  `CreateProjectAction` records the selected version; and the controller/view
  only coordinate and present the result.
- **Dependency inversion:** project creation, the project form and
  `PreviewStackCatalog` consume the injected concrete catalog instead of
  reading template arrays independently. No generic repository or provider
  interface was introduced.
- **Open/closed:** adding a curated template is now a configuration/catalog
  extension with an explicit version and support contract; existing preview
  resource and process actions remain unchanged.
- **Interface segregation:** the catalog exposes only the preset definitions
  needed by its actual consumers. Installation, upgrade and remote health
  capabilities are not implied by this read-only metadata boundary.

### Implementation and preserved behavior

The three existing Laravel presets (`laravel`, `laravel-inertia` and
`laravel-api`) now share a version `1.0.0` service-template contract covering
PHP/Laravel compatibility, managed PostgreSQL and Valkey resources with
generated credential names, persistent locations and retention, web/process/
resource readiness checks, replica/resource limits, backup/restore scope,
upgrade guidance, partial-failure recovery and deletion/retention behavior.
The metadata contains no credential values and does not execute any new remote
operation.

`ApplicationTemplateCatalog` returns immutable definitions for the project
creation form, project action and preview stack catalog. New projects record
the selected curated version in nullable `projects.template_version`. Existing
projects and currently unpublished presets remain `NULL`; the migration does
not infer or rewrite their installed version. The field is never silently
updated when configuration changes, leaving a later upgrade workflow an
explicit review boundary. The project creation screen shows only the curated
version, resource count and readiness-check count, not generated credential
names or values.

Runtime defaults, preset keys, validation behavior, protected production
environment creation, entitled worker creation, preview child declarations,
initialization state, queue payloads, routes, response formats and existing
resource credentials remain unchanged. A populated disposable SQLite database
survived migration rollback/reapply with its project row intact and the new
column restored.

### Verification and remaining work

The focused Phase 4A catalog/project/runtime/preview batch passed **38 tests
and 326 assertions**. The fresh isolated full PHP suite passed **1,371 tests
and 11,839 assertions**, with the one unchanged baseline failure in
`ProvisioningHardeningTest::test_website_database_user_is_local_only` (the
test expects three `localhost` occurrences and the current script contains
four). No Phase 4A test failed.

Migration fresh/rollback/reapply on populated disposable SQLite, config-cache
creation/clear, required-PHP Composer validation/platform checks, full Pint,
Vite build and `git diff --check` passed. The feature commit `925baf5` was
fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on
2026-09-13. This slice defines support metadata and version identity only; it
does not claim Node composition, template installation/upgrade execution or
provider/cloud acceptance. The Phase 4B Node composition is recorded below.

## Phase 4B — Node preview composition (completed slice)

### Concrete responsibility problem

The existing generic `node` preset already supplied runtime commands for
projects, but preview selection treated it as an empty stack. Its preview
environment also did not carry the selected source environment's runtime
settings, so a Node preview could fall back to the default PHP runtime. The
resource and cleanup implementations were already generic; the missing
responsibility was an explicit catalog declaration and a lifecycle boundary
that preserved the source runtime configuration.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** `ApplicationTemplateCatalog` continues to
  normalize template data, `PreviewStackCatalog` selects declared children,
  `PreviewDeploymentLifecycle` copies environment runtime settings, and the
  existing environment actions, deployment snapshot service, readiness service
  and cleanup job retain their respective responsibilities.
- **Open/closed:** the Node composition is a versioned configuration/catalog
  extension. No provider-specific conditionals or second resource-provisioning
  implementation was introduced.
- **Liskov substitution:** Node uses the same managed-resource result, encrypted
  credential, callback readiness, retry, ownership and deletion contracts as
  the existing Laravel preview stack; the feature test exercises the shared
  lifecycle through a Node selection.
- **Dependency inversion:** preview orchestration remains connected to the
  existing catalog and resource/deployment collaborators. No HTTP request,
  provider client or generic repository was added to the stack boundary.

### Implementation and preserved behavior

The generic `node` preset is now curated at version `1.0.0`. It declares managed
PostgreSQL and Valkey, generated credential metadata, persistent-data and
backup/restore boundaries, web/resource readiness checks, a two-resource limit,
reviewable upgrade guidance, provisioning/cleanup recovery and deletion
behavior. It intentionally declares no worker process and no automatic
initialization command; application-specific migrations and seed data remain
part of the reviewed repository deployment. `nextjs` remains an existing but
unpublished runtime preset because its framework-specific lifecycle has not
been characterized.

When a preview is created, its environment now copies the selected nonpreview
environment's runtime type, version, build command, start command, port and
Dockerfile path. This makes the Node preset's existing runtime contract reach
the preview deployment snapshot. The default Laravel preview values remain
unchanged, while an explicitly configured source runtime is no longer lost.

The existing `ConfigurePreviewStackAction`, `SaveEnvironmentResourceAction`,
`PreviewResourceCredentials`, `DeploymentRequest`, `ConfigureResourcesScript`,
`PreviewStackReadiness`, `QueuePreviewStackCleanupAction` and cleanup job remain
the execution path. Database and Valkey values are generated/derived and
encrypted at rest; they are not copied from the source website environment.
Readiness remains callback-backed local resource state, and cleanup remains
exact-identity, preview-owned and retryable. The new metadata does not perform a
remote mutation or imply provider-side health. Existing projects are not
backfilled or silently retagged; newly created Node projects receive the
versioned identity through the Phase 4A project-creation boundary.

### Verification and remaining work

The focused catalog/project/runtime/preview batch passed **41 tests and 372
assertions**. The adjacent preview/concurrency/cleanup/readiness/PostgreSQL/
configuration-resource/runtime/project batch passed **56 tests and 518
assertions**. The fresh isolated full PHP suite, using the repository's
in-memory PHPUnit database configuration, passed **1,374 tests and 11,885
assertions** with the one unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script contains
four). A run that explicitly forced a file database produced three additional
configuration-environment failures and was discarded as an invalid baseline.

PHP lint, required-PHP Composer validation/platform checks, full Pint, Vite
build, config-cache create/clear and `git diff --check` passed. The feature
commit `b8c5871` was fast-forwarded through canonical `main` and pushed to
GitHub `origin/main` on 2026-09-13. Phase 4C is recorded below.

## Phase 4C — template lifecycle characterization and service boundary (completed slice)

### Concrete responsibility problem

The curated metadata could describe an operational contract, but it did not by
itself prove that the selected template reached the existing installation,
resource-provisioning or cleanup paths. Publishing another service such as
Mailpit would also be unsafe: the application has no matching resource type,
configuration schema, provisioning implementation, backup scope or exact
cleanup identity for it. A metadata-only addition would overstate support and
leave failures to be handled by unrelated orchestration code.

### Applicable principles and Laravel mechanisms

- **Single responsibility:** the catalog declares immutable support metadata;
  the repository deployment plan installs application dependencies and runs
  reviewed build revisions; `ConfigureResourcesScript` provisions supported
  local resources; and `PreviewStackCleanupScript` removes only captured,
  exact identities. The characterization test checks those boundaries without
  moving remote work into the catalog.
- **Open/closed:** a future service can be added only after its resource,
  readiness, backup/restore, upgrade, failure-recovery and deletion behavior
  is implemented through the existing contracts. This slice does not add a
  provider-specific conditional or a speculative service adapter.
- **Liskov substitution:** every published template is required to use the
  same preview resource installation and cleanup result semantics. The test
  renders each published definition through the shared PostgreSQL/Valkey paths
  and checks valid shell output.
- **Dependency inversion:** no new HTTP or provider dependency was introduced;
  the test resolves the existing catalog, preview-stack catalog, deployment
  plan and cleanup service that production code already uses.

### Implementation and preserved behavior

The existing standard repository deployment plan is the installation boundary:
`InstallDependenciesScript` installs lockfile-driven application dependencies,
the normal build stages execute the repository revision, and the existing
resource/process stages apply the captured environment snapshot. Curated
template upgrades therefore remain explicit reviewed deployments; there is no
automatic version mutation or unreviewed resource migration. The nullable
`projects.template_version` remains the initial-definition identity recorded by
Phase 4A.

Added `ServiceTemplateLifecycleTest` to characterize the published Laravel and
Node definitions. It verifies that their declared resources match the actual
preview stack, that each resource type is both a known environment resource and
supported by the resource installation/cleanup paths, that generated resource
and cleanup shell is syntactically valid, and that upgrade metadata remains
reviewable. The test also verifies the existing deployment plan still includes
dependency installation before repository build commands.

The model currently lists more resource types for ordinary environments, but
the curated preview lifecycle has complete installation and exact cleanup
support only for managed PostgreSQL and Valkey. Mailpit is therefore explicitly
deferred until its resource identity, generated configuration, readiness,
backup/restore, retry and deletion semantics can be implemented and tested
together. `nextjs` remains unpublished for the same evidence reason.

No route, API envelope, schema, persisted value, queue payload, provider call,
credential, installation command or existing template behavior changed in this
characterization slice.

### Verification and remaining work

The new lifecycle characterization passed **3 tests and 57 assertions**. The
adjacent service-template, release, PostgreSQL resource, preview cleanup,
project-creation and preview-deployment batch passed **41 tests and 408
assertions** with the isolated array session driver. An initial adjacent run
using unsupported `SESSION_DRIVER=sync` failed during Laravel test setup and
was discarded as environment evidence; it did not execute the affected
application assertions.

The feature commit `818ebc0` was fast-forwarded through canonical `main` and
pushed to GitHub `origin/main` on 2026-09-13. Phase 5A and the first Phase 5B
slice are recorded below. The exact next task is to characterize deployment
working-directory assumptions for per-service repository roots and a
multi-target impact preview without changing deployment strategies, approval
checks, revision identity, webhook idempotency, cancellation or stale-attempt
handling. Provider/cloud acceptance, template installation on real hosts and the
separate live drill remain outstanding.

## Phase 5A — deployment timeline and evidence (completed slice)

### Concrete responsibility problem

The build page already displayed individual status fields, setup stages, logs,
failure guidance and rollback controls, but the lifecycle meaning was split
between the Livewire component, the setup partial and the deployment plan. A
user could inspect a log, yet had no single bounded view of the request,
approval, source preparation, build, application preparation, release
activation, traffic routing, resource configuration, health validation and
finalization path. The page also showed only a shortened revision and did not
surface the actor or the existing configuration-operation identity.

### Responsibility boundary and applicable principles

- **Single responsibility:** `BuildDeploymentTimeline` interprets the existing
  monotonic callback progress and deployment-plan stages into immutable
  `DeploymentTimelineEntry` values. Livewire coordinates authorization, eager
  loading and view data; the Blade view presents the result.
- **Dependency inversion:** the timeline receives the existing
  `RepositoryDeploymentPlan`, so it follows the one source of truth for stage
  order rather than copying stage numbers into a UI component.
- **Liskov substitution:** the reader consumes the same persisted status and
  setup-stage contract used by callbacks and jobs. Successful legacy builds are
  treated as complete because their persisted terminal status is the stronger
  completion signal; failed, canceled and active builds retain recorded partial
  progress.
- **Laravel mechanisms:** an Eloquent `Build::configurationOperation()` relation
  reuses the existing unique `configuration_operations.build_id` link. No
  repository abstraction or write path was introduced.

### Implementation and preserved behavior

Added `RepositoryDeploymentPlan::stageFor()` and the injected
`BuildDeploymentTimeline` reader. The reader reports request, conditional
approval, provisioning, build, application preparation, release activation,
traffic routing, managed resources, health verification and finalization. It
marks only persisted lifecycle stages as completed/active/failed/canceled or
pending. It exposes the recorded request, approval, release activation and
finalization times; intermediate callbacks currently persist no individual
timestamps, so the UI explicitly says when a milestone has no timestamp rather
than inferring one from `started_at` or `finished_at`.

The build page now shows the complete revision when one exists, the requesting
and approving/rejecting account identity, and the exact configuration review,
application, operation and intent-digest identity for configuration-driven
builds. It never renders the encrypted operation payload. Existing log bounds,
polling, authorization, approval actions, deployment strategies, release
retention, rollback eligibility, queue behavior and callback semantics are
unchanged. The new relation is read-only and ordinary builds continue to have
no configuration identity.

### Verification and limitations

`BuildDeploymentTimelineTest` covers successful legacy progress, active stage
ranges, failed first-unrecorded stages, canceled partial progress and approval
states. `DeploymentTimelineTest` covers the authorized build page, exact
revision/actor/configuration identity and encrypted-payload non-disclosure.
The focused deployment timeline/history/log batch passed **16 tests and 134
assertions**. The fresh isolated full PHP suite passed **1,383 tests and 11,979
assertions** with the one unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script contains
four). Pint, changed-file PHP lint and `git diff --check` passed.

Commit `b5d1cab` (`feat: clarify deployment lifecycle evidence`) was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on
2026-09-13. This is local application evidence; no provider, cloud or live
acceptance was performed. The first Phase 5B path-filter slice below is now
complete. The exact next task is to characterize per-service repository-root
execution and multi-target impact preview behavior without weakening webhook
idempotency, revision attestation, approval checks, deployment serialization,
cancellation or stale-attempt protection.

## Phase 5B — conservative automatic deployment path filters (completed slice)

### Concrete responsibility problem and characterization

The repository model had no service-root or include/exclude path settings, and
the webhook verifier discarded provider file lists. Every matching branch push
therefore followed the existing deployment path, even when one deployment target
represented only a subdirectory of a larger repository. The characterization also
confirmed that the deployment scripts still assume one checked-out repository
root per target; no safe root execution boundary or multi-service planner existed.
GitHub and GitLab push payloads can carry bounded commit file lists, while the
current Bitbucket payload path does not provide enough changed-file detail for a
safe filter decision.

### Responsibility boundary and applicable principles

- **Single responsibility:** `RepositoryPath` owns relative-path normalization
  and glob matching, `RepositoryPathPattern` owns HTTP validation,
  `RepositoryWebhookVerifier` normalizes provider file-list metadata, and
  `RepositoryChangeImpactEvaluator` makes a pure include/exclude decision.
  Webhook actions retain deduplication, website locking, pending coalescing and
  dispatch coordination; the repository/history views present the outcome.
- **Dependency inversion:** webhook actions receive the concrete pure evaluator
  through their constructors rather than embedding provider or glob logic in the
  transaction. No external-service interface was invented because this boundary
  has no remote dependency.
- **Liskov substitution:** providers that do not report changed paths continue to
  satisfy the deployment contract by producing an unknown decision that queues
  conservatively. Existing providers and legacy deliveries remain valid with
  nullable path data.
- **Laravel mechanisms:** the existing `RepositoryRequest` normalizes the
  newline-oriented form fields and validates bounded arrays; Eloquent JSON casts
  preserve the optional filters and delivery path lists; the existing action and
  history query boundaries remain responsible for writes and tenant-scoped reads.

### Implementation, intentional change and preserved behavior

Repositories now accept optional `auto_deploy_include_paths` and
`auto_deploy_exclude_paths` settings. Patterns are relative to the repository
root, support single-segment `*`, recursive `**` and `?`, reject absolute paths,
traversal segments and control characters, and are capped before persistence.
GitHub and GitLab changed paths are normalized, deduplicated and capped at 200;
malformed or missing provider file data becomes unknown. Bitbucket remains
unknown for this decision because its current webhook payload does not provide a
safe file list.

An affected or unknown push follows the existing queue/pending/build path. A
known push outside the configured scope is recorded as the new explicit
`skipped` delivery outcome and creates no build or queue job. Exclusions win over
inclusions. When pending deliveries are coalesced, all retained path sets are
merged; any missing or unsafe set makes the aggregate unknown so an earlier
relevant change cannot be skipped. Empty filters preserve the old deploy-every-
matching-push behavior. Manual deployments, approvals, revision identity,
website locks, idempotency, cancellation, stale-attempt protection, provider
authentication, secret handling and dispatch timing are unchanged. Skipped
history is included in repository/dashboard metrics and terminal webhook
retention pruning.

This is an intentional, opt-in behavior change for automatic push deployments.
The migration leaves existing repositories with null filters and existing
delivery rows with null changed paths, so no backfill or reinterpretation is
required. The UI documents that each repository record is one deployment target
and that shared dependency files must be included explicitly for every dependent
target. No repository-root execution, automatic shared-dependency inference or
multi-service preview was introduced in this slice.

### Verification and limitations

The focused evaluator, webhook, request, history, dashboard, demo-seed and
retention batch passed **57 tests and 976 assertions**. It covers affected,
unaffected, unknown and unsafe paths; exclusion precedence; GitHub/GitLab path
extraction; conservative Bitbucket behavior; no-build skips; pending delivery
coalescing; no side effects on skipped/unknown decisions; filter normalization and
omitted-field preservation; tenant-scoped history; dashboard metrics; demo data;
and pruning. The fresh isolated full PHP suite passed **1,392 tests and 12,027
assertions** with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script contains
four). Full Pint and `git diff --check` passed.

Commit `c79c736` (`feat: add safe monorepo path filters`) was fast-forwarded into
canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. This is local
application evidence only; provider-side cloud acceptance and the separate live
drill remain outstanding.

The exact next task is to characterize the deployment scripts' working-directory
and release-artifact assumptions, then implement a separately verified
per-service repository-root boundary and a read-only multi-target impact preview.
Do not infer shared dependencies or skip a target when changed paths are
unavailable.

## Phase 5B — per-service repository roots (completed slice)

### Characterization and concrete responsibility problem

The deployment scripts previously assumed that every target used the whole
repository checkout. The checkout is created under the deployment slug, then
the setup directory becomes a retained release and the current-release
symlink. Build hooks, dependency installation, Artisan commands, canary checks,
post-deployment commands and process runners use that working directory;
web-root, Caddy, logs, scheduled tasks and restore maintenance commands also
need the service's effective path. A repository could therefore describe a
subdirectory in a monorepo only by convention; cloning still prepared and
executed the repository root.

### Responsibility boundary and applicable principles

- **Single responsibility:** `RepositoryPath` owns safe relative-root
  normalization and path composition, `RepositoryDeploymentRoot` owns request
  validation, `Build` owns the immutable deployment-root snapshot/fallback and
  `WebsiteCaddyConfiguration` owns shared PHP/reverse-proxy document rendering.
  Deployment scripts and website-level jobs consume those boundaries instead
  of reconstructing service paths independently.
- **Dependency inversion:** Caddy rendering is an injected collaborator for
  runtime configuration and domain application jobs. The deployment-root
  value travels through the existing repository/build payload boundary rather
  than introducing a repository abstraction or service locator into scripts.
- **Open/closed and compatibility:** the existing default repository root
  remains the behavior for legacy rows and blank/`.` values. A non-default
  root extends the existing deployment path contract across the established
  script/job consumers without changing provider adapters or deployment
  strategies.
- **Laravel mechanisms:** `RepositoryRequest` validates and normalizes the
  optional field, Eloquent persists it as a nullable value, `DeploymentRequest`
  snapshots it into new build payloads, and existing actions/jobs/scripts keep
  writes, dispatch, locks and remote execution in their established places.

### Implementation, intentional change and preserved behavior

Repositories now have an optional relative service root. Absolute paths,
traversal, control characters, wildcards and oversized values are rejected;
blank, `.` and `./` resolve to the repository root. New non-default deployment
payloads record `repository_root`, so clone, checkout, dependency installation,
build hooks, Artisan, canary, release symlink, post-deployment, process,
runtime, Caddy, log, scheduled-task and restore paths resolve to the selected
service directory. The release remains a whole checkout under the existing
deployment slug; this slice does not create separate release artifacts.

Rollback payloads and preview repositories preserve the selected root, and
configuration repository identity includes it so a changed root invalidates a
pending review. Historical builds without a root snapshot use the existing
repository fallback. Website-level maintenance follows the latest successful
service deployment when one is available, with the existing repository
fallback otherwise. The default payload shape and all default script paths
remain unchanged. No new configuration-document YAML field or API response
field was added, and no automatic shared-dependency inference, multi-target
orchestration or path-based impact preview was introduced.

This is an intentional opt-in behavior change for a repository deployment
target: a configured service root scopes execution and document-root behavior
to that relative directory. Teams with multiple services must still configure
each target explicitly and include shared dependency paths in the existing
path-filter settings. Existing installations and queued payloads remain
compatible; no remote resources are created or deleted by this boundary.

### Verification and limitations

The focused repository-root, preview, rollback, backup, hooks, runtime,
domain, website-security and environment-runtime batch passed **60 tests and
586 assertions**. The fresh isolated full PHP suite passed **1,396 tests and
12,086 assertions** with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script contains
four). Required-PHP Composer platform checks, full Pint, Vite asset build,
changed-file checks and `git diff --check` passed. This is local application
evidence only; provider-side cloud acceptance and the separate live drill
remain outstanding.

Commit `72d7c69` (`feat: support per-service repository roots`) was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on
2026-09-13.

The read-only multi-target impact preview described above is now complete. The
Phase 6 characterization, read-only evidence and isolated verification slices
are recorded below. The current exact next task is Phase 7: connect
environment, deployment, logs, health and incident context with bounded,
authorization-checked reads; preserve the separate provider/cloud acceptance
track.

## Phase 5B — read-only multi-target impact preview (completed slice)

### Concrete responsibility problem

The automatic webhook path filter already evaluated one repository target, but
users had no way to answer which enabled services would be affected by the
same changed-file set before sending or replaying a push. Repeating that logic
in a controller would risk different include/exclude or unknown-path semantics
from the webhook workflow and could accidentally become a deployment trigger.

### Responsibility boundary and applicable principles

- **Single responsibility:** `RepositoryImpactPreviewQuery` composes the
  existing tenant-scoped `RepositoryInventoryQuery` with the pure
  `RepositoryChangeImpactEvaluator`; `RepositoryImpactPreview` and
  `RepositoryImpactPreviewTarget` carry the immutable read result. The
  controller only authorizes, invokes the query and renders the response.
- **Dependency inversion:** the preview depends on the existing evaluator and
  inventory collaborators rather than provider payloads, Eloquent writes or
  webhook orchestration. No new provider contract or generic repository was
  introduced.
- **Laravel mechanisms:** `RepositoryImpactPreviewRequest` owns bounded
  newline-path validation and the explicit unavailable-data mode;
  `RepositoryPolicy::viewAny` protects the workspace inventory; eager-loaded
  website data avoids per-target relationship queries; the GET route and Blade
  view are read-only.

### Implementation, intentional change and preserved behavior

Added `repositories.impact-preview`, linked from the repository inventory. A
workspace member can enter normalized relative changed paths or explicitly
mark the provider path list unavailable. The query evaluates only repositories
with enabled push webhooks in the selected workspace, ordered deterministically,
and displays each service root, configured scope and at most the first five
matched paths. Affected, unaffected and unknown totals are shown together;
unknown data remains conservative and is described as deployable. Foreign
repositories, disabled push targets and repository credentials are not
rendered.

This is an opt-in read-only feature. It creates no builds, webhook deliveries,
jobs, provider calls or persisted state and does not alter automatic webhook
behavior. Empty path input is rejected; unavailable path data is an explicit
preview mode. Existing webhook behavior remains conservative when provider
paths are missing, shared dependencies still require explicit target filters,
and no shared-dependency inference or cross-service orchestration was added.

### Verification and limitations

The new preview feature suite passed **4 tests and 24 assertions**. The
repository/deployment regression batch passed **61 tests and 524 assertions**,
including tenancy, path-filter, root, webhook, history, rollback and no-side-
effect behavior. The fresh isolated full PHP suite passed **1,400 tests and
12,111 assertions** with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script contains
four). Full Pint, changed-file PHP lint, route registration and
`git diff --check` passed. No frontend assets changed in this slice. This is
local application evidence only; provider-side cloud acceptance and the
separate live drill remain outstanding.

Commit `3940a28` (`feat: preview repository deployment impact`) was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on
2026-09-13.

The Phase 6 characterization, read-only evidence and isolated verification
slices are recorded below. The current exact next task is Phase 7: connect
environment, deployment, logs, health and incident context with bounded,
authorization-checked reads while preserving restore destinations, overwrite
safeguards, duplicate protection, retries, partial-failure cleanup and existing
dispatch semantics.

## Phase 6 — backup recovery characterization (completed investigation)

### Concrete responsibility problem

The managed-backup dashboard mixed backup history loading with recovery
semantics in `BackupController::index()`. Its metrics were derived from the
latest 50 mixed-status backup rows, so an older successful backup could fall
out of the read window. The first card also described any successful backup as
a “recovery point”, while a successful `BackupRestore` row represented an
in-place restore job—not an independently verified restore drill.

### Existing lifecycle and evidence boundaries

- `QueueWebsiteBackupAction` and `RunWebsiteBackupsCommand` create queued
  `WebsiteBackup` records and preserve manual duplicate prevention and the
  schedule lock/last-queued guard. Dispatch remains after the local transaction
  so synchronous queues can execute using the existing semantics.
- `CreateWebsiteBackupJob` transitions queued backups to running, creates a
  MySQL dump plus `.env` and persistent storage archive, sends them to the
  encrypted Restic destination, applies retention and records `snapshot_id`,
  `size_bytes` and `completed_at`. `https_verified_at` is per-backup HTTPS
  transport evidence; it does not prove independent hosting or data integrity.
  Failed queue attempts use the existing bounded `error` and terminal status.
- `RequestWebsiteBackupRestoreAction` checks the completed snapshot and active
  deployment guard, creates a queued `BackupRestore` inside a transaction and
  dispatches the existing restore job. It currently does not create an isolated
  target, persist a destination/overwrite review, prevent duplicate queued
  restores, or record a recovery-attempt stage.
- `RestoreWebsiteBackupJob` revalidates the snapshot, deployment state and
  server credential, takes a remote safety dump/environment/storage copy,
  restores into the live website, runs the configured live health check, and
  removes its temporary stage on success. Its shell rollback protects the live
  application after a remote error, while the job's `failed()` callback records
  only terminal status, completion time and a bounded error. There are no
  persisted integrity-check, application-smoke, cleanup, isolated-target or
  independent-verification fields.
- `BackupDestination` keeps access, secret and repository credentials encrypted;
  `last_verified_at` is destination-level operational history. It must not be
  treated as proof that every historical backup was verified.
- `BackupDatabaseCommand` and `VerifyDatabaseBackupsCommand` operate on local
  file-backed SQLite snapshots through `SqliteBackupVerifier`. This is
  BuildPusher control-plane backup/integrity evidence, separate from managed
  website application-data backups and restores. The acceptance audit also
  explicitly reports recorded lifecycle evidence rather than claiming restored
  fixture comparison, real-provider provenance or cleanup confirmation.

### Decision for the next slice

The smallest safe implementation is a read-only, organization-scoped recovery
summary query used by the existing backups page. It will report completed
managed backups, per-backup HTTPS transport evidence, completed in-place restore
records and measured restore duration independently. The UI will explicitly
show that an independent restore verification has not yet been recorded rather
than relabeling the existing in-place restore as a verified drill. No restore
job, destination, overwrite behavior, queue semantics, schema or control-plane
backup command changes in this slice.

This boundary applies single responsibility by moving tenant-scoped evidence
selection out of the controller, dependency inversion by making the controller
depend on an injected read collaborator, and Laravel query discipline through
bounded eager-loaded history plus targeted aggregate/latest queries. The later
isolated restore-test slice must build on the characterized job safety behavior
and add explicit destination, integrity, smoke, stage and cleanup contracts.

**Phase 6 characterization exit gate: complete.** No application behavior was
changed by this investigation. The read-only recovery summary and honest
dashboard indicators described above are now implemented and verified below.

## Phase 6 — read-only backup recovery evidence summary (completed slice)

### Concrete responsibility problem

`BackupController::index()` selected the latest 50 mixed-status backup rows and
calculated recovery metrics from that bounded collection. This made an older
successful backup disappear from the summary when newer failures filled the
window. The controller also presented a completed in-place `BackupRestore` as
restore-drill evidence even though the existing job does not persist an
independent restore test, integrity check, application smoke result or cleanup
status.

### Responsibility boundary and applicable principles

- **Single responsibility:** `BackupRecoveryEvidenceQuery` owns
  organization-scoped backup-history and evidence selection. The controller
  now coordinates the existing destination/website reads, invokes the query
  and returns the view; it no longer defines recovery semantics.
- **Dependency inversion:** the controller receives the concrete read
  collaborator through its constructor. The query uses Eloquent relationships
  and targeted latest-record queries without introducing a generic repository
  or provider abstraction.
- **Stable read boundary:** immutable `BackupRecoverySummary` carries converted
  timestamps and measured restore duration. Its nullable independent-verification
  field is intentionally empty until a later persisted verification workflow
  supplies real evidence.
- **Laravel mechanisms:** tenant scoping remains in `whereHas('website')` and
  `whereHas('backup')`; history remains capped at 50 rows with eager-loaded
  website/destination/restore relations; summary queries are independent of
  that presentation limit and do not write or dispatch.

### Implementation and preserved behavior

The backups page now shows five separate indicators: latest completed managed
backup, latest per-backup HTTPS transport evidence, latest completed in-place
restore, independent restore verification and observed restore duration. The
independent verification card says `Not recorded` rather than claiming that a
live restore was an isolated drill. The control-plane SQLite backup/verifier is
not mixed into these managed website metrics.

The existing 50-row history, destination/schedule lists, restore form,
confirmation, routes, flash messages, encrypted fields, job serialization,
restore safety rollback and dispatch timing are unchanged. This slice adds no
migration, provider call, queue job, restore target, overwrite behavior or
persisted state. Foreign workspace records remain excluded, credentials are
not rendered and the summary performs no side effects.

### Verification and limitations

The new recovery-evidence feature and existing managed-backup/release-audit
regression set passed **12 tests and 120 assertions**. The strict isolated full
PHP suite passed **1,402 tests and 12,129 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script contains
four). Changed PHP files pass lint and Pint; `git diff --check` passed. No
frontend assets changed. This is local evidence only and does not establish
provider/cloud acceptance or the separate live drill.

Commit `764588e` (`feat: clarify backup recovery evidence`) was fast-forwarded
into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13.

**Phase 6 read-only evidence exit gate: complete.** The isolated
restore-verification characterization and execution slices are recorded below;
the existing in-place restore workflow remains separate and unchanged.

## Phase 6B — isolated restore-verification characterization (completed investigation)

### Concrete responsibility problem

The existing `RestoreWebsiteBackupJob` restores directly into the live website:
it enables maintenance, takes a safety dump/environment/storage copy, replaces
live data, optionally checks the live health URL and rolls back on a remote
error. That is an important recovery operation, but it cannot prove that a
retained snapshot can be restored and exercised without risking a production
overwrite. `BackupRestore` has no target kind, overwrite decision, integrity or
application-smoke result, failure stage, cleanup result or duration field.

### Safe protocol boundary

The first execution slice will use a deterministic temporary directory and
temporary MySQL database on the website's existing managed server. Restic will
restore the exact snapshot captured at request time into that directory. The
database dump will be imported into a uniquely named temporary database after
rewriting only its validated original database identifier; the live database,
live `.env`, live persistent storage and maintenance state are never changed.
The temporary storage archive and `.env` must exist. Database import plus a
nonempty-table check is the integrity result. For Laravel service roots, the
current application release will run a safe `php artisan migrate:status`
against the temporary database as the application smoke result. Unsupported
runtime layouts fail closed and are not recorded as verified.

The remote script will emit bounded stage/status markers and use an EXIT trap
to remove both temporary storage and the temporary database. A cleanup failure
will make the verification fail even if restore and checks succeeded. The
application records the snapshot identity, target kind, no-overwrite mode,
check statuses, failure stage, bounded error, completion time and duration.
Remote work stays outside the local transaction; dispatch remains after the
verification row is committed so synchronous queues retain their existing
execution behavior.

### Responsibility boundary and guarantees to preserve

- `VerifyWebsiteBackupRequest` and `WebsiteBackupPolicy::verify` will own the
  HTTP confirmation, authorization and entitlement boundary. A new request is
  not a replacement for the existing destructive restore form.
- `RequestWebsiteBackupVerificationAction` will lock the backup row, bind the
  verification to its exact snapshot, reject an active deployment and prevent
  duplicate queued/running attempts before dispatching the job.
- `VerifyWebsiteBackupJob` will coordinate durable state transitions and
  sanitized failure persistence. A dedicated script collaborator will own
  shell quoting, stage markers and cleanup rather than putting remote protocol
  details in the controller or action.
- The existing in-place restore action/job, confirmation text, safety rollback,
  retries, routes and dispatch semantics remain unchanged. The verification
  path will not create provider resources, overwrite a live target, expose
  credentials or claim that a live health check proves isolated recovery.

**Phase 6B characterization exit gate: complete.** No application behavior was
changed by the investigation itself. The persisted execution boundary,
remote integrity/smoke/cleanup script and focused failure/concurrency coverage
are implemented in the execution slice below.

## Phase 6B — isolated restore verification (completed execution slice)

### User problem and responsibility boundary

The existing restore workflow safely replaces live website data and can roll
back on remote failure, but it cannot answer whether a retained snapshot can
be restored and exercised without an overwrite. The new workflow makes that
answer durable while keeping the destructive restore operation unchanged.

- `VerifyWebsiteBackupRequest` owns the exact website-name confirmation,
  policy authorization and backup entitlement check. `WebsiteBackupPolicy`
  exposes a separate `verify` ability while preserving the existing `restore`
  ability and workspace scoping.
- `RequestWebsiteBackupVerificationAction` owns the local transaction, locks
  the backup row, revalidates workspace ownership and snapshot validity,
  rejects active deployments and duplicate queued/running attempts, then
  dispatches only after the verification row commits.
- `VerifyWebsiteBackupJob` owns durable queued/running/succeeded/failed
  transitions, exact snapshot revalidation, bounded stage markers and
  sanitized failure persistence. `VerifyWebsiteBackupScript` owns the remote
  shell protocol, quoting, integrity/smoke checks and trap-backed cleanup.
- `BackupRecoveryEvidenceQuery` and `BackupRecoverySummary` expose only
  successful independent verification as a separate dashboard indicator; the
  controller coordinates the request/action/query and does not perform remote
  work or persistence.

This applies single responsibility and dependency inversion with concrete
Laravel requests, policy, action, job, query and integration boundaries. No
generic repository, universal action base, new provider interface or service
locator was introduced in the business operation.

### Behavior, safety and compatibility

The `backup_restore_verifications` table records the exact snapshot identity,
fixed `same_server_temporary` target, `never` overwrite mode, integrity/smoke/
cleanup statuses, failure stage, bounded error, timestamps and duration. The
request route is `POST backups/{backup}/verify`. A failed attempt is visible in
the existing backup history and can be retried as a new attempt; an active
attempt remains duplicate-protected by the locked backup row.

The supported remote protocol restores the exact Restic snapshot into a
deterministic temporary directory on the same managed server, imports the
database dump into a unique temporary MySQL database after rewriting its
validated identifier, checks the restored database/storage/.env contents,
executes `php artisan migrate:status` against the temporary database and
removes both temporary resources in an EXIT trap. Cleanup failure makes the
verification fail. The remote execution uses `Runner::create(false)`, so
remote output is not attached to normal command logging; persisted errors and
the UI expose only bounded stage messages, never command output or
credentials.

The live database, `.env`, persistent storage, maintenance state, provider
resources and existing in-place restore job are untouched. Dispatch remains
outside the local transaction, synchronous queues retain their post-commit
execution behavior, queued job compatibility is preserved, and unsupported
target modes fail closed. This is an intentional new recovery-verification
workflow, not a relabeling of an in-place restore or HTTPS transport evidence.

### Scope limitations and verification

This first execution slice supports Laravel service roots with MySQL on the
existing managed server and a stored MySQL root credential. It does not yet
provide an isolated external host, PostgreSQL restore adapter, arbitrary
runtime smoke checks, production-data copying, provider cleanup evidence or
scheduled restore drills. Those limitations are explicit; mocked remote
runner tests are not cloud acceptance or the separate live drill.

The focused backup verification, recovery evidence and managed-backup
regression set passed **13 tests and 132 assertions**. The fresh strict
isolated full PHP suite passed **1,408 tests and 12,199 assertions**, with the
unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure (the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, changed PHP
lint, full Pint, Vite build, route registration, `git diff --check` and the
required-PHP asset/browser suite (**9 passed**) passed. No production or cloud
resource was changed.

Commit `a9b8730` (`feat: add isolated website backup verification`) was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on
2026-09-13.

**Phase 6B execution exit gate: complete for the supported local path.** The
current exact next task is Phase 7 inventory: connect environment, deployment,
logs, health and incident context through bounded, authorization-checked
read collaborators while keeping provider/cloud acceptance separate.

## Phase 7 — connected observability inventory (completed investigation)

### Current journeys and entry points

The authenticated and verified `GET /observability` route renders the current
workspace's server telemetry, metric rules, alert destinations, public status
pages, status incidents and a bounded list of recent deployment and failed-health
signals. `ObservabilityController::index()` coordinates the request and calls
`ObservabilityDashboardQuery`; it does not perform writes. Operational incident
actions have their own controller and CSV export route. Website detail and
health-history routes expose retained runtime-log snapshots and paginated health
checks. Build detail and its Livewire status component expose the existing
deployment log, failure guidance and plan-driven timeline.

### Existing boundaries and evidence

| Concern | Existing implementation and contract | Current gap/classification |
| --- | --- | --- |
| Workspace access | The verified web group supplies authentication. Resource policies use current-workspace membership and `Organization::permits()`. Operational incidents use `OperationalIncidentPolicy` for export and response actions. | The dashboard read is implicitly current-workspace scoped rather than represented by a dedicated read policy. A new context read must authorize the current workspace before accepting an environment selector and must not turn resource/state guards into permissions. Existing with a read-boundary improvement. |
| Deployments | `ObservabilityDashboardQuery` loads at most 10 terminal `Build` records through the repository's organization relationship. `BuildPolicy`, `BuildsController`, `BuildDeploymentTimeline` and `DeploymentFailureGuidance` preserve build visibility, immutable revision context, bounded logs and recovery guidance. | The dashboard cannot focus on one `Environment`, show active attempts, or filter a bounded deployment window. Existing with correlation/readability gap. |
| Health | The dashboard loads at most 10 failed `WebsiteHealthCheck` records through the website organization relationship. `WebsiteHealthHistoryQuery` owns retained limits, filters, metrics and exports; website policy checks visibility. | Health history is available only after navigating to a website. A context view should reuse the same website-scoped relationship and preserve retained/temporal bounds. Existing with navigation gap. |
| Runtime logs | `WebsiteLogSnapshot` stores encrypted, bounded application/access snapshots. Website routes authorize `WebsitePolicy`, validate supported types, return no-store responses and queue refreshes through `QueueWebsiteLogRefreshAction`. Build and provisioning logs have separate authorized views. | The dashboard does not expose a context link to a runtime-log surface and must never load or render encrypted log bodies merely to summarize an environment. Existing with safe-link gap. |
| Incidents and grouping | `IncidentNotifier` deduplicates active incidents by workspace/category/resource, records encrypted summaries/events and resolves them on recovery. `ObservabilityDashboardQuery` loads at most 50 current-workspace incidents with assignee/events; export remains a separate user-requested CSV. | Incident rows use category/resource IDs rather than an environment ID. Context correlation must explicitly map supported categories (environment build, website, server, metric rule, scheduled task and provider) and omit ambiguous records rather than guessing. Existing with explicit-correlation gap. |
| Metrics and alerts | Server metrics are eager-loaded to 24 samples per server; metric rules and alert destinations are workspace scoped. Alert delivery and recovery semantics are covered by existing actions/jobs. | This slice must not add polling, remote observations, alert regrouping or provider calls. Those remain later work after a read-only context proves useful. Existing and out of first-slice scope. |
| Authorization and side effects | The index is read-only. Website/build/incident links re-enter their existing policy-protected endpoints. No context read may write, dispatch a job, invoke a provider or expose secrets. | Need direct tests for foreign environment IDs, viewer access, no side effects and secret/log-body exclusion. |

### Proposed smallest verified slice

Add an authorization-checked, read-only **environment evidence context** below
the existing observability dashboard. It accepts a selected current-workspace
environment and one of the bounded windows `24h`, `7d` or `30d`, then loads:

- the environment's safe project/website/server identity;
- recent environment-linked builds, including active attempts, with links to
  the existing build detail/timeline and no build-log bodies;
- recent website health checks within the selected window, with a link to the
  existing filtered health history;
- current encrypted runtime-log snapshot metadata only, with links to the
  existing authorized website log surface;
- operational incidents explicitly related to the environment's build,
  website, server, metric-rule, scheduled-task or provider IDs.

The read model will carry a visible “related evidence”/“possible correlation”
label. It will not infer causation, merge unrelated resources, add a generic
polymorphic repository, or claim remote drift. The first slice will use query
parameters rather than persisted saved views; shareable/saved investigation
URLs, severity/service filters, alert grouping and observation controls remain
subsequent slices with their own authorization and retention decisions.

### Responsibility boundary and applicable principles

- **Single responsibility:** `ObservabilityDashboardQuery` remains the broad
  dashboard read. A new `ObservabilityEnvironmentContextQuery` owns only the
  selected environment's bounded evidence relationships and explicit incident
  mapping. The controller continues to authorize, pass validated values and
  return a view.
- **Dependency inversion:** a `ObservabilityContextRequest` owns the finite
  window and tenant-scoped environment selection; the query receives explicit
  values and never reads an HTTP request or resolves an actor from the
  container.
- **Liskov/tenant safety:** build, website, health and incident records are
  selected through organization/environment relationships and existing policy
  entry points. Unsupported incident categories are not treated as related.
- **Laravel mechanisms:** Form Request normalization, Eloquent relationships,
  bounded eager loading, existing policies and no-store links where sensitive
  log output is returned.

### Verification and completion criteria

Add behavior tests proving that an authorized member can select its own
environment and see only bounded, related evidence; a foreign environment is
rejected before any read is rendered; an unauthorized viewer cannot bypass the
workspace boundary; active and terminal builds are included within the window;
health/log links preserve their existing route contracts; encrypted log bodies,
environment variables, provider credentials and incident encrypted summaries
are not included in the context response; and the read performs no writes,
queue dispatches or provider calls. Add query-count/bound assertions where
they can be made stable. Run the focused observability/deployment/health/log
suite, Pint, lint and the full isolated PHP regression suite before integration.

This is local application evidence only. Provider-side monitoring, cloud
acceptance and the separate live acceptance drill remain outstanding.

**Phase 7 inventory exit gate: complete.** The exact next task was to implement
the bounded environment evidence context and push its verified feature commit.

## Phase 7A — bounded environment evidence context (completed slice)

### Problem and entry points

The existing observability dashboard showed workspace-wide deployment and
failed-health lists, but it did not let an operator select one environment and
follow its deployment, health, runtime-log and operational-incident evidence.
The new read path is linked from `GET /observability` and the project
environment view, and is served by
`GET /observability/environments/{environment}/context`. The website detail
page now exposes a stable anchor for the existing runtime-log surface.

### Responsibility boundary and preserved behavior

`ObservabilityContextRequest` authorizes the existing `EnvironmentPolicy` and
normalizes only the finite `24h`, `7d` and `30d` window values. It rejects a
foreign or unauthorized environment before validating an unsupported window.
`ObservabilityEnvironmentContextQuery` owns the explicit, bounded Eloquent
read: recent or active environment builds, recent website health observations,
metadata-only current log snapshots and incident relationships for builds,
website, server, metric-rule, scheduled-task and provider resources.
`ObservabilityEnvironmentContext` is the immutable secret-safe read model;
the controller only passes validated filters and returns the view.

This applies single responsibility and dependency inversion without adding a
generic repository or provider abstraction. Existing build, health, log and
incident routes remain the authorization and sensitive-content boundaries.
The context does not render build-log bodies, health error/endpoint text,
encrypted runtime output, incident summaries/resolutions/events, provider
credentials or environment secrets. It performs no writes, queue dispatches,
provider calls, persistence changes or causal inference. Active builds and
open/acknowledged incidents remain visible for recovery context even when they
predate the selected window; terminal builds and resolved incidents are window
bounded. Existing dashboard, project, website, response, route and API
contracts are otherwise unchanged.

### Verification and limitations

`ObservabilityEnvironmentContextTest` passed **3 tests / 29 assertions** inside
the focused observability run of **54 tests / 464 assertions**. The tests cover
viewer access, foreign-tenant denial before malformed-window validation,
finite windows, per-collection bounds, active-build retention, explicit
incident mapping, link contracts, secret/body exclusion and no writes. The
fresh strict isolated PHP suite passed **1,411 tests / 12,228 assertions**,
with the unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure (the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, Vite build, route registration, `git diff --check` and the required-
PHP asset/browser suite (**9 passed**) passed.

This is a local read-only evidence path. It does not add saved investigation
views, service/severity filters, alert grouping, polling, remote health
observation or a claim that adjacent signals caused an incident. Provider-side
monitoring, cloud acceptance and the separate live acceptance drill remain
outstanding.

**Phase 7A exit gate: complete for the bounded context slice.** Feature commit
`5661873` (`feat: connect environment observability evidence`) was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on
2026-09-13. The exact next task is to add the next bounded troubleshooting
slice: explicit service/deployment and incident-severity filters with the same
authorization, query bounds and possible-correlation wording.

## Phase 7B — bounded observability evidence filters (completed slice)

### Problem and entry points

The environment context initially supported only a time window. Operators
could not narrow deployment evidence to one repository service, separate active,
successful and unsuccessful deployment groups, or focus incidents by severity.
The existing context route and dashboard/project links remain the entry points;
this slice extends their query-string contract without adding persistence or a
new endpoint.

### Responsibility boundary and preserved behavior

`ObservabilityContextRequest` validates service IDs against non-deleted
repository targets on the selected organization-owned website and normalizes
the finite deployment groups `all`, `active`, `successful` and `unsuccessful`
plus the existing incident severities. `ObservabilityContextFilters` carries
the immutable values, including the selected repository ID, and
`ObservabilityEnvironmentContextQuery` applies them to bounded build and
incident reads. The context read model carries the available service metadata
so the view can preserve the selected filter without unrestricted request
input.

Service filtering narrows deployment records only: website health, runtime-log
metadata and shared server/provider evidence remain visible because the
current data model cannot safely attribute those signals to one repository
target. Unsuccessful deployments include rejected, failed and canceled builds;
active builds retain the existing recovery visibility rule. Incident severity
filtering applies only after explicit environment resource mapping. Existing
tenant policy checks, deliberate denial ordering, sensitive-content boundaries,
collection limits, no-write behavior and possible-correlation wording remain
unchanged. Cross-organization website/server relations are discarded from the
read model before their evidence is queried.

This is a single-responsibility/dependency-inversion improvement: the request
owns HTTP validation, the data object owns normalized filter state, the query
owns Eloquent filtering and tenant-safe mapping, and the controller/view do
only coordination/presentation. No generic repository, provider abstraction,
remote call, queue dispatch, migration or persisted-value change was added.

### Verification and limitations

The focused observability/deployment/health/log run passed **51 tests / 462
assertions**; `ObservabilityEnvironmentContextTest` passed **4 tests / 34
assertions**, including service/deployment/severity filtering, finite bounds,
authorization-before-validation and secret/body exclusion. The fresh strict
isolated PHP suite passed **1,412 tests / 12,234 assertions**, with the
unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure (the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, Vite build, route registration, `git diff --check` and the required-
PHP asset/browser suite (**9 passed**) passed.

This slice still does not provide explicit incident-to-build/configuration
links, saved investigation views, alert grouping controls or configurable
post-deployment observation. Provider-side monitoring, cloud acceptance and
the separate live acceptance drill remain outstanding.

**Phase 7B exit gate: complete for the bounded filter slice.** Feature commit
`375c643` (`feat: add observability evidence filters`) was fast-forwarded into
canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. The exact
next task is to add explicit incident links to the relevant deployment/build
or configuration evidence, preserving resource authorization and avoiding
causal claims.

## Phase 7C — explicit incident-to-deployment evidence links (completed slice)

### Problem and entry points

The environment context identified deployment incidents by their category and
resource ID, but the incident card sent every operator to the general incident
centre. An operator could see that a deployment was related without having a
direct path to the build's revision, timeline, bounded deployment log and
existing configuration identity. The existing environment context route and
the existing `builds.show` route are the only entry points changed.

### Responsibility boundary and preserved behavior

The bounded context query already maps deployment incidents only to the
tenant-scoped builds included in the selected evidence window and filters.
The view now presents that existing relationship as a separate
`Open deployment evidence` link. The surrounding incident-centre link is
retained for response history. A build link is rendered only for a concrete
deployment incident whose resource ID resolves to one of those already loaded
builds; other categories continue to use the incident centre. The build detail
route rechecks `BuildPolicy`, and its existing Livewire boundary loads the
configuration operation identity without exposing it in the context response.

This keeps single responsibility at the existing boundaries: the query owns
bounded tenant-safe evidence mapping, the read model carries only metadata, and
the Blade view presents navigation. No new repository abstraction, route,
database field, provider call, queue dispatch, persistence change or causal
inference was introduced. Incident titles and deployment links remain evidence
to investigate, not proof that the deployment caused the incident. Encrypted
incident summaries/resolutions, deployment logs and configuration payloads
remain behind their existing authorized surfaces.

### Verification and limitations

The focused observability/deployment/health/incident run passed **26 tests /
218 assertions**, including the exact deployment-link URL and sensitive-body
exclusion. The fresh strict isolated PHP suite passed **1,412 tests / 12,236
assertions**, with the unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure (the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check` and the required-PHP
asset/browser suite (**9 passed**) passed.

This slice does not add saved/shareable investigations, alert grouping,
polling, remote health observation or a direct configuration-review route.
Configuration-driven deployment evidence remains available through the linked
build's existing configuration identity. Provider-side monitoring, cloud
acceptance and the separate live acceptance drill remain outstanding.

**Phase 7C exit gate: complete for explicit deployment evidence navigation.**
Feature commit `3e79f4f` (`feat: link incidents to deployment evidence`) was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on
2026-09-13. The exact next task is to characterize saved/shareable
investigation views, including ownership, authorization rechecks, filter
normalization, retention and whether a persistence change is justified.

## Phase 7D — shareable investigation URL characterization (completed slice)

### Existing behavior and responsibility assessment

The repository already has saved filters for the notification inbox. They are
stored in the authenticated user's `preferences` JSON, are limited to ten
user-owned presets, and contain no organization, environment, resource-policy,
expiry or retention metadata. That boundary is appropriate for a private
notification preference but cannot safely represent a workspace investigation:
it would not establish whether a referenced environment or service remains
visible after a workspace switch, membership change or resource deletion.

The observability context instead already uses a GET route with a bound
environment and four finite, non-secret filters (`window`, `service`,
`deployment` and `severity`). `ObservabilityContextRequest` rechecks the
environment policy and current organization on every request, validates the
service against the selected website's organization-owned repositories, and
normalizes omitted values. The existing build, health, runtime-log and
incident links recheck their own resource policies. A copied context URL will
therefore fail closed after authorization changes rather than preserving a
stale access grant.

### Decision and future boundary

Do not reuse notification preferences and do not add a persistence table for
the first shareable-investigation slice. Add a canonical URL generated only
from the validated filter data so arbitrary query parameters cannot be
reflected into a share link. This is a controller/view navigation concern;
the query collaborator remains responsible for bounded evidence and no
secret, incident body or provider credential enters the URL.

If named shared investigations are later justified, characterize an
organization-owned model separately with an explicit schema version, owner or
workspace permission, environment/resource revalidation, filter allowlist,
expiry/retention and deletion behavior. That would require a policy and
action boundary; the existing user-preference action is not a substitute.

**Phase 7D characterization exit gate: complete.** Documentation commit
`c9b1e00` (`docs: characterize shareable investigations`) was fast-forwarded into
canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. The exact
next task is to add and test the canonical shareable context URL without
introducing persistence or changing authorization semantics.

## Phase 7D — canonical shareable investigation URL (completed slice)

### Problem and responsibility boundary

The bounded environment context was reproducible through validated query
parameters, but it did not expose an explicit link that an operator could copy
to share the same investigation with an authorized teammate. The controller
now builds a canonical context URL from the immutable, validated filter data;
the view presents that URL alongside the existing observability navigation.
Unknown query parameters are excluded from the generated link, and omitted
filters are represented by their normalized defaults.

The Form Request remains responsible for authorization, allowlists and
normalization. `ObservabilityContextFilters` owns the stable URL parameter
shape, the injected context query remains responsible for bounded tenant-safe
evidence, and every visit re-runs the existing environment/resource policy
checks. This applies single responsibility and dependency inversion without a
new model, persistence table, provider call, queue dispatch or authorization
grant. No secret, incident body, encrypted log content or arbitrary request
input enters the link.

### Verification and limitations

The focused observability/deployment/health/incident run passed **26 tests /
222 assertions**, including default normalization, selected filters, unknown
parameter exclusion and escaped-link rendering. The fresh strict isolated PHP
suite passed **1,412 tests / 12,241 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check` and the required-PHP
asset/browser suite (**9 passed**) passed.

This is a stateless shareable URL, not a named or persisted investigation. It
does not add alert grouping, polling, remote health observation, configuration
review navigation or provider/cloud acceptance. The separate live acceptance
drill remains outstanding.

**Phase 7D URL exit gate: complete.** Feature commit `c757413`
(`feat: add shareable observability links`) was fast-forwarded into canonical
`main` and pushed to GitHub `origin/main` on 2026-09-13. The exact next task is
to characterize existing alert grouping/deduplication and post-deployment
observation semantics before changing either.

## Phase 7E — alert grouping and post-deployment observation characterization (completed investigation)

### Existing alert grouping and delivery behavior

Operational incidents already provide a durable grouping boundary. A failure
uses the current workspace, resource category and resource ID to form a unique
`active_key`; a row lock and transaction either create the active incident or
increment its `occurrences`, update `last_seen_at` and append a `repeated`
event. Recovery locks the open or acknowledged row, records the resolution and
clears `active_key`, so a later outage begins a new incident. The environment
context displays the resulting occurrence count and last-seen time.

The source monitors have additional transition guards: website health requires
the configured consecutive-failure threshold and notifies only on the change
to unhealthy; provider health notifies only when its persisted connection state
changes; metric rules use consecutive breaches, `is_alerting` and a cooldown;
manual health jobs are unique per website, and metric collection jobs are
unique per server. Failed deployments remain distinct because each build has a
different resource ID.

Incident grouping does not currently deduplicate delivery. Every direct
`IncidentNotifier::fail()` call still creates a database failure notification
and queues each subscribed alert destination; repeated incident events are
therefore grouped in the operational-incident table but remain individually
visible in the inbox and external delivery stream. Webhook jobs retry with
bounded backoff and PagerDuty receives a category/resource deduplication key,
while generic webhook, Slack, Discord, Teams and email destinations have no
provider-neutral suppression or idempotency key. Resolved incidents are pruned
only after the bounded retention period, while open incidents are preserved.

### Existing post-deployment observation behavior

The repository deployment plan runs one immediate remote HTTP probe after
post-deployment commands and before release cleanup. It retries the curl probe
five times with a two-second delay and turns the deployment into a failure when
the probe cannot succeed. That check does not create a `WebsiteHealthCheck`
row, persist an observation window, or retain a build-to-health-check
relationship; the build only records the existing stage, activation and finish
timestamps.

Periodic website monitoring is a separate scheduler path. It checks active,
non-hibernated websites according to the configured 5/10/15/30/60-minute
interval, retains up to 100 observations per website, applies the configured
1/2/3/5/10 consecutive-failure threshold and reports website transitions to
the incident notifier. It is not a post-deployment observation window and
cannot establish that a later health result was caused by a particular build.

### Boundary and next implementation

Do not replace the existing incident key, transition guards, queue retry
semantics or deployment health probe in this characterization. The next
smallest safe alert slice is to carry an explicit, stable incident identity
and occurrence metadata with external alert deliveries, allowing downstream
systems to group repeated events without silently changing the existing inbox
or webhook notification frequency. A later suppression policy must be
separately designed with destination-level opt-in, queued-job compatibility,
recovery behavior and replay/idempotency guarantees.

Post-deployment observation requires a separate design because adding a window
would affect build persistence, queue scheduling, website-monitor state,
deployment/recovery semantics and the claim that a health result is related to
a revision. It should begin with a bounded, revision-aware observation record
and explicit cancellation/retry behavior rather than reusing the website's
periodic health history.

The focused alert, incident, website-health and deployment-observation
regression set passed **55 tests / 615 assertions**. No application behavior or
schema changed in this investigation. The exact next task is to implement and
verify the stable incident identity/occurrence metadata for external alert
deliveries while preserving current delivery frequency and retry behavior.

**Phase 7E characterization exit gate: complete.** This characterization is
ready for the next feature slice; no provider/cloud or live acceptance claim is
made.

## Phase 7E — stable alert identity and occurrence metadata (completed slice)

### Problem and responsibility boundary

Operational incidents were already grouped and counted locally, but external
destinations received only the event's category and resource fields. PagerDuty
reconstructed a grouping key itself; generic webhooks, Slack, Discord, Teams
and email consumers had no stable incident identity or occurrence count with
which to group repeated deliveries. The application continues to deliver
every failure event, but now carries the grouping context with each external
payload.

`OperationalIncidentAlert` is an immutable delivery boundary built only after
the existing incident transaction and row lock return the active incident.
`IncidentNotifier` retains the existing unique `active_key`, repeated-event
history, notification preferences and fan-out behavior, while passing the
context to `DeliverAlertWebhookJob`. The payload adds non-secret
`incident_id`, `incident_occurrences` and `dedup_key` fields. PagerDuty uses
the supplied key, and legacy jobs without it retain the previous fallback
calculation. This applies single responsibility and dependency inversion at
the integration boundary without adding a provider interface, schema change,
new queue semantics or automatic suppression.

The existing database inbox remains per-event, repeated incident events remain
visible, webhook retry/backoff remains unchanged, and recovery continues to
use the existing category/resource behavior. The first incident now explicitly
sets its existing database-default occurrence value before building the
in-memory delivery context, avoiding a transient zero in newly created
payloads without changing the stored value.

### Verification and limitations

The focused alert, incident and observability run passed **24 tests / 205
assertions**; the broader alert, incident, website-health and deployment
regression set passed **55 tests / 608 assertions**. The fresh strict isolated
PHP suite passed **1,413 tests / 12,248 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check` and the required-PHP
asset/browser suite (**9 passed**) passed.

This slice adds grouping metadata, not destination-side deduplication or
delivery suppression. It does not add a revision-aware post-deployment
observation window, alert grouping controls, polling or provider/cloud
acceptance. The separate live acceptance drill remains outstanding.

**Phase 7E alert metadata exit gate: complete.** Feature commit `aae111c`
(`feat: add alert incident metadata`) was fast-forwarded into canonical `main`
and pushed to GitHub `origin/main` on 2026-09-13. The exact next task is to
design and characterize a bounded revision-aware post-deployment observation
record and lifecycle without reusing periodic website health history.

## Phase 7F — revision-aware post-deployment observation characterization (completed investigation)

### Existing contracts and responsibility boundary

The deployment plan currently contains 15 ordered stages. After release
activation and application/resource preparation, `VerifyDeploymentHealthScript`
runs one immediate HTTP probe against the configured website path with the
existing five retries, two-second retry delay, timeout limits and sanitized
failure message. A disabled check is a non-networking progress step. A failed
probe causes the signed failure callback to mark the active build failed and
allows the existing automatic release-recovery service to evaluate the prior
release before the final callback/purge path; a successful final callback marks
the build succeeded. This is release admission evidence, not a persisted
health-history row.

`Build` already carries the relevant deployment identity and lifecycle facts:
repository/environment relationship when available, immutable source
`revision`, captured environment payload, activation time, setup stage,
terminal status, finish time and heartbeat/remote-process guards.
`RecordBuildStatusAction` locks the build, advances progress monotonically and
ignores terminal or stale callbacks. `RecordBuildFailureAction` accepts only
the current deploying/running lifecycle and preserves the existing preview,
rollback and stale-callback boundaries.

Periodic website monitoring has a different responsibility. The scheduled
command runs every minute, selects due active websites using the configured
5/10/15/30/60-minute interval, skips hibernated or unentitled workspaces and
uses a unique website job for queued checks. `WebsiteHealthMonitor` probes and
locks the website, validates its URL/path/previous-check snapshot, records
`WebsiteHealthCheck` rows as `manual` or `automatic`, retains only the newest
100 rows, updates aggregate website health and emits transition incidents.
Those rows have no `build_id`, revision, deployment attempt or observation
window; manual checks and unrelated periodic checks can occur long after a
release. The existing build timeline can therefore show the immediate health
stage, but cannot honestly label a later website check as evidence for one
revision.

The feature boundary is to keep these responsibilities separate. A
revision-aware release observation must not reuse `WebsiteHealthCheck` as a
foreign or inferred build result, must not reset the website's global outage
counter, and must not let an old deployment's callback or delayed probe change
the current release's state. The existing immediate probe, automatic rollback,
periodic monitoring, website history, notification frequency and queued-job
compatibility remain unchanged by this characterization.

### Smallest safe implementation contract

The next implementation should introduce a separate, bounded deployment
observation aggregate linked to the build and the target website, with a
revision/path snapshot and finite states such as pending, observing, healthy,
failed, expired and superseded. Its configuration must be explicit and
disabled for legacy deployments by default; the setting should be captured in
the build's immutable deployment boundary so changing a website or environment
later cannot rewrite what a release was asked to observe.

Creation should occur only after the successful build transition commits. A
unique observation job may perform the remote probe outside the database
transaction, then persist a bounded result and schedule the next check only
after revalidating the locked observation, build ID, revision, target identity,
configuration snapshot, current deployment ownership and observation deadline.
If a newer deployment supersedes the target, the older observation becomes
terminal without touching the website aggregate or deleting the newer
observation. Duplicate delivery, worker retry, cancellation, expiration and
lease recovery must be idempotent; cleanup of an abandoned job must never
reuse revoked or stale authority.

The remote HTTP execution should be extracted into a small injected probe
collaborator shared by periodic monitoring and the new observation operation.
That applies single responsibility and dependency inversion while leaving
their persistence policies distinct. The observation writer/action owns its
transaction, locks, state transitions and bounded error data; the job only
coordinates durable work; the build page can then distinguish immediate
verification from a completed, failed, expired or superseded observation.
No provider-specific interface, generic repository or causal inference is
justified for this slice. Secret values, full endpoint details where the
existing UI excludes them, remote output and arbitrary command execution must
not enter observation responses or logs.

### Verification and next task

The focused deployment-health, website-monitoring/history, observability
context and repository-deployment characterization run passed **44 tests / 497
assertions**. It covers immediate probe ordering and retry syntax, disabled
checks, rollback context, website interval selection, failure thresholds,
transition notifications, retention, stale website snapshots, build callback
monotonicity and the bounded observability read. No application behavior or
schema changed in this investigation. Provider/cloud acceptance and the
separate live acceptance drill remain outstanding.

**Phase 7F characterization exit gate: complete.** The shared remote
health-probe result is now extracted and injected without changing periodic
monitor behavior. The exact next task is to add the disabled-by-default
revision-aware observation aggregate and lifecycle with focused duplicate,
supersession, retry, expiry and failure tests.

## Phase 7F — shared website health probe extraction (completed slice)

### Problem and responsibility boundary

`WebsiteHealthMonitor` combined remote SSH command construction, output parsing,
transport-failure sanitization, website persistence, aggregate state transitions
and incident notification. That made it difficult to reuse the exact bounded
probe for a revision-aware deployment observation without coupling that
observation to periodic website history.

`WebsiteHealthProbe` now owns only remote execution and returns immutable
`WebsiteHealthProbeResult` data: success, sanitized <=500-character error,
optional HTTP status and bounded duration. `WebsiteHealthMonitor` retains
eligibility, website row locking, retained `WebsiteHealthCheck` history,
failure thresholds, incident transitions and automatic/manual semantics. This
applies single responsibility and dependency inversion with a concrete
collaborator, not a speculative interface; existing Runner/SSH behavior and
monitoring command remain unchanged.

The immediate deployment shell probe remains its own release/rollback contract
with five retries and the existing callback behavior. This slice does not
create observation records, alter schema, change website health history,
schedule new jobs or infer causation.

### Verification and limitations

The focused probe/website-monitoring/history/automatic-control/deployment-health
run passed **35 tests / 441 assertions**. The fresh strict isolated full PHP
suite passed **1,416 tests / 12,274 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check` and the required-PHP
asset/browser suite (**9 passed**) passed.

No provider/cloud or live acceptance claim is made. The next implementation
slice—recorded below—adds the disabled-by-default revision-aware observation
aggregate using this probe, with explicit build/revision/path identity,
post-commit scheduling and duplicate, supersession, retry, expiry and failure
coverage.

## Phase 7F — revision-aware post-deployment observation aggregate (completed slice)

### Problem and responsibility boundary

Successful deployments had no durable, revision-bound observation boundary.
Periodic `WebsiteHealthCheck` history is website-scoped and intentionally has
no build identity, while the deployment plan's health stage is one immediate
probe that can roll back a release. Reusing either would lose the target
revision or change existing monitoring semantics.

The environment now has an optional, bounded post-deployment observation
window. Enabling it uses the existing `monitoring` entitlement. `DeploymentRequest`
captures only the non-secret website/server/URL/path identity and duration in
the encrypted build payload. `CreateDeploymentObservationAction` owns the
transaction-time build status, target revalidation, duplicate callback
idempotency and supersession of older active observations for the same
website/repository. The finite status enum and model provide a stable boundary
for the queued execution slice. The creation action does not perform remote
work itself; queued execution is a separate boundary described below.

This applies single responsibility and dependency inversion: the deployment
request builds an immutable input snapshot, the action owns local state
transitions and locks, and the extracted `WebsiteHealthProbe` remains the
remote execution collaborator. The aggregate is disabled for legacy builds,
rejects changed or incomplete targets and stores no secrets, command output or
provider credentials.

### Verification and limitations

The focused feature/regression run passed **68 tests / 517 assertions**. The
fresh strict isolated full PHP suite passed **1,421 tests / 12,301 assertions**,
with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check` and the required-PHP
asset/browser suite (**9 passed**) passed.

No provider/cloud or live acceptance claim is made. The exact next task was to
add leased remote observation execution using the shared probe, with bounded
retry, expiry/failure outcomes, duplicate-dispatch protection and stale-claim
guards; that task is recorded in the following completed slice.

## Phase 7F — leased observation execution and bounded build read surface (completed slice)

### Problem and responsibility boundary

The aggregate could record that a successful deployment requested observation,
but it could not execute the bounded window or recover when a worker stopped
after claiming work. Calling the remote probe from the signed build callback
would mix remote I/O with callback/local transaction behavior; allowing a late
worker to write without an attempt guard could overwrite a newer deployment or
claim.

`RunDeploymentObservationAction` now owns the observation state machine. It
locks and claims one due row, verifies the successful build and exact captured
website/revision identity, and constructs a non-secret probe target. The
`WebsiteHealthProbe` runs outside database transactions. A two-minute lease
and claim token define the recovery boundary; the action records a result only
if the same claim, current successful deployment and target identity still
match. A changed target is finalized as `superseded` rather than left active.

`ObserveDeploymentJob` coordinates one durable queue delivery with three
bounded attempts and backoff. It carries only the observation ID and is unique
for the lease window. `ProcessDeploymentObservationsCommand` queues at most 100
due observations or expired leases each minute; the action rechecks every
identity and lease before remote work. The successful-build action dispatches
the first job only after its transaction commits, so a synchronous queue can
execute immediately without running inside the creation transaction.

Expected unsuccessful HTTP results become terminal `failed` outcomes with
bounded status/duration/error storage; successful checks remain `observing`
until the deadline, then become `healthy`. Expired windows become `expired`,
and unexpected worker exceptions clear the claim for bounded queue retry or
persist a terminal failure on the final attempt. A worker that disappears is
recoverable after lease expiry. The build detail surface exposes only status,
window, successful-check count, HTTP status and timestamps, and polls while
the observation is active. It does not render claim tokens or remote error
text.

This applies single responsibility and dependency inversion: the job and
scheduler coordinate durable work, the action owns local state transitions and
locks, and the injected shared probe owns bounded remote execution. The
existing periodic `WebsiteHealthCheck` history and the deployment plan's
immediate health probe remain separate; no website health state, incident
threshold or deployment response contract is changed.

### Verification and limitations

The final focused observation run passed **14 tests / 87 assertions**, covering
post-commit dispatch idempotency, successful and failed probes, worker retries,
future and due scheduling, expired leases/windows, target/revision changes,
stale claims, periodic-health isolation and the bounded build read surface.
The fresh strict isolated full PHP suite at `c6eff04` passed **1,429 tests /
12,359 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check`, Vite and the required-PHP
asset/browser suite (**9 passed**) passed.

**Phase 7F execution/read-surface exit gate: complete locally.** No
provider/cloud or live acceptance claim is made. The exact next task is to
include revision-bound observation outcomes in the bounded environment
evidence context, preserving service filters, tenant authorization and
exclusion of remote error text; that task is recorded in the following
completed slice.

## Phase 7F — revision-bound outcomes in environment evidence context (completed slice)

### Problem and responsibility boundary

The environment evidence context connected deployments, health checks, runtime
metadata and incidents, but it stopped at the build's immediate deployment
timeline. Operators could not see the outcome of an explicitly requested,
revision-aware post-deployment observation in the same bounded investigation
surface. Adding the observation model directly to the view would also risk
exposing encrypted remote error text, claim data or a stale target.

`ObservabilityEnvironmentContextQuery` now owns the read-side composition. It
retains builds with an active observation even when the build is older than the
selected time window, keeps the existing repository-service and organization
scope, and eager-loads only the observation columns needed for a safe summary.
`DeploymentObservationEvidence` is an immutable read model that excludes error
text, target URLs/paths, claim tokens and lease metadata. The query emits an
outcome only when the stored build ID and revision match the selected build and
the captured website is the selected environment website. The view shows only
status, successful-check count, duration, HTTP status and a bounded check time.

This applies single responsibility and dependency inversion: the query
collaborator composes tenant-scoped evidence, the immutable data object defines
the response boundary and the Blade view renders presentation only. The
observation action, job, lease and probe remain responsible for execution and
state transitions; periodic website health history remains separate. No new
remote call, write, job, route, API response or causal claim was introduced.

### Verification and limitations

The combined environment-context, deployment-observation and observability
regression run passed **39 tests / 295 assertions**. New coverage includes
active observations outside the selected time window, bounded collection size,
service filtering, exact revision/website identity matching and exclusion of
remote error text, claim tokens and target details. The fresh strict isolated
full PHP suite passed **1,433 tests / 12,383 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, changed-file
PHP lint, full Pint, route-cache creation, Vite and the required-PHP
asset/browser suite (**9 passed**) passed; `git diff --check` passed.

**Phase 7F environment-evidence exit gate: complete locally.** No
provider/cloud or live acceptance claim is made. The exact next task is to
characterize named saved investigation views, including organization/resource
authorization, filter normalization, expiry and retention, before deciding
whether persistence is justified.

## Phase 7G — named saved investigation views characterization (completed investigation)

### Existing contracts and responsibility boundary

The environment evidence route already has the correct read boundary for a
shareable investigation: `ObservabilityContextRequest` authorizes the selected
environment before validating a finite `window`, `service`, `deployment` and
`severity` filter, `ObservabilityContextFilters` normalizes those values, and
`ObservabilityEnvironmentContextQuery` rechecks the current organization and
resource relationships while loading bounded, secret-safe evidence. Its
canonical URL is stateless; every visit repeats authorization and no arbitrary
query input is preserved.

The notification inbox's saved presets are a different feature. They live in
the authenticated user's JSON `preferences`, store only notification filters,
replace case-insensitive names, retain at most ten entries and have no
workspace, environment or resource identity, expiry, retention, membership
visibility or current-resource revalidation. `SaveNotificationFilterAction`
and `RemoveNotificationFilterAction` intentionally persist that personal
preference shape, while `NotificationInboxQuery` scopes notifications to the
recipient rather than an organization. Reusing those records for observability
would allow a stale or unauthorized environment reference and would make
cross-member sharing depend on a personal preference blob.

### Decision and proposed boundary

Named investigation views are justified as a separate persisted capability for
team handoff, but they must not become public evidence snapshots. The smallest
safe design is an organization-owned record containing only an opaque public
identifier, environment ID, creator ID, display name, normalized context
filters, creation/update timestamps and an explicit expiration time. It stores
no deployment output, health errors, incident bodies, runtime logs, secrets or
provider data. Service IDs are validated against the environment's current
website when a view is created and are revalidated when it is opened.

The implementation should use a dedicated request/data boundary and cohesive
create/delete actions. A view may be opened only when its organization is the
actor's current organization, its environment still belongs to that
organization and the existing environment-view policy succeeds. Expired or
detached views should be concealed as unavailable rather than revealing
historical resource existence. Creating a view is a read-adjacent operation
authorized by the selected environment's view access; deleting one is limited
to its creator or a current workspace manager. Opening a named view should
authorize first and then resolve to the existing canonical context URL, so the
query, sensitive-content boundaries and filter semantics remain single-sourced.

Retention should be explicit and bounded: use a short default lifetime, a
finite maximum lifetime and a per-organization cap, with a scheduled prune
operation for expired rows. Creation must enforce the cap atomically, and
pruning must never delete a newly extended or currently opened record. No
anonymous access or bearer link that bypasses membership checks is justified.
These choices apply single responsibility, least privilege and dependency
inversion without changing the existing stateless context URL or notification
preferences.

### Verification and next task

The focused notification-inbox and observability regression run passed **34
tests / 261 assertions**. It confirms personal preset save/apply/remove
behavior, finite notification filter normalization, recipient scoping,
environment authorization before malformed filter handling, canonical context
URL normalization and bounded secret-safe evidence. No application behavior,
schema or runtime state changed in this characterization. Provider/cloud
acceptance and the separate live acceptance drill remain outstanding.

**Phase 7G characterization exit gate: complete.** The exact next task is to
implement the separate organization-owned named investigation view with
validated filters, policy rechecks, opaque identifiers, explicit expiry,
atomic retention bounds and a redirect to the existing canonical context read.

## Phase 7G — organization-owned named investigation views (completed slice)

### Problem and responsibility boundary

The stateless observability context URL was safe to copy, but it did not give
teams a bounded named handoff that could be listed and removed from the
environment view. The existing notification saved-filter preference was not a
safe substitute because it is personal JSON without environment identity,
workspace authorization, expiry or current-resource revalidation.

The new `ObservabilityInvestigationView` record stores only an opaque UUID,
organization and environment identity, creator identity, a bounded name,
normalized context filters and an expiration time. It never snapshots
deployment output, incident bodies, health errors, runtime logs, secrets or
provider data. The route key is the UUID rather than an enumerable database
ID.

`StoreObservabilityInvestigationViewRequest` extends the existing context
request so authorization and finite filter normalization remain at the HTTP
boundary. `ObservabilityInvestigationViewData` carries the immutable validated
input to `CreateObservabilityInvestigationViewAction`, which owns the
transaction-time organization lock, service revalidation, case-insensitive
duplicate check and active per-organization cap. `ObservabilityInvestigationViewPolicy`
owns open/delete decisions; the creator or a current workspace manager may
remove a view, while an open always requires current workspace and environment
access. `ObservabilityInvestigationViewQuery` owns the bounded active list and
`ObservabilityInvestigationViewResolver` revalidates persisted filters and
service identity before redirecting to the canonical context route. The
controller coordinates those boundaries and returns the existing response
style; it does not perform persistence or return a new evidence payload.

This applies single responsibility, least privilege and dependency inversion
without reusing personal preferences, introducing a generic repository or
creating a public bearer link. Expiry choices are finite (7, 30 or 90 days),
the default is 30 days and the active cap is 50 per organization. Expired rows
remain available to the bounded prune command rather than being deleted as a
side effect of a failed create; the scheduled command deletes only bounded
expired IDs. Opening an expired or detached/corrupt view returns the existing
404-style unavailable response, while an authorized creator or manager can
remove the expired row.

### Verification and limitations

`ObservabilityInvestigationViewTest` passed **10 tests / 48 assertions**,
covering member save/open and canonical redirect, bounded listing, creator
removal, denial before malformed input, foreign-workspace protection, expiry,
corrupt/stale filter rejection, duplicate names, atomic active-cap behavior,
default retention and bounded pruning. The adjacent observability and
notification regression set passed **44 tests / 309 assertions**. The fresh
strict isolated full PHP suite passed **1,443 tests / 12,433 assertions**, with
the unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure (the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check`, Vite and the required-PHP
asset/browser suite (**9 passed**) also passed.

The new web routes do not change existing API envelopes, notification
preferences, context filter semantics or evidence retention. No provider,
cloud, billing or live-acceptance claim is made. The exact next task is the
Phase 8 typed category-aware control-plane diagnostic report, after which any
server-host probe or interactive terminal transport must be designed as a
separate bounded protocol.

## Phase 8 — structured diagnostics characterization (completed investigation)

### Existing diagnostic boundaries

The application already has a bounded control-plane diagnostic path.
`OperationalDiagnostics::run()` returns fourteen safe checks when optional
systemd inspection is disabled: application key presence, application URL
shape, database connectivity, migration readiness, storage and bootstrap-cache
writability, production debug and queue configuration, email readiness,
external-monitoring configuration, pending and failed queue state, and
optional application-service and automation-timer state. It injects the
readiness, database, email and monitoring collaborators, reads queue counts and
timestamps rather than payloads, uses bounded `systemctl` processes and
replaces failures with safe details. It does not return configuration values,
queue payloads, exception text or endpoint bodies.

`SystemHealth` uses those checks for a short-lived dashboard cache and for a
fresh private manager-only HTML/JSON report. `DiagnoseApplicationCommand` is
the machine-readable and human-readable CLI adapter, and
`PublicPlatformStatus` derives coarse public components from the same named
checks. The existing route, JSON envelope, check names, detail strings, cache
key and authorization are compatibility contracts. They are not a
server-host diagnostic and must remain unchanged while the internal result
boundary is improved.

The server-side paths have different scopes:

| Area | Existing entry point | What it safely establishes | Why it is not the next interactive boundary |
| --- | --- | --- | --- |
| Import discovery | `ServerDiscovery::inspect()` and `InspectServerImportAction` | Pins the SSH host identity, runs one fixed read-only script, verifies root/Ubuntu/architecture and returns bounded capacity/service warnings before import. | It is an import-time admission check; it is not persisted, polled or intended to represent current process/storage/connectivity health. |
| Numeric telemetry | `CollectServerMetricsJob` and `ServerMetric` | Runs a fixed `/proc`, `df` and process-count sample for an active server, stores bounded numeric metrics, evaluates existing alerts and prunes old samples. | Metrics are measurements, not a readiness report; no service identity, diagnostic category or failure-stage contract should be inferred from a missing sample. |
| Runtime logs | `RefreshServerLogJob`, `CollectServerLogAction` and `ServerLogSnapshot` | Refreshes an allowlisted, bounded log category asynchronously and retains output for an authorized server view/download. | Log text is evidence, not structured health state; it must remain bounded and separately authorized. |
| Arbitrary commands | `QueueServerCommandAction`, `RunServerCommandJob` and `ServerCommand` | Locks an active server, enforces owner/deploy access and one active execution, encrypts the command/output, dispatches after commit and retains a bounded root-command result. | It is a deliberately powerful operation, not a diagnostic API. Reusing it for fixed probes would blur permissions, audit meaning, retry semantics and future terminal transport. |

There is currently no persisted or routed structured diagnostic report for an
already-imported server. `ServerShow` displays metrics, log snapshots and the
command dialog, but does not claim that any of those proves current runtime,
process, storage or connectivity readiness. The existing `ServerPolicy` gives
workspace members view access and deploy-capable actors update/command access;
any future server diagnostic read must select and document its own ability
without weakening tenant scoping or turning a viewer request into root command
execution.

### Responsibility and protocol decisions

The first Phase 8 implementation slice will improve the existing control-plane
diagnostic contract, not create an interactive shell. Introduce an immutable,
category-aware diagnostic check/report boundary and keep
`OperationalDiagnostics::run()` as a compatibility adapter that returns the
current arrays in the current order. Categories will cover runtime
configuration, storage, connectivity and process/automation checks. The CLI,
`SystemHealth`, `PublicPlatformStatus`, JSON fields, check names/details and
cache behavior will continue to consume the legacy projection until a separate
read-surface change is justified and tested.

This is a single-responsibility improvement: the diagnostic result becomes an
explicit value rather than an untyped array while the existing service remains
the composition point for the current checks. It applies dependency inversion
without adding interfaces for concrete application checks or repositories. A
later server-host diagnostic slice may reuse the typed result shape, but must
first define a fixed read-only command, host-key behavior, output allowlist,
timeout, failure and retention contract. It must not call
`QueueServerCommandAction` or expose secrets from environment files, command
output, logs or old input.

Interactive troubleshooting is explicitly deferred. The current `Runner`
executes as root and disables strict host-key checking when a server has no
stored key; there is no short-lived session grant, separate connect/execute
ability, membership revalidation, idle/concurrency limit, resize/disconnect
protocol, abandoned-process cleanup or terminal audit record. Designing those
after the fixed diagnostic boundary prevents a convenient command dialog from
becoming an unbounded remote execution transport.

### Verification and next task

The characterization inspected the existing source, routes, policies, jobs,
models, migrations and focused tests without changing application behavior,
schema, queue state or remote resources. Existing focused coverage includes
the safe human/JSON diagnostics report, systemd service/timer outcomes, bounded
queue checks, secret exclusion, import discovery, metrics collection,
command encryption/lifecycle and log snapshots. The typed diagnostic report
now categorizes those existing checks without changing their compatibility
projection. The known full-suite baseline failure remains
`ProvisioningHardeningTest::test_website_database_user_is_local_only`; it is
unrelated to this slice. Provider/cloud acceptance and the separate live
acceptance drill remain outstanding.

**Phase 8 characterization exit gate: complete.** The typed report slice is
recorded below. The exact next task is to characterize and design a fixed
server-host diagnostic contract; an interactive transport remains a separate,
later boundary.

## Phase 8 — typed control-plane diagnostic report (completed slice)

### Problem and responsibility boundary

`OperationalDiagnostics::run()` returned untyped arrays even though its checks
already represented distinct runtime, storage, connectivity and process
concerns. That made the result easy to consume but difficult to extend safely
for a categorized troubleshooting surface. The affected entry points are
`OperationalDiagnostics`, `SystemHealth`, `DiagnoseApplicationCommand` and
`PublicPlatformStatus`; all existing adapters still need the established
array shape.

Added the immutable enum-backed `OperationalDiagnosticCheck` and
`OperationalDiagnosticReport` data boundaries. `OperationalDiagnostics::report()`
now composes the fourteen existing checks with explicit categories, while
`run()` projects the report back to the exact legacy list of `name`, `passed`
and `detail` fields. The service remains the composition point for the
existing injected readiness, database, email and monitoring collaborators;
no generic repository, per-check interface or speculative remote strategy was
introduced.

This applies single responsibility and dependency inversion at a stable value
boundary: report consumers can reason about diagnostic concerns without
coupling to HTTP, CLI or cache adapters. It preserves check order, names,
details and failure sanitization. No server SSH call, arbitrary-command path,
route, schema, queue/job behavior, cache key or external provider call changed.

### Verification and limitations

The focused diagnostic regression set passed **20 tests / 159 assertions**,
including the typed report, category ordering, CLI, system health, public
status, cache and secret-safety coverage. The fresh strict isolated full PHP
suite passed **1,445 tests / 12,443 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation and non-dev platform checks,
PHP lint, full Pint, Pint test mode, route-cache creation and
`git diff --check` passed. This PHP-only slice made no frontend changes, so
the browser and asset suite was not rerun here; the prior Phase 7G browser
evidence remains unchanged.

**Phase 8 typed-report exit gate: complete.** Feature commit `2f7d719`
(`refactor: type operational diagnostics`) was fast-forwarded into canonical
`main` and pushed to GitHub `origin/main` on 2026-09-13. The exact next task is
to characterize and design a fixed, read-only server-host diagnostic contract
with explicit host-key, command allowlist, timeout, failure and retention
semantics before implementing it. Interactive troubleshooting remains
deferred.

## Phase 8 — fixed server-host diagnostics characterization (completed investigation)

### Existing boundaries and responsibility problem

An imported or provisioned server already has a pinned SSH host identity, and
the existing `Runner` can execute remote work as root. That does not establish
a safe diagnostic product boundary: `CollectServerMetricsJob` stores numeric
measurements, `RefreshServerLogJob` stores bounded log text, and
`QueueServerCommandAction` deliberately stores encrypted arbitrary commands and
their output. Reusing the command history for health checks would grant the
wrong ability, change audit meaning and make secret/output retention harder to
reason about. `ServerDiscovery` has a fixed script, but it is a one-time import
admission check and is not a current server-health record.

The affected user entry point is the existing authorized server detail view;
the reusable execution entry points are `Runner`, `ManagedSsh`, the server
policy and the existing server jobs. Existing metrics, logs, commands,
provisioning callbacks and provider probes remain separate contracts.

### Fixed diagnostic contract

The next implementation is a manual, asynchronous server diagnostic with a
single responsibility: collect a current, safe readiness report for an
already-managed active server. A policy ability will authorize the read-only
diagnostic independently of the stronger update/command ability, while tenant
scoping continues through `ServerPolicy` and the existing route binding. The
HTTP/Livewire boundary will queue an action after commit; it will not open SSH
or block on a remote host.

The action/job boundary will persist one latest snapshot per server with
`queued`, `running`, `ready` and `failed` states, a bounded lease and attempt
identity, start/finish timestamps and a safe failure stage. A fresh request
will not queue a duplicate active snapshot. An expired lease can be recovered
by a bounded retry, and a stale completion cannot overwrite a newer attempt.
The latest terminal snapshot remains until the next request or server deletion;
no unbounded diagnostic history or remote response body is retained.

The remote command is a versioned, application-owned script with no user or
database value interpolated into it. It may emit only bounded scalar facts
needed for runtime, storage, process and transport checks: root identity,
architecture/runtime availability, bounded filesystem capacity, numeric
process/load state and allowlisted service-state booleans. It must not read
environment files, process arguments, logs, queue payloads, command history,
provider credentials or arbitrary URLs. Parsing accepts a fixed key allowlist,
strict scalar formats, a maximum output size and a dedicated diagnostic
timeout. Stderr and transport failures become generic sanitized failure
details.

The diagnostic requires the server's stored `ssh_host_key` before any remote
call. If the identity is absent, the snapshot fails safely and does not use
`Runner`'s legacy no-host-key fallback that disables strict checking. The
probe never rescans or silently replaces the pinned identity. This preserves
the existing provisioning/import identity flow while making the new read
operation fail closed.

### Verification plan and next task

Characterization inspected the server model, policy, bindings, SSH runner and
temporary-file cleanup, import discovery, provisioning identity capture,
metrics/log/command jobs, views, scheduler and related tests. No application
behavior, schema, queue state or remote resource changed. The implementation
must add tests for exact script/parser output, missing host identity, timeout
and sanitized failure, policy/tenant denial before queueing, duplicate active
requests, lease recovery, retry bounds, stale-attempt protection and absence
of secrets or arbitrary command output. Existing metrics, logs, arbitrary
commands and provisioning behavior must remain green.

**Phase 8 fixed-host characterization exit gate: complete.** The exact next
task was to implement the persisted fixed server-host diagnostic snapshot,
policy/action/job boundary and safe read surface; that implementation is
recorded below. Interactive terminal transport remains a separate,
unimplemented protocol.

## Phase 8 — fixed server-host diagnostics (completed slice)

### Problem and responsibility boundary

The characterization established that existing metrics, logs, provisioning
discovery and encrypted arbitrary-command history could not safely serve a
current structured host-readiness product. Reusing command history would mix
read-only health checks with a stronger command ability and retain the wrong
data. The affected entry point is the authorized server detail view, with
`ServerPolicy`, `ServerShow`, `Runner` and `ManagedSsh` providing the existing
authorization, UI and transport boundaries.

Added `ServerDiagnosticSnapshot`, a unique latest-result record per server,
plus `QueueServerDiagnosticAction` and `RunServerDiagnosticJob`. The policy's
`diagnose` ability is intentionally no stronger than server visibility and is
separate from update/arbitrary-command execution. The Livewire component queues
the action without opening SSH; no Form Request was added because this action
has no user-supplied diagnostic command or filter. The action locks the server
and snapshot, prevents duplicate unexpired work, fails closed for inactive or
unprepared servers, and dispatches only after commit. The job claims a bounded
lease, probes outside the database transaction, retries transport failures,
and updates only the matching attempt token. Expired attempts can be replaced;
stale jobs cannot overwrite a newer result.

`ServerDiagnosticProbe` owns the fixed remote integration and
`ServerDiagnosticOutputParser` owns its strict, bounded scalar response
contract. The script does not interpolate user, database or environment-file
values and does not read secrets, logs, process arguments or arbitrary URLs.
It requires the stored pinned host identity before calling the existing SSH
runner. Only safe categorized checks are stored and rendered; credentials,
claim tokens, raw response bodies and exception text are not retained. The
existing typed operational diagnostic report now provides the storage
projection without changing its legacy CLI/JSON/HTTP shape.

This applies single responsibility and dependency inversion at the HTTP,
operation, queue and integration boundaries, while retaining existing provider
and SSH contracts. It does not create a generic command abstraction or change
arbitrary command execution, server metrics, logs, provisioning, routes,
provider calls or persisted values outside the new snapshot table.

### Verification and limitations

The focused fixed-diagnostic suite passed **16 tests / 90 assertions**. The
adjacent server, import, log, command and observability regression set passed
**45 tests / 373 assertions**. The fresh strict isolated full PHP suite passed
**1,461 tests / 12,534 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Migration fresh/rollback/reapply, required-PHP Composer
validation and platform checks, changed-file PHP lint, full Pint, Pint test
mode, route-cache creation, fixed-script shell validation and `git diff --check`
passed. The required-PHP asset build and browser asset/non-JavaScript suite
passed **9 tests**. The initial browser invocation using the system PHP 8.3
stopped at PHPUnit's version check and was not application evidence; the
mandated PHP 8.5 rerun passed.

No provider/cloud, paid-resource or separate live acceptance claim is made.
The fixed probe is local application evidence; it does not establish host
availability for an actual managed server until the separate live drill is
authorized and run. Interactive terminal transport remains deferred.

**Phase 8 fixed-host implementation exit gate: complete locally.** Feature
commit `4add5b9` (`feat: add fixed server diagnostics`) was fast-forwarded into
canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. The exact
next task was to characterize the interactive troubleshooting transport and
host execution model, then add a separate persisted session boundary. That
characterization and lifecycle implementation are recorded below; the exact
next task is now the bounded transport and abandoned-process cleanup contract.

## Phase 8 — interactive troubleshooting transport characterization (completed investigation)

### Existing boundary and responsibility problem

BuildPusher already exposes queued server commands, but that feature is a
different contract from an interactive troubleshooting session. `ServerCommand`
and `ServerCommandsController` authorize the existing command/history abilities;
`QueueServerCommandAction` locks an active server, enforces the current owner
and one-active-command rules, encrypts the command and dispatches
`RunServerCommandJob` after commit. The job claims a queued row once, calls
`Runner`, captures a bounded stdout/stderr tail and stores the terminal result.
The command model is therefore an auditable one-shot execution record, not a
live channel.

`Runner` and `ManagedSsh` create a new root SSH connection for each execution,
write temporary private-key and known-host files, and use the Spatie SSH
`bash -se` heredoc transport. The normal runner callback logs remote output;
`ManagedSsh` has no durable PTY, stdin, resize, heartbeat or disconnect
protocol. `executeAsync()` returns a local process handle and cannot itself be
serialized as a durable application session. The runner also deliberately
disables strict host checking when a server has no stored host key, which is
acceptable only for legacy command compatibility and is not acceptable for a
new terminal capability. The fixed diagnostics slice already proves the safer
fail-closed host-key boundary for read-only probes.

The current command UI polls recent history through Livewire and sends a
complete command before the queue worker starts. It has no separate connect
and execute abilities, no session lease or short-lived grant, no revocation
check during execution, no remote process cleanup contract and no policy for
persisting sensitive terminal output. A terminal must not reuse
`ServerCommandExecution`, `RunServerCommandJob`, encrypted command history or
the generic output-logging callback, because doing so would change the meaning
and security boundary of existing commands.

### Characterized terminal contract

Any future session needs a separate persisted lifecycle record and an injected
transport collaborator. The proposed record would contain an opaque session
identity, actor/server ownership, finite `connecting`/`connected`/`closing`/
`closed`/`expired`/`failed` state, a short authorization expiry, an idle
deadline, a lease/heartbeat and a bounded local process identity. It must store
no private key, password, bearer token, PTY transcript or raw terminal output
in the queue payload. The browser receives only an opaque capability and
bounded stream chunks; every connect, input, resize and close request
revalidates actor membership, current organization/resource access, session
expiry and server lifecycle.

Connect and execute must be separate decisions. The policy/gate design must
choose the least-privileged existing workspace abilities for opening a session
and for sending shell input, while preserving the current owner-only queued
command behavior until a deliberate product authorization change is reviewed.
Policies will remain side-effect free; session actions will own locks, leases,
revocation and local persistence; a transport adapter will own SSH/PTY
construction, pinned host-key checking, bounded timeouts, input/output limits
and sanitized transport failures.

The first implementation should use an explicitly selected server-side
transport supported by the current locked dependencies. It must define how a
worker survives web-request boundaries, how a local process is reclaimed after
worker death, how remote process groups are terminated after disconnect or
expiry, and what happens during a network partition. A polling-only sequence
of one-shot commands would be an improvement to command history, not an
interactive terminal, and should not be labeled as one. WebSocket/SSE or PTY
support must not be assumed from Livewire or Symfony Process without a tested
runtime and deployment model.

Required controls before implementation are: independent connect/execute
authorization; short-lived session grants; membership/credential revalidation;
active server and pinned host identity checks; one-session/concurrency and
idle limits; bounded input, output and resize frames; explicit terminal size;
disconnect, expiry and reconnect semantics; cleanup of abandoned local and
remote processes; audit metadata; and an explicit sensitive-output retention
policy. Reconnect must create a newly authorized session and cannot revive a
revoked grant. A session must never make provider credentials, environment
files, command history or control-plane secrets available to the browser.

### Characterization result and next task

The current locked stack contains Symfony Process PTY and input primitives,
but the application has no supported long-lived process registry, bidirectional
browser transport or remote cleanup protocol. The safe next slice is therefore
to implement and test the separate session authorization/data boundary and a
bounded lifecycle state machine only after the transport choice, worker
ownership and cleanup semantics are specified. Do not call that slice an
interactive terminal until it can carry input/output and prove revocation and
cleanup behavior. No application behavior, schema, queue state or remote
resource changed in this investigation.

**Phase 8 interactive-transport characterization exit gate: complete.** The
minimal persisted authorization/lifecycle boundary is recorded below. The
exact next task is to select and implement the server-side transport and
host-process cleanup contract; no browser terminal or remote PTY may be
exposed until reconnect, revocation, expiry, disconnect and abandoned-process
tests are green.

## Phase 8 — troubleshooting session authorization and lifecycle (completed slice)

### Problem and responsibility boundary

The transport characterization found no safe place to keep a live terminal
grant, revalidate membership or represent expiry independently of the existing
one-shot command history. Before selecting a PTY or browser transport, the
application needed a durable session boundary whose state could be tested
without opening SSH. This slice therefore adds no route, Livewire control,
remote process or change to the existing queued-command contract.

`ServerTroubleshootingSession` stores only the server/user ownership, opaque
UUID, one-way grant digest, finite lifecycle state and bounded absolute/idle
deadlines. The plaintext grant is returned only in the immutable
`ServerTroubleshootingSessionGrant` data object at the application boundary;
it is hidden from model serialization and is not placed in a queued job. The
enum preserves explicit `connecting`, `connected`, `closing`, `closed`,
`expired`, `revoked` and `failed` outcomes without changing any existing
persisted value.

`ServerPolicy::connect` permits the existing server-view audience to request a
future connection, while `ServerPolicy::execute` requires the stronger
workspace `operate` ability. `ServerTroubleshootingSessionPolicy` additionally
binds every session decision to its issuing actor and server. Policies remain
side-effect free. `OpenServerTroubleshootingSessionAction` owns the transaction,
server/session locks, active-server and pinned-host-key prerequisites, bounded
capacity and expired-session recovery. It creates no SSH connection or job.

`TouchServerTroubleshootingSessionAction` revalidates the digest, actor
membership and server state under a row lock. It extends only the idle deadline
and never past the absolute deadline; expiry and server-inactive conditions
become distinct terminal outcomes. Close and internal revoke actions are
idempotent. A bounded scheduled expiry action marks idle/absolute deadline
records without contacting a server. Server and user relationships are
cascade-safe, and the existing owner-only arbitrary command path remains
unchanged.

This applies single responsibility and dependency inversion by separating the
future transport's authorization/lifecycle state from HTTP, queued one-shot
commands and SSH integration. It uses a meaningful immutable data boundary and
finite enum outcomes without creating a generic session repository or a
speculative transport interface. Membership is checked again on heartbeat, so
revoked access cannot keep an otherwise live grant usable.

### Verification and limitations

The focused lifecycle suite passed **13 tests / 46 assertions**. The adjacent
server, import, log, command and observability regression set passed **78 tests
/ 609 assertions**. The fresh strict isolated full PHP suite passed **1,474
tests / 12,580 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Migration fresh/rollback/reapply, the bounded expiry command
and scheduler registration, required-PHP lint, scoped Pint and
`git diff --check` passed. No frontend changed, so the prior required-PHP
asset/browser evidence (**9 passed**) remains unchanged and was not rerun for
this PHP-only slice.

No SSH, PTY, websocket/SSE, browser terminal, remote cleanup or provider/cloud
acceptance is included. A session row is an authorization/lifecycle boundary,
not an interactive terminal. The feature commit `6e9e55f` and wording
correction `335ea42` were fast-forwarded into canonical `main` and pushed to
GitHub `origin/main` on 2026-09-13. The exact next task is to choose and test a
bounded server-side transport with local/remote process ownership, reconnect,
revocation, expiry, disconnect and abandoned-process cleanup semantics before
adding any UI or route.

## Phase 8 — bounded troubleshooting transport and process ownership (completed slice)

### Problem and responsibility boundary

The persisted troubleshooting session now has a safe authorization and
lifecycle record, but the locked application still had no connection boundary
that could carry bounded input/output or reclaim a live process. The existing
queued command path intentionally remains a one-shot operation, so it is not a
safe place to add stdin, PTY state or reconnect behavior.

This slice adds `ServerTroubleshootingTransport` and
`ServerTroubleshootingConnection` contracts. The container binds the transport
to a pinned `SshServerTroubleshootingTransport`; the future broker can inject a
different test or host adapter without moving SSH details into policies,
actions or controllers. The SSH adapter refuses inactive, incomplete or
unpinned servers, uses the existing managed server credentials, and constructs
an argv-safe `ssh -tt` command with password authentication disabled, strict
known-host checking, no SSH escape character and a fixed foreground `exec bash`
remote command. The existing one-shot `Runner` path, including its legacy compatibility
behavior, remains unchanged.

`ProcessServerTroubleshootingConnection` owns Symfony Process/InputStream
state. It starts an asynchronous process once, limits each input frame and
pending output frame, drains Symfony's temporary output buffers as callbacks
arrive, validates terminal dimensions and closes the process group and managed
temporary SSH files idempotently. A destructor provides best-effort local
reclamation if the owning broker loses the connection object. Resize is
currently serialized as a validated `stty` control frame because the locked
Symfony API has no portable remote PTY window-size operation; the future broker
must serialize it with other frames and surface a failed connection.

This applies single responsibility and dependency inversion at the actual
integration seam: process ownership and remote command construction are kept
out of application authorization and persistence, while the contract allows
the broker to be tested without a live server. It does not add a speculative
generic integration layer or alter queued command semantics.

### Verification and limitations

The transport suite passed **8 tests / 25 assertions**, covering container
binding, incremental local I/O, input/output limits, validated resize,
idempotent cleanup, release-once behavior, pinned command construction and
fail-closed host identity. The adjacent server command, diagnostics and
session regression set passed **58 tests / 376 assertions**. The fresh strict
isolated full PHP suite passed **1,482 tests / 12,605 assertions**, with the
unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure (the test expects three `localhost` occurrences and the current script
contains four). Changed-file lint, full Pint and `git diff --check` passed.
No frontend changed, so the prior required-PHP asset/browser evidence
(**9 passed**) remains unchanged and was not rerun for this PHP-only slice.

The local process group and temporary-file release behavior are covered, but a
real remote host, network partition, supervisor restart, remote process-group
cleanup and abandoned SSH channel are not. There is no durable frame store,
session lease revalidation in a broker loop, queue payload, route, Livewire
terminal or browser exposure in this slice. Therefore it is not an interactive
terminal implementation, and no provider/cloud or live-acceptance claim is
made. Feature commit `6013f19` was fast-forwarded into canonical `main` and
pushed to GitHub `origin/main` on 2026-09-13.

The exact next task is to add a durable, bounded encrypted input/output frame
relay and supervisor-owned broker command with session lease, attempt and
process-identity guards; keep routes and UI disabled until revocation, expiry,
disconnect and cleanup behavior is proven.

## Phase 8 — durable troubleshooting frames and broker ownership (completed slice)

### Problem and responsibility boundary

The transport seam could own a PTY process, but it had no durable exchange
buffer or supervisor identity. A browser-facing boundary could therefore lose
input/output across requests or allow an abandoned process to overlap a newer
broker. This slice adds those guarantees without exposing a route or changing
the existing one-shot server-command workflow.

`ServerTroubleshootingFrame` and its separate table retain input and output as
encrypted, sequenced rows. Input and output have independent bounded limits;
output is acknowledged through a sequence and old rows are removed by a
bounded scheduled prune. A broker marks an input frame sent before the remote
write, giving shell input at-most-once behavior across an uncertain broker
crash: a frame may be lost, but it is not replayed into a new shell. Output is
persisted only while the exact broker lease is valid, and overflow fails the
connection before inserting an unbounded batch.

The session now stores a hashed short broker lease, monotonic attempt, process
identity and input/output sequence counters. Claim, connect, renew, release
and frame operations lock the session and require the exact token, attempt and
process identity. An expired lease is terminalized rather than replaced, so a
possibly orphaned remote process cannot overlap a new one. User close,
revocation, idle/absolute expiry and scheduled maintenance clear ownership;
stale broker callbacks cannot clear a replacement. `ServerTroubleshootingBroker`
coordinates the injected transport, live lease renewal, frame relay and a
bounded polling window. The console command is suitable for a supervisor-owned
process, while actual supervisor/systemd installation remains deferred.

This applies single responsibility by keeping authorization/lifecycle actions,
encrypted frame persistence and transport orchestration separate. It applies
dependency inversion through the existing transport contract and does not add
a generic repository or speculative integration framework.

### Verification and limitations

The focused troubleshooting/session/transport suite passed **33 tests / 135
assertions**. The fresh strict isolated full PHP suite passed **1,494 tests /
12,668 assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP changed-file lint, full Pint and
`git diff --check` passed. The new migrations applied, rolled back as the last
two migrations and reapplied on disposable SQLite. A whole-database rollback
was not used as evidence because the unrelated pre-existing
`2026_09_05_160000_add_expiry_to_personal_access_tokens` migration fails on
SQLite when its index remains during a drop-column operation. The prior
required-PHP asset/browser evidence (**9 passed**) remains unchanged and was
not rerun for this PHP-only slice.

No real remote host, network partition, supervisor restart, abandoned remote
process cleanup, reconnect protocol, route, Livewire terminal or browser
journey was exercised. No provider/cloud/live-acceptance claim is made.
Feature commit `5b54f3b` was fast-forwarded into canonical `main` and pushed
to GitHub `origin/main` on 2026-09-13.

The exact next task is to characterize and implement the policy-authorized
bounded frame input/output polling/acknowledgment and resize boundary; keep
user exposure gated until remote cleanup and revocation behavior has been
tested.

## Phase 8 — troubleshooting session HTTP lifecycle boundary (completed slice)

### Problem and responsibility boundary

The durable session, transport, frame and broker boundaries had no consumer
that could safely issue a grant or report lifecycle state. Added a small
authenticated JSON boundary for session creation, status/heartbeat and
idempotent close. The controller coordinates the server/session policies,
bearer-header protocol and existing lifecycle actions; the immutable view data
object exposes only safe timestamps, finite status, sequence counters and the
execute capability. No controller owns persistence or remote process work.

Nested scoped binding keeps a session attached to the requested server.
Creation returns the opaque grant once over a private, no-store response;
status and close require the bearer grant and never return it. Status uses the
existing touch action as the polling heartbeat, so membership, server state,
absolute/idle expiry and the grant are revalidated under the action's lock.
Close preserves the existing idempotent terminal transition and clears broker
ownership. Missing/invalid grants are rejected after resource authorization,
foreign nested sessions are concealed as not found, and no SSH connection,
frame, job or credential is created by this boundary.

This applies single responsibility and dependency inversion without introducing
a request object for an empty body or a generic API repository. Bearer-token
parsing is deliberately kept at the HTTP protocol boundary; the actions remain
usable from jobs, commands and future Livewire operations.

### Verification and limitations

The focused HTTP lifecycle suite passed **8 tests / 51 assertions**. The
combined HTTP/session/transport/broker regression set passed **41 tests / 186
assertions**. The fresh strict isolated full PHP suite passed **1,502 tests /
12,720 assertions**, with the unchanged
ProvisioningHardeningTest::test_website_database_user_is_local_only failure
(the test expects three localhost occurrences and the current script contains
four). Required-PHP changed-file lint, Pint test mode, git diff --check and
isolated route-cache creation passed. The prior required-PHP asset/browser
evidence (9 passed) remains unchanged and was not rerun because this is a
PHP/route-only slice.

Feature commit ee62249 (feat: expose troubleshooting session lifecycle) was
fast-forwarded into canonical main and pushed to GitHub origin/main on
2026-09-14. No provider, cloud, remote-host, supervisor, reconnect or
live-acceptance claim is made. The lifecycle routes are now supplemented by
bounded input, output polling, output acknowledgment and resize operations;
no Livewire terminal is exposed. The exact next task is to characterize and
implement remote cleanup, membership revocation and safe reconnect semantics,
retaining the supervisor-installation and terminal-UI gates until those
behaviors are tested.

## Phase 8 — troubleshooting frame HTTP transport boundary (completed slice)

### Problem and responsibility boundary

The durable frame relay and broker already protected encrypted input/output,
sequence cursors, backpressure and exact lease/attempt/process ownership, but
there was no policy-authorized HTTP consumer for a browser or future Livewire
client. Added four small protocol operations to the existing session boundary:
input enqueue, output polling, output acknowledgment and terminal resize.

The controller coordinates nested scoped binding, the appropriate connect or
execute policy, bearer-grant validation, protocol parsing and the existing
actions, then returns the existing JSON-safe transport metadata. It does not
persist frames, perform SSH work or own workflow transitions. Input and resize
reuse the transaction-time action boundary, while output and acknowledgment
reuse the locked frame-store actions. The resize action is deliberately a
small cohesive adapter that delegates to the bounded input path; no generic
transport interface or repository was added.

Input is accepted only from the explicit byte-preserving input field and
returns 202 with sequence/size metadata, never the submitted payload.
Laravel's global TrimStrings middleware exempts this protocol field because
trailing newlines are shell data; the action still validates it again after
authorization and locking. Output is decrypted by the existing store,
returned in bounded sequence order with after/next_after cursors and never
exposes ciphertext, frame identifiers or an inaccurate has_more claim.
Acknowledgment removes only output through the requested sequence. Resize
validates an immutable terminal-size object and serializes a bounded stty
control frame through the same ordered input path, so the HTTP request never
performs a remote call.

All routes are nested and scoped to the server, require private no-store
responses and recheck the bearer grant on every operation. Execute permission
is required before input/resize validation; connect permission is required
before output/ack validation. This preserves safe authorization ordering,
secret-safe failure behavior and the broker's fail-closed stale-owner rules.

### Verification and limitations

The focused frame HTTP suite passed 15 tests / 94 assertions. The combined
HTTP/session/transport/broker regression set passed 35 tests / 183 assertions.
The broader server/provisioning set passed 185 tests, with the one unchanged
provisioning baseline failure, and 1,384 assertions. The fresh strict isolated
full suite passed 1,509 tests / 12,762 assertions, with the unchanged
ProvisioningHardeningTest::test_website_database_user_is_local_only failure
(the test expects three localhost occurrences and the current script contains
four). Required-PHP changed-file lint, Pint, git diff --check and route-cache
recreation passed.

No installed provider-host deployment, network partition, uncooperative remote
shell or reconnect protocol was exercised. Provider/cloud acceptance, the live
drill and browser terminal remain separate gates. Feature commit `8ad2e52`
(`fix: keep troubleshooting ssh sessions interactive`) was fast-forwarded into
canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. The exact
next task is authorized installed-host verification of the remaining remote
cleanup and reconnect semantics before exposing a terminal UI or moving to
Phase 9.

The latest characterization found that HTTP activity and broker lease renewal
must both recheck current membership. Broker renewal now loads the session
actor and uses the existing session policy: removed members are revoked and
their local broker lease is cleared, while an execute-role downgrade keeps a
connect-capable session alive but denies subsequent shell input. Local
process-group and temporary-credential cleanup is covered. The SSH command
now runs a foreground interactive Bash process in the allocated PTY; closing
the SSH channel and stopping the local process group provide the tested local
cleanup path without a nested `setsid` wrapper or shell trap. The remote
command still has no durable session identity or server-side cleanup helper,
so network-partition and uncooperative-remote cleanup are not proven.

## Phase 8 — remote cleanup and reconnect characterization (completed investigation)

### Existing ownership chain and responsibility problem

The current troubleshooting ownership chain is:

ServerTroubleshootingSession lease -> supervisor-oriented broker process ->
local Symfony Process group -> SSH PTY -> remote foreground bash.

During a normal broker return, ServerTroubleshootingBroker closes the
connection and ProcessServerTroubleshootingConnection asks Symfony Process to
stop the local process group. The connection then releases the managed SSH
temporary private-key and known-host files. These local operations are
idempotent and bounded by the configured process-stop timeout.

ManagedSsh::close() only removes local temporary credential files. The remote
command currently has no durable session identifier, remote PID record or
server-side cleanup helper. Symfony Process's process-group option governs the
local child process; it does not prove that a remote process survives or is
terminated after a worker crash, network partition or supervisor restart. A
real remote cleanup claim therefore requires an explicit remote lifecycle
protocol and deployment support, not a stronger controller or policy check.

### Revocation and reconnect findings

HTTP heartbeat, input, output and resize actions already recheck the presented
grant and current actor/server policy under a session lock. Internal
revocation clears the broker lease and is idempotent. A broker heartbeat,
however, currently validates only exact lease ownership, session deadlines and
server activity; it does not load the session actor and re-evaluate current
workspace membership. Membership removal or an execute-role downgrade can
therefore leave a broker-owned connection running until another lifecycle
transition, although subsequent HTTP input is denied.

The session state machine has no resume or reconnect route. This is the safe
default: a terminal session is not reusable, and opening a new session creates
a new opaque grant, new lease attempt and new frame ownership. Any future
reconnect operation must repeat server policy and current membership checks,
must never accept a terminal session's old grant, and must not clear or replace
an old broker lease while its remote process remains unaccounted for.

### Boundary decision and verification

The local implementation following this characterization puts actor
authorization revalidation in the broker lease-renewal action, where the
long-running operation already performs its heartbeat and owns the terminal
transition. The daemon-supervisor wiring added afterward starts only eligible
UUID-addressed brokers and gives their local process groups explicit stop and
restart semantics; the HTTP boundary remains responsible for bearer protocol
and response semantics. Reconnect remains a new-session operation until
remote cleanup has an explicit server-side contract.

This characterization itself changed no application behavior, schema, queue
state or remote resource. The follow-up implementation and tests are recorded
in the supervision slice below. The exact next task is authorized installed-
host verification of normal and abnormal remote cleanup plus safe
new-session-only reconnect; keep the terminal UI deferred.

## Phase 8 — troubleshooting broker supervision wiring (completed slice)

### Problem and responsibility boundary

The bounded broker command and encrypted frame transport had a clear
supervisor-oriented contract, but the daemon installer did not start one for
an active session or place its local SSH child in a restart/stop cgroup. That
left process cleanup and restart behavior as an uninstalled design rather than
an operational boundary.

`SuperviseServerTroubleshootingBrokersCommand` now scans only active,
non-expired sessions whose broker lease is absent or expired. The scan is
bounded, eager-loads the server and actor, rechecks the existing connect policy
and revokes ineligible sessions through the existing action before any broker
start. Eligible units contain only the opaque UUID instance identifier and are
started with Symfony Process argument arrays, so the bridge does not perform
shell interpolation or remote work. Lease claims, actor revalidation, frame
ordering and transport ownership remain in the existing actions and broker.

The daemon installer now writes a templated
`lessbuild-troubleshooting-broker@.service` with bounded broker polling,
restart-on-failure, a 15-second stop timeout and `KillMode=control-group`.
It also installs a bounded supervisor service and a persistent 15-second timer.
The SSH command runs a foreground interactive Bash process in the allocated
PTY. Normal broker shutdown therefore has explicit local cgroup and
remote-channel cleanup behavior: closing the SSH channel lets the remote PTY
terminate the foreground shell, while the local Symfony Process/systemd
control group owns local descendants. This is not a claim about cleanup after
a network partition or an uncooperative remote shell.

This applies single responsibility and dependency inversion at the actual
runtime seam: the console command owns bounded database-to-systemd discovery,
systemd owns local process lifecycle, and the broker/actions retain business
state, authorization and lease invariants. No generic supervisor abstraction,
new queue payload or browser terminal was introduced.

The broker actor revalidation and remote-channel termination changes that this
slice wires into the installed lifecycle were delivered immediately before it
as `5a452fc` (`fix: revoke troubleshooting brokers after access changes`) and
`e54add0` (`fix: terminate remote troubleshooting shells on disconnect`).
They are included in the local evidence below but remain separate cohesive
commits.

### Verification and limitations

The supervisor, broker, HTTP and transport suite passed **40 tests / 209
assertions**. The installer contract suite passed **2 tests / 56 assertions**;
the shell installer passed `bash -n`, changed PHP files passed the required
runtime lint, scoped Pint passed and `git diff --check` passed. The tests prove
bounded UUID unit construction, pre-start policy revocation, broker relay,
HTTP authorization and transport cleanup; they do not install systemd units or
connect to a remote host.

The later local application exercise and PTY-lifetime fix below supersede the
earlier nested-wrapper experiment. They add direct evidence for normal
transient-systemd disconnect and worker-loss restart cleanup, but they do not
prove the application on an installed provider host, network-partition
recovery or cleanup of an uncooperative remote shell.

Feature commit `e9b1ed1` (`feat: supervise troubleshooting brokers`) was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on
2026-09-14. The fresh strict isolated full suite after this slice passed
**1,514 tests / 12,797 assertions** with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). No installed-host, worker-crash, network-partition,
remote-orphan, provider/cloud or live-acceptance claim is made.

The exact next task is to obtain authorized installed-host evidence for normal
disconnect, broker restart, worker loss, network partition and remote process
cleanup, then characterize safe new-session reconnect. Keep the browser
terminal gated until those behaviors are proven.

## Phase 8 — local application transport verification (completed evidence)

### Problem and boundary

The existing unit and feature tests proved the bounded process connection and
the argv-safe pinned SSH command, but they did not exercise the actual
`SshServerTroubleshootingTransport` against an SSH daemon. A local adapter
check was justified as a verification boundary, not as a reason to add a
second transport abstraction or weaken the installed-host gate.

### Verification

In the isolated worktree on 2026-09-14, an ephemeral local `sshd` was started
with newly generated ED25519 client and host keys. A `Server` model was given
the encrypted client key, pinned known-host entry, active status and loopback
port, and the real `SshServerTroubleshootingTransport` was used to open five
sequential sessions. Each session remained running before and after input and
returned its bounded shell proof marker. No application production database,
provider host, acceptance-drill checkout or persistent credential was used.

The focused supervisor/broker/HTTP/transport/session/installer suite passed
**55 tests / 311 assertions** with strict warning/deprecation flags. Scoped
Pint, required-PHP execution, `bash -n` and `git diff --check` passed. The
fresh worktree's first run emitted only the expected missing-`.env` test-harness
warning; an isolated empty `.env` was then created and the strict rerun was
clean.

This evidence validates the local application adapter and its pinned-host
boundary. It does not prove a deployed systemd installation, broker restart,
worker-loss recovery, network-partition behavior, remote orphan cleanup or an
uncooperative remote shell. Reconnect remains intentionally new-session-only,
and the browser terminal remains gated.

**Local transport evidence gate: complete.** The exact next task is authorized
installed-host verification of normal disconnect, broker restart, worker loss,
network partition and remote process cleanup, followed by safe new-session
reconnect verification. Do not move to Phase 9 or expose terminal UI until
that external gate is satisfied.

## Phase 8 — troubleshooting PTY lifetime fix (completed slice)

### Concrete bug and responsibility boundary

The first real local SSH-adapter exercise showed that the nested `setsid`
shell wrapper could echo a short command and then exit before the broker's
next polling cycle. That was an interactive transport bug, not an
authorization or persistence problem: the wrapper introduced a detached child
whose lifetime was not reliably tied to the allocated SSH PTY.

`ManagedSsh` now runs the foreground interactive shell with `exec bash
--noprofile --norc -i`. The local Symfony Process and systemd control group
remain responsible for local process ownership, while SSH/PTTY channel closure
owns the remote foreground shell's normal disconnect behavior. The nested
`setsid` child, shell trap and remote process-group interpolation were removed;
the one-shot `Runner` command remains unchanged. This is a focused reliability
fix applying single responsibility at the transport boundary and preserving
the existing injected adapter contract.

### Verification and limitations

The real `SshServerTroubleshootingTransport` completed five sequential
encrypted-key sessions against an ephemeral local `sshd`; each session stayed
live after a short command and returned its bounded proof marker. The actual
broker then completed a 20-cycle local run with status `connected`, the marker
present and the connection released. Under transient local systemd, normal
stop left the session connected, observed the marker, and removed the remote
process. Killing the broker's exact main PID caused one systemd restart; the
session lease remained present as designed, and the control-group cleanup
removed the remote process before the test revoked the session.

The focused supervisor/broker/HTTP/transport/session/installer suite passed
**55 tests / 311 assertions** with strict warning/deprecation flags. Required-
PHP lint, scoped Pint, installer shell syntax and `git diff --check` passed.
The fresh strict isolated full PHP suite completed with **1,510 passing and 5
failing tests / 12,780 assertions**. Four unrelated failures are existing
validation-message response assertions in `OperationalIncidentTest` and
`OrganizationManagementTest`; the fifth is the known
`ProvisioningHardeningTest::test_website_database_user_is_local_only` count
mismatch (expected three `localhost` occurrences, found four). Neither the
PTY fix nor its transport test changes those paths.
These are disposable local-host checks only. Network partition, installed
provider-host deployment, uncooperative remote cleanup and reconnect remain
unproven; reconnect remains deliberately new-session-only and the browser
terminal remains gated.

Feature commit `8ad2e52` (`fix: keep troubleshooting ssh sessions
interactive`) was fast-forwarded into canonical `main` and pushed to GitHub
`origin/main` on 2026-09-14. The disposable installed-host-equivalent and
network-partition evidence is recorded in the next section. The exact next
task is the Phase 9 resource-usage and cost-visibility inventory; the browser
terminal remains deliberately gated and provider/cloud acceptance remains
separate.

## Phase 8 — disposable installed-host-equivalent verification (completed evidence)

### Problem and responsibility boundary

The local adapter and transient systemd checks needed one repeatable host-like
environment before the troubleshooting lifecycle could advance. A full VM
could not fit on the available 24 GB workspace disk, so an explicitly named,
disposable Debian 12 LXD system container was used instead. It has its own
root filesystem, systemd instance, SSH daemon, network namespace and firewall
tools; the BuildPusher application remained in the isolated worktree and its
SQLite runtime stayed outside the container. This verifies host/process and
network lifecycle semantics without introducing cloud credentials or provider
resources.

The container was configured with a dedicated ephemeral ED25519 key and a
verified pinned ED25519 host entry. The real
`SshServerTroubleshootingTransport` connected to the container through the
application's encrypted `Server` fields. The application broker remained the
owner of lease, frame and session state; systemd owned the local broker
process group; SSH/PTTY closure owned the remote foreground shell. No
controller, route, persisted contract or provider adapter was changed.

### Verification and limitations

The actual application broker delivered marker input/output through encrypted
frames and completed a 30-cycle run with a connected session and released
lease. A broker running under a restart-enabled systemd unit connected and
accepted input; a normal systemd stop left no remote interactive shell while
the lease correctly remained until explicit revocation. Killing the exact
broker PID caused one systemd restart; the replacement returned `not_claimed`
while the original lease remained, and the container had no surviving remote
shell. A second long-window run also verified remote-shell cleanup after the
disposable shell process was killed.

For the partition case, the container's LXD `eth0` was detached while a live
broker was running, the broker was stopped under systemd, and the interface
was reattached. The session's queued marker was present, no interactive shell
survived inspection through the container boundary, and the old lease remained
until revocation. A newly opened session received a different opaque identity,
connected after reattachment, delivered a new marker through the real broker
and released its lease. Existing HTTP regression coverage separately proves
that revoked grants cannot be reused and reconnect requires a new session.

The focused supervisor/broker/HTTP/transport/session/installer suite remains
**55 tests / 311 assertions** with strict warning/deprecation flags. The
current fresh strict isolated PHP baseline remains **1,510 passing and 5
failing tests / 12,780 assertions**: four existing incident/organization
validation-message response assertions and the known provisioning
`localhost`-count mismatch. These local checks do not establish a full VM,
cloud/provider acceptance, an uncooperative remote shell protocol, production
deployment, the separate live drill or browser-terminal usability. No
production credentials, cloud resources or acceptance-drill files were used.

**Local installed-host-equivalent lifecycle gate: complete.** The exact next
task is Phase 9 inventory and characterization for resource usage and cost
visibility. Keep terminal UI exposure and provider/cloud acceptance separate.

## Phase 9 — resource usage and cost visibility characterization (completed investigation)

### Concrete problem and affected entry points

The existing `CostController` reports a workspace-scoped sum of stored
`Size.price_monthly` values and a low-use signal derived from the latest twelve
server metric samples. That is useful planning information, but the current
projection does not identify the estimate's observation time, distinguish
measured telemetry from provider billing, or explain when a price is unknown.
The relevant entry points are `CostController`, the cost view and budget
request/action, `Size`, `GenerateSizesAndRegionsAction`, `Server`,
`Organization`, `Environment`, `PreviewDeployment`, `PlanLimits` and the
preview-expiry command.

### Existing boundaries and findings

- `Size` is the persisted DigitalOcean catalog used by the current cost view.
  `GenerateSizesAndRegionsAction` refreshes its capacity and price fields, but
  there is no first-class timestamp saying when that catalog observation was
  made. `updated_at` is not a safe substitute because fixtures, seeders or
  future administrative edits can update the row without observing a provider
  price.
- The current monthly number is a **catalog estimate**, not measured usage and
  not provider billing. Server CPU samples are measured telemetry used only for
  an attention signal. There is no provider invoice contract or billing import,
  so the application must not infer taxes, bandwidth, storage, discounts or a
  spending cap.
- Server-level attribution is known because servers belong to the workspace.
  Environment-to-project allocation is not always known: multiple websites or
  environments may share a server, while `Environment.server_id` can be null.
  The product must not invent fractional allocations. A later allocation view
  should expose only direct ownership and mark shared/unknown cases explicitly.
- Preview lifetime and concurrent quota are already persisted/configured:
  `Project.preview_ttl_hours`, `PreviewDeployment.last_activity_at`, status and
  `closed_at`, plus `PlanLimits::usageForOrganization(...,
  'preview_deployments')`. The expiry command is the authority for lifecycle
  cleanup. A cost/readiness projection may summarize this data, but must not
  mutate previews or imply that quota is a monetary limit.
- `ServerProvider` exposes lifecycle and catalog capabilities, not provider
  invoices. Adding billing methods to that shared contract solely for the cost
  page would force unrelated adapters to implement unsupported behavior and
  violate interface segregation. Provider billing remains a separate,
  justified contract when an actual provider integration is available.

### Chosen first slice

The smallest justified implementation is an explicit, read-only cost
projection: record the local observation time when the existing catalog refresh
stores a price, extract the server estimate/telemetry query from the controller
into an injected query collaborator, and label the view as catalog-estimate,
measured-telemetry and provider-billing-unavailable. Unknown prices retain the
existing safe behavior and remain excluded from the total. This applies single
responsibility and dependency inversion without introducing a repository,
generic action or provider-specific billing abstraction. Preview lifetime/quota
summary and review-only cleanup recommendations remain the next Phase 9 slices
after this source-semantics boundary is tested.

No application behavior or schema has changed in this characterization. The
next implementation must preserve current routes, budget validation and
authorization, ordering, metric sample bounds, unknown-price count, estimate
sum and provider-invoice disclaimer.

## Phase 9 — explicit cost source semantics (completed slice)

### Responsibility boundary

The controller was coordinating a reusable, organization-scoped server query,
catalog lookup, CPU aggregation and cost projection. `InfrastructureCostQuery`
now owns that read operation and returns immutable
`InfrastructureCostReport`/`InfrastructureCostRow` objects. The controller
continues to perform only workspace selection, entitlement projection and
response rendering. This is a single-responsibility and dependency-inversion
improvement without a generic repository or provider billing abstraction.

### Preserved and intentional behavior

`Size.catalog_synced_at` records the local time at which
`GenerateSizesAndRegionsAction` observed provider size/price data. It is
explicitly an observation timestamp, not a provider invoice timestamp or price
effective date. Existing price values remain unchanged; old or manually seeded
rows without this fact show an unavailable observation timestamp rather than
claiming freshness. Unmapped sizes remain unknown and are excluded from the
workspace estimate. The existing latest-twelve-metric bound, average-CPU
attention signal, server ordering, budget request/authorization and routes
remain unchanged.

The cost page now labels the monthly number as a provider-catalog estimate,
labels CPU as measured BuildPusher telemetry and states that provider billing
is not connected. The page retains the existing invoice disclaimer and
review-only optimization signals. No environment allocation, provider invoice
import, automatic cleanup or spending-cap claim was added.

### Verification

The focused cost/catalog regression set passed **10 tests / 39 assertions**
with strict warning/deprecation flags. It covers the scoped cost page,
timestamp display, unknown-size handling, budget denial and catalog refresh
metadata; the adjacent provider adapter set remained green. Changed PHP lint,
Pint and `git diff --check` passed. The feature commit was fast-forwarded into
canonical `main` and pushed to GitHub `origin/main`.

**Completed slice:** explicit catalog-source semantics and extracted
organization-scoped cost read model. The exact next task is preview
lifetime/quota visibility and safe server/environment attribution, keeping
cleanup recommendations read-only and limited to explicitly owned temporary
resources.

## Phase 9 — preview lifetime and quota visibility (completed slice)

### Responsibility boundary

The cost page needed preview capacity and expiry context, but the lifecycle
service must remain the owner of closing previews, dispatching cleanup and
enforcing concurrent reservations. `PreviewUsageQuery` now composes the
existing `PlanLimits` quota result with a bounded organization-scoped read
of active previews and immutable `PreviewLifetime` rows. `CostController`
coordinates the two read models; no lifecycle write or provider call was
introduced.

### Preserved and intentional behavior

Displayed usage uses the exact active-preview predicate already enforced by
`PlanLimits`, including its existing compatibility behavior for status and
`closed_at`. Preview expiry is projected from the recorded
`last_activity_at` plus the configured `Project.preview_ttl_hours`. The
expiry command remains the authority for cleanup; an expired display is a
review signal and does not close a preview or enqueue a job. The result is
bounded to 50 rows, while the quota count remains exact and reports omitted
active rows.

The UI explains that preview quota is not a monetary limit, shows the
configured lifetime and calculated expiration, and distinguishes pending
cleanup from an active future expiry. It does not estimate preview dollars
or attribute a shared server cost to a project/environment. Existing cost
estimates, budget behavior, routes, authorization, preview lifecycle,
concurrency and cleanup semantics remain unchanged.

### Verification

The focused cost and preview regression set passed **28 tests / 272
assertions** with strict warning/deprecation flags, including existing
preview lifecycle, entitlement and concurrent-quota coverage. Changed PHP
lint, full Pint and `git diff --check` passed. The feature commit was
fast-forwarded into canonical `main` and pushed to GitHub `origin/main`.

**Completed slice:** read-only preview quota/lifetime visibility. The exact
next task is direct-versus-shared server/environment attribution and
review-only cleanup signals for explicitly owned temporary resources.

## Phase 9 — attribution and review-only cleanup recommendations (completed slice)

### Responsibility boundary

A server-level catalog estimate cannot safely be divided among projects when
environments share infrastructure, and an apparently idle server is not proof
that deletion or hibernation is safe. `InfrastructureCostQuery` now loads
organization-owned environment/project links in the same bounded read and
classifies them as direct, shared or unallocated. The cost page uses existing
server/project review destinations; it does not add a deletion command or
automatic cleanup path.

### Preserved and intentional behavior

Direct means the server has recorded environments for one workspace project;
shared means more than one project is recorded; unallocated means no safe
organization-owned project link was found. These labels describe attribution
evidence only. Dollar values remain at server level and no fractional project
cost is displayed. Cross-workspace environment links are excluded from the
projection.

Existing no-website and sustained-low-CPU signals remain review candidates,
not deletion authorization. An expired preview is also a review-only signal;
the link re-enters the policy-protected project page, where lifecycle and
cleanup authorization are rechecked. The cost GET path does not update
previews, hibernate servers, delete resources or dispatch jobs.

### Verification

The focused cost regression set passed **8 tests / 40 assertions** with
strict warning/deprecation flags. It covers direct, shared and unallocated
relationships plus the review-only expired-preview path and no-side-effect
assertions. Full Pint and `git diff --check` passed. Attribution was pushed
in `907811d`; review-only cleanup signaling was pushed in `e6ff82c`. Both
commits were fast-forwarded into canonical `main` and pushed to GitHub
`origin/main` on 2026-09-14.

**Phase 9 local scope: complete.** The cost surface now distinguishes catalog
estimates, measured telemetry and unavailable provider billing; records price
observation times; shows preview quota/lifetime context; exposes safe
server/project attribution; and provides review-only cleanup signals. Provider
invoice imports, guaranteed spending caps, automatic cleanup, cloud/provider
acceptance and the separate live drill remain outstanding. The next task is
the final cross-feature verification and requirement audit.

## Slice ledger

| Slice | Problem and boundary | Tests/evidence | Commit | Push status | Exact next task |
| --- | --- | --- | --- | --- | --- |
| Phase 8 disposable installed-host-equivalent verification | The real adapter and transient process checks needed an isolated installed host with systemd, SSH and a controllable network boundary. A disposable Debian 12 LXD system container was configured with a dedicated key and pinned host identity; the actual application broker then exercised encrypted frames, normal systemd stop, exact broker-PID loss/restart, network-interface partition and new-session-only reconnect. No application code or public contract changed. | Real application broker marker delivery succeeded; normal stop, worker-loss restart and interface-detach cleanup left no remote interactive shell; the stale lease remained until revocation; a newly authorized replacement session connected and delivered a new marker. Focused supervisor/broker/HTTP/transport/session/installer suite: **55 tests / 311 assertions**. Full strict baseline remains **1,510 passing / 5 failing / 12,780 assertions** with the documented unrelated failures. | `28b35ae` — `docs: record installed host troubleshooting evidence` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Start Phase 9 resource-usage and cost-visibility inventory; keep browser-terminal exposure and cloud/provider acceptance separate. |
| Phase 9 resource usage and cost visibility characterization | The existing cost page combines server queries, catalog estimate lookup, measured CPU signals and projection semantics in `CostController`; it has no first-class price observation timestamp, provider billing source, safe environment allocation rule or preview quota/lifetime summary. Catalog prices come from `GenerateSizesAndRegionsAction`/`Size`; provider billing is not part of `ServerProvider`; preview lifetime/quota already live in `Project`, `PreviewDeployment` and `PlanLimits`. | Read-only source and schema characterization completed. No application behavior, schema, provider contract or runtime state changed. | `befe569` — `docs: characterize cost visibility boundaries` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Implement preview lifetime/quota visibility and safe known-attribution reporting without inventing shared-resource allocations or provider billing. |
| Phase 9 explicit cost source semantics | The controller's reusable organization-scoped server estimate and measured CPU projection was extracted into `InfrastructureCostQuery` with immutable report/row data objects. A nullable `sizes.catalog_synced_at` records when the existing provider catalog refresh observed price data; the UI now distinguishes catalog estimates, measured telemetry and unavailable provider billing. Existing prices, unknown handling, ordering, metric bounds, budget behavior and routes remain unchanged. | Focused cost/catalog/provider regression set: **10 tests / 39 assertions** with strict warning/deprecation flags. Changed PHP lint, Pint and `git diff --check` passed. | `fab31ef` — `feat: clarify infrastructure cost sources` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Add direct-versus-shared server/environment attribution and review-only cleanup signals for explicitly owned temporary resources. |
| Phase 9 preview lifetime and quota visibility | The cost surface had no preview capacity or expiry context. `PreviewUsageQuery` reuses the exact `PlanLimits` active-preview count, loads a bounded organization-scoped preview list and returns immutable `PreviewLifetime` projections; the controller and view remain read-only and the existing lifecycle service/expiry command retain ownership of cleanup. | Cost and preview regression set: **28 tests / 272 assertions** with strict warning/deprecation flags, including preview entitlement and concurrency coverage. Changed PHP lint, full Pint and `git diff --check` passed. | `b4fa99f` — `feat: show preview quota and lifetime` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Add direct-versus-shared server/environment attribution and review-only cleanup signals for explicitly owned temporary resources. |
| Phase 9 attribution and review-only cleanup recommendations | Server estimates remain at server level; `InfrastructureCostQuery` now loads organization-owned environment/project links and classifies each row as direct, shared or unallocated without inventing fractional costs. Existing server and policy-protected project pages are the review destinations; the cost page adds no deletion or automatic cleanup path. | Cost regression set: **8 tests / 40 assertions** with strict warning/deprecation flags, including direct/shared/unallocated relationships and no-side-effect expired-preview review. Full Pint and `git diff --check` passed. | `907811d` — `feat: explain server cost attribution` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Add no further Phase 9 mutation; run the final cross-feature verification and requirement audit. |
| Phase 9 review-only cleanup signaling | Expired previews and low-use servers needed actionable but safe guidance. Expired previews now link to the existing policy-protected project review page; the GET projection does not change status, close resources or enqueue cleanup. | Cost regression set: **8 tests / 40 assertions** with strict warning/deprecation flags; the expired-preview test asserts unchanged status/closure and an empty queue. | `e6ff82c` — `feat: add review-only cleanup signals` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Run the final cross-feature verification and requirement audit; keep provider billing/import and cloud/live acceptance separate. |
| Phase 8 troubleshooting frame HTTP transport | The durable encrypted frame relay and broker had no policy-authorized HTTP consumer. Added nested scoped input, output polling, output acknowledgment and resize routes. The controller owns only HTTP parsing, policy/grant checks and response projection; existing actions retain authorization revalidation, locks, encryption, bounds and broker ordering. Shell input remains byte-preserving, payloads are never echoed, output is cursor-based/decrypted without ciphertext or model identifiers, and resize uses a validated control frame through the same bounded input path. | Focused frame HTTP suite: 15 tests / 94 assertions. Combined HTTP/session/transport/broker suite: 35 tests / 183 assertions. Broader server/provisioning set: 185 passed / 1 unchanged baseline failure / 1,384 assertions. Fresh strict isolated full suite: 1,509 passed / 12,762 assertions / 1 unchanged baseline failure. Required-PHP lint, Pint, route-cache recreation and git diff --check passed. | 188f3b9 — feat: expose troubleshooting frame transport | Feature commit fast-forwarded into canonical main and pushed to GitHub origin/main on 2026-09-14. | Characterize and implement remote cleanup, membership revocation and safe reconnect semantics; keep supervisor installation and the browser terminal gated. |
| Phase 8 troubleshooting broker supervision wiring | The bounded broker had no daemon lifecycle owner. Added a bounded UUID-only supervisor scan that policy-revokes ineligible sessions and starts per-session systemd units, plus installer units with restart and control-group cleanup semantics. Existing broker/actions retain lease, actor, frame and transport invariants; the foreground SSH/PTTY process is owned by the local Symfony/systemd lifecycle. | Supervisor/broker/HTTP/transport suite: **40 tests / 209 assertions**. Installer suite: **2 tests / 56 assertions**. `bash -n`, required-PHP lint, Pint and `git diff --check` passed. No systemd installation or provider host was used in this slice. | `e9b1ed1` — `feat: supervise troubleshooting brokers` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Obtain authorized installed-host evidence for disconnect, worker loss, network partition, remote cleanup and safe new-session reconnect; keep browser-terminal exposure gated. |
| Phase 8 local application transport verification | The pinned SSH adapter needed one deterministic end-to-end local exercise beyond process/unit tests. Used an ephemeral `sshd`, generated keys and an active `Server` model to run the real `SshServerTroubleshootingTransport` through five sequential sessions; no code or production state changed. | Five real local adapter connections accepted bounded input and returned a proof marker. Focused supervisor/broker/HTTP/transport/session/installer suite: **55 tests / 311 assertions**. Scoped Pint, required-PHP execution, `bash -n` and `git diff --check` passed. | Documentation evidence checkpoint | Recorded in this progress update and pushed with the documentation commit. | Obtain authorized installed-host evidence for normal disconnect, broker restart, worker loss, network partition and remote cleanup, then verify safe new-session-only reconnect; keep browser-terminal exposure and Phase 9 gated. |
| Phase 8 troubleshooting PTY lifetime fix | The real local adapter exposed a transport-lifetime bug: the nested `setsid` wrapper could exit after a short command. `ManagedSsh` now runs a foreground `exec bash --noprofile --norc -i`, leaving local Symfony/systemd process ownership and SSH/PTTY channel cleanup at their respective boundaries; no one-shot command, route, frame contract or persisted value changed. | Five sequential real adapter sessions stayed live and returned markers; a 20-cycle broker run released cleanly; transient systemd normal stop removed the remote process; exact broker-PID loss caused one restart and control-group remote cleanup. Focused supervisor/broker/HTTP/transport/session/installer suite: **55 tests / 311 assertions**. Required-PHP lint, scoped Pint, `bash -n` and `git diff --check` passed. | `8ad2e52` — `fix: keep troubleshooting ssh sessions interactive` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-14. | Obtain authorized installed-host evidence for normal disconnect, broker restart, worker loss, network partition and remote cleanup, then verify safe new-session-only reconnect; keep browser-terminal exposure and Phase 9 gated. |
| Phase 8 remote cleanup and reconnect characterization | The normal broker path stops the local Symfony Process group and releases temporary SSH files; the foreground SSH/PTTY process now has a tested local channel-close path, but no server-side remote identity/helper exists. Worker death, network partition and remote orphan cleanup remain unproven. HTTP and broker activity recheck membership; reconnect remains intentionally new-session-only. | Read-only source/dependency characterization plus local process/command tests; no installed provider host, network partition, uncooperative remote shell or reconnect protocol was used. | Documentation checkpoint preceding `e9b1ed1` | Fast-forwarded into canonical `main` and pushed with the supervision slice. | Obtain authorized installed-host proof of normal and abnormal cleanup, then verify that reconnect always creates a newly authorized session. |
| Phase 8 troubleshooting session HTTP lifecycle | The durable session, transport, frame and broker boundaries had no safe HTTP consumer. Added authenticated JSON create, status/heartbeat and idempotent close routes with policy checks, bearer-grant validation, nested scoped binding, private no-store responses and an immutable secret-safe metadata projection. Existing lifecycle actions retain locks, ownership revalidation, expiry, broker cleanup and remote-free behavior; no frame, job, SSH or credential side effect is introduced. | HTTP lifecycle suite: **8 tests / 51 assertions**. Combined HTTP/session/transport/broker suite: **41 tests / 186 assertions**. Fresh strict isolated full PHP suite: **1,502 passed / 12,720 assertions / 1 unchanged baseline failure**. Required-PHP lint, Pint test mode, route-cache creation and git diff check passed. | ee62249 — feat: expose troubleshooting session lifecycle | Feature commit fast-forwarded into canonical main and pushed to GitHub origin/main on 2026-09-14. | Add bounded policy-authorized frame input, output polling/acknowledgment and resize operations; retain the remote cleanup/revocation gate before Livewire terminal exposure. |
| Phase 6 characterization | Managed-backup dashboard reads and labels conflated completed backups, HTTPS transport evidence and completed in-place restores; the existing fields do not establish isolated integrity/smoke/cleanup verification, and control-plane SQLite backup evidence is a separate scope. Characterized actions, schedule locks, job transitions, safety rollback, failure persistence, destination encryption and acceptance-audit limits. No application behavior changed. | Read-only source/instruction characterization completed; no tests or runtime state changed. | `1607749` — `docs: characterize backup recovery evidence` | Fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Implement and verify the read-only recovery summary and honest dashboard indicators. |
| Phase 6 read-only evidence | Backup metrics were calculated from only the latest 50 mixed-status rows and a completed in-place restore was labeled as drill evidence. Added an injected tenant-scoped evidence query and immutable summary that separate completed backups, HTTPS transport evidence, completed in-place restores and measured duration; the independent verification field remains explicitly unrecorded. | New recovery-evidence plus managed-backup/release-audit regression set: **12 passed, 120 assertions**. Fresh isolated full PHP suite: **1,402 passed, 1 unchanged baseline failure, 12,129 assertions**. Changed-file lint, Pint and `git diff --check` passed. | `764588e` — `feat: clarify backup recovery evidence` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Characterize and implement isolated restore verification with target, overwrite, integrity, smoke, failure-stage and cleanup contracts. |
| Phase 6B characterization | The existing restore mutates the live website and `BackupRestore` records no isolated target, overwrite mode, integrity/smoke result, failure stage, cleanup result or duration. Characterized a same-server temporary Restic directory/database protocol, exact snapshot binding, Laravel smoke boundary, fail-closed unsupported runtimes, EXIT-trap cleanup and separation from control-plane recovery. No application behavior changed. | Read-only source/protocol characterization completed; no runtime state or external resource changed. | `5bed78d` — `docs: characterize isolated restore verification` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Implement the persisted isolated verification attempt and safe request/action/policy/job boundary. |
| Phase 6B execution | The characterized recovery protocol needed a durable, duplicate-protected request and evidence boundary without changing the destructive in-place restore. Added a separate verification model/table, policy/request/action, post-commit job and injected remote script. It restores an exact snapshot to a same-server temporary MySQL target, checks restored files/database and Laravel readiness, records stage/status/duration/cleanup evidence, fails closed for unsupported target modes and offers safe retry after failure. | Verification/recovery/managed-backup regression set: **13 passed, 132 assertions**. Fresh strict isolated full PHP suite: **1,408 passed, 12,199 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, changed PHP lint, full Pint, Vite, route registration, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `a9b8730` — `feat: add isolated website backup verification` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Start Phase 7 inventory: connect environment, deployment, logs, health and incidents through bounded, authorization-checked reads; preserve the separate cloud/live acceptance track. |
| Phase 7 inventory | The existing observability dashboard exposed workspace-wide deployment and failed-health signals but had no selected-environment evidence context. Completed the source inventory and characterized existing policy, query, log, health, incident and side-effect boundaries before coding. | Read-only source/instruction characterization completed; no application behavior changed. | `82cbeb1` — `docs: inventory connected observability` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Implement the bounded environment evidence context with explicit authorization and secret-safe links. |
| Phase 7A | Operators needed a selected environment view connecting deployments, health observations, runtime-log metadata and explicitly related incidents. Added a policy-authorized Form Request, immutable filter/context data objects, bounded tenant-scoped query collaborator, context route/view and dashboard/project links. Existing sensitive routes remain responsible for bodies and response authorization; no causal inference, writes, jobs or provider calls were added. | Focused observability run: **54 passed, 464 assertions**, including **3 tests / 29 assertions** for the new context. Fresh strict isolated full PHP suite: **1,411 passed, 12,228 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, Vite, route registration, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `5661873` — `feat: connect environment observability evidence` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Add explicit bounded service/deployment and incident-severity filters while preserving authorization, collection limits and possible-correlation wording. |
| Phase 7B | The environment context had only a time window. Added validated repository-service selection, finite active/successful/unsuccessful deployment groups and incident-severity filtering, while retaining shared health/runtime/infrastructure signals that cannot be safely attributed to one repository. Cross-organization attached resources are excluded before evidence reads. | Focused observability/deployment/health/log run: **51 passed, 462 assertions**; new context file: **4 passed, 34 assertions**. Fresh strict isolated full PHP suite: **1,412 passed, 12,234 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, Vite, route registration, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `375c643` — `feat: add observability evidence filters` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Add explicit incident links to relevant deployment/build or configuration evidence without implying causation. |
| Phase 7C | Deployment incidents in the context identified a build resource but sent operators only to the general incident centre. Added a separate link to the existing policy-protected build detail for concrete deployment incidents, retaining the incident-centre navigation for response history and using the build page's existing configuration identity when present. Unknown categories/resource IDs receive no guessed link. | Focused observability/deployment/health/incident run: **26 passed, 218 assertions**. Fresh strict isolated full PHP suite: **1,412 passed, 12,236 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `3e79f4f` — `feat: link incidents to deployment evidence` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Characterize saved/shareable investigation views, authorization rechecks, filter normalization and retention before deciding whether persistence is justified. |
| Phase 7D characterization | Existing saved notification filters are user-preference JSON without workspace/resource authorization, expiry or retention semantics, while the observability context already has a policy-checked GET URL with finite non-secret filters. Rejected reusing that preference boundary and decided that an initial shareable investigation needs only a canonical validated URL; named shared views require a separate organization-owned design. | Read-only source and behavior characterization completed; no application behavior or schema changed. | `c9b1e00` — `docs: characterize shareable investigations` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Implement and verify the canonical shareable context URL without persistence or authorization changes. |
| Phase 7D URL | The validated environment context had no explicit copyable investigation link. Added a canonical URL generated only from normalized filters, excluding arbitrary query input and preserving policy checks on every visit. No persistence, secret, response, authorization or query-bound change was introduced. | Focused observability/deployment/health/incident run: **26 passed, 222 assertions**. Fresh strict isolated full PHP suite: **1,412 passed, 12,241 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `c757413` — `feat: add shareable observability links` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Characterize alert grouping/deduplication and post-deployment observation semantics before implementing the next troubleshooting slice. |
| Phase 7E characterization | Operational incidents already group active failures by workspace/category/resource and source monitors suppress repeated state-transition notifications, but direct notifier calls still deliver repeated inbox/webhook events; the deployment health check is a single immediate probe with no revision-aware observation window or persisted health relationship. Preserved all current grouping, transition, retry and timing boundaries while documenting the next explicit metadata and observation designs. | Focused alert, incident, website-health and deployment-observation regression set: **55 passed, 615 assertions**. Read-only characterization; no application behavior or schema changed. | `43d4e43` — `docs: characterize alert grouping` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Implement and verify stable incident identity/occurrence metadata on external alert deliveries without changing current delivery frequency; design revision-aware post-deployment observation separately. |
| Phase 7E alert metadata | External alert payloads lacked a stable identity for grouping repeated events. Added immutable incident delivery metadata from the locked active incident and used its stable key for PagerDuty while preserving the legacy fallback, per-event inbox/webhook delivery, retry behavior and secret boundaries. | Focused alert/incident/observability run: **24 passed, 205 assertions**; broader alert/incident/website-health/deployment regression set: **55 passed, 608 assertions**. Fresh strict isolated full PHP suite: **1,413 passed, 12,248 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `aae111c` — `feat: add alert incident metadata` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Design and characterize a bounded revision-aware post-deployment observation record and lifecycle; do not reuse periodic website health history. |
| Phase 7F characterization | The deployment health stage is a single immediate remote probe with rollback-on-failure, while periodic website checks are independently scheduled, website-scoped and unrelated to a build revision. Documented the separate observation aggregate, explicit disabled-by-default configuration, shared probe collaborator, post-commit scheduling, locked identity/deadline checks, supersession, retry, expiry and secret-safe result boundaries without changing application behavior or schema. | Focused deployment-health, website-monitoring/history, observability-context and repository-deployment run: **44 tests / 497 assertions**. Read-only characterization; no application behavior or schema changed. | `cb09f81` — `docs: characterize deployment observation` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Completed by the shared probe extraction; add the disabled-by-default revision-aware observation aggregate and lifecycle. |
| Phase 7F shared probe | `WebsiteHealthMonitor` mixed remote execution with website state transitions. Extracted the injected `WebsiteHealthProbe` and immutable `WebsiteHealthProbeResult`; periodic history, thresholds, incident transitions, immediate deployment probe, retries and secret-safe bounds remain unchanged. | Focused probe/website-monitoring/history/automatic-control/deployment-health run: **35 tests / 441 assertions**. Fresh strict isolated full PHP suite: **1,416 tests / 12,274 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `3e4c337` — `refactor: extract website health probe` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Add the disabled-by-default revision-aware observation aggregate and lifecycle using the extracted probe. |
| Phase 7F observation aggregate | Successful deployments had no durable, revision-bound post-deployment observation boundary. Added an optional environment window protected by the existing monitoring entitlement, an immutable non-secret target snapshot in the encrypted build payload, a finite observation status model and a locked/idempotent creation action invoked after successful build completion. The action validates the captured build/revision/path identity, avoids legacy/incomplete targets and supersedes older active observations for the same website/repository. The creation action performs no remote work itself; execution is a separate queued boundary. Periodic website history and the immediate deployment probe remain separate. | Focused feature/regression run: **68 tests / 517 assertions**. Fresh strict isolated full PHP suite: **1,421 tests / 12,301 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `32c3947` — `feat: add deployment observation records` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Add leased remote observation execution using the shared probe, with bounded retries, expiry/failure outcomes, duplicate-dispatch protection and stale-claim guards. |
| Phase 7F observation execution/read surface | The observation aggregate needed durable remote execution, lease recovery and a safe user-facing result without changing periodic website health or immediate deployment health. Added a post-commit unique job, a locked two-minute claim action, bounded queue retries/backoff, a minute-level scheduler for due work and expired leases, current build/revision/target revalidation, terminal expiry/failure/supersession outcomes and stale-claim protection. Build details expose bounded status metadata while hiding claim tokens and remote error text. | Final focused observation run: **14 tests / 87 assertions**. Fresh strict isolated full PHP suite: **1,429 tests / 12,359 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, `git diff --check`, Vite and required-PHP asset/browser suite: **9 passed**. | `c6eff04` — `feat: execute revision-bound deployment observations` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Include revision-bound observation outcomes in the bounded environment evidence context, preserving service filters, tenant authorization and exclusion of remote error text. |
| Phase 7F environment evidence outcomes | The bounded environment evidence context had no revision-bound outcome from an explicitly requested post-deployment observation. Extended the existing tenant-scoped query to retain active observations outside the time window, preserve service filters and eager-load only approved fields. An immutable `DeploymentObservationEvidence` read model and view expose status, checks, duration, HTTP status and check time only; exact build/revision/current-website matching rejects stale or cross-target rows. No remote calls, writes, jobs, routes or causal claims were added. | Combined environment-context, deployment-observation and observability regression run: **39 tests / 295 assertions**. Fresh strict isolated full PHP suite: **1,433 tests / 12,383 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, `git diff --check`, Vite and required-PHP asset/browser suite (**9 passed**) passed. | `0390030` — `feat: surface deployment observations in environment evidence` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Characterize named saved investigation views, including organization/resource authorization, filter normalization, expiry and retention, before deciding whether persistence is justified. |
| Phase 7G characterization | Existing saved notification filters are personal user-preference JSON with notification-only criteria, a ten-entry cap and name replacement, but no organization/environment identity, membership visibility, expiry or retention. The stateless observability URL already normalizes finite filters and rechecks environment authorization on every visit. Characterized and rejected reuse of the personal preference boundary; named shared views justify a separate organization-owned, environment-bound record with opaque identity, revalidated filters, explicit expiry and bounded retention. | Focused notification-inbox and observability regression run: **34 tests / 261 assertions**. Read-only source/behavior characterization; no application behavior, schema or runtime state changed. | `2bf89f8` — `docs: characterize named saved investigation views` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Implement the organization-owned named investigation view with validated filters, policy rechecks, opaque identifiers, explicit expiry, atomic retention bounds and redirect to the canonical context read. |
| Phase 7G named investigations | The stateless context link had no bounded named team handoff, and notification preferences could not safely carry workspace/environment authorization. Added an organization-owned, environment-bound view with opaque UUID routing, finite normalized filters, creator/manager deletion policy, current-resource revalidation, 7/30/90-day expiry choices, a 50-active-view organization cap and a bounded scheduled prune command. Opening redirects to the canonical context, so evidence remains read-only, current and secret-safe. | `ObservabilityInvestigationViewTest`: **10 tests / 48 assertions**. Adjacent observability/notification regression set: **44 tests / 309 assertions**. Fresh strict isolated full PHP suite: **1,443 tests / 12,433 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, route-cache creation, Vite, `git diff --check` and required-PHP asset/browser suite: **9 passed**. | `a2351fa` — `feat: add saved observability investigations` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Phase 8 characterization is complete; implement the typed category-aware control-plane diagnostic report. |
| Phase 8 characterization | The application already has separate safe control-plane diagnostics, import-time SSH discovery, numeric server telemetry, bounded logs and encrypted arbitrary root-command history, but no typed category-aware diagnostic report and no justified interactive transport. Characterized their scopes, authorization, output/secret boundaries, process timeouts and failure semantics. Decided to type the existing control-plane result first while preserving the current CLI/JSON/HTTP/cache projection; server-host probes and terminal sessions remain separate designs. | Read-only source, route, policy, job, migration and focused-test characterization completed; no application behavior, schema, queue state or remote resource changed. The known baseline `ProvisioningHardeningTest::test_website_database_user_is_local_only` remains unchanged. | `f084951` — `docs: characterize structured diagnostics` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Implement the typed category-aware control-plane diagnostic report with an exact legacy projection, then verify existing CLI, system-health, public-status and secret-safety behavior. |
| Phase 8 typed report | Existing operational checks were safe but untyped, leaving category-aware troubleshooting consumers coupled to legacy arrays. Added immutable enum-backed check/report data objects and made `OperationalDiagnostics::report()` the typed composition boundary; `run()` remains the exact legacy adapter used by current CLI, JSON, health, public-status and cache consumers. | Focused diagnostic regression set: **20 tests / 159 assertions**. Fresh strict isolated full PHP suite: **1,445 tests / 12,443 assertions, 1 unchanged baseline failure**. Required-PHP Composer validation/platform checks, PHP lint, full Pint, Pint test mode, route-cache creation and `git diff --check` passed. No frontend changes; browser/assets not rerun for this PHP-only slice. | `2f7d719` — `refactor: type operational diagnostics` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Characterize and design the fixed server-host structured diagnostic contract, including host-key behavior, command allowlist, timeout, failure and retention semantics; keep interactive transport separate. |
| Phase 8 fixed-host characterization | Existing server metrics, logs, import discovery and encrypted arbitrary-command history have separate scopes, but no current structured host-readiness report. Characterized a manual asynchronous snapshot with a dedicated policy ability, pinned-host-key fail-closed behavior, versioned fixed script, scalar allowlist, bounded timeout/output, lease/retry/stale-attempt protection and latest-result retention. No application behavior, schema, queue state or remote resource changed. | Read-only source/protocol characterization completed; implementation tests were defined before coding. | `7c4a896` — `docs: characterize fixed server diagnostics` | Documentation commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Completed by the implementation slice below; characterize the interactive troubleshooting transport next. |
| Phase 8 fixed-host implementation | Existing server diagnostics had no dedicated current-result, safe execution or read boundary. Added a policy-authorized Livewire action, one latest snapshot per server, fixed allowlisted parser/probe, post-commit leased job, bounded transport retries, sanitized failure stages, stale-attempt protection and typed read-only checks. Missing host identity fails closed and raw output/credentials are never retained. Existing arbitrary commands, metrics, logs, provisioning and provider behavior remain separate. | Focused suite: **16 tests / 90 assertions**. Adjacent server/import/log/command/observability set: **45 tests / 373 assertions**. Fresh strict isolated full suite: **1,461 passed / 12,534 assertions / 1 unchanged baseline failure**. Required-PHP Composer/platform, lint, Pint, route cache, shell, diff checks, Vite and required-PHP browser asset suite: **9 passed**. | `4add5b9` — `feat: add fixed server diagnostics` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Characterize the interactive troubleshooting transport and host execution model; keep terminal sessions separate from fixed diagnostics. |
| Phase 8 interactive-transport characterization | Existing queued commands are one-shot root executions with encrypted bounded output, while `Runner`/`ManagedSsh` have no durable PTY, stdin, resize, heartbeat or abandoned-process cleanup contract. Characterized the separate session record, connect/execute authorization, pinned host-key requirement, short-lived grants, leases, input/output bounds, revalidation, audit and cleanup requirements. No application behavior, schema, queue state or remote resource changed. | Read-only source, dependency and runtime capability characterization completed; no terminal code or remote execution added. | This documentation checkpoint | Will be fast-forwarded into canonical `main` and pushed before the lifecycle boundary implementation. | Implement the minimal persisted session authorization/lifecycle boundary without remote execution, then verify its revocation and cleanup semantics before selecting transport. |
| Phase 8 troubleshooting session lifecycle | The transport design had no durable grant, actor revalidation or terminal outcome boundary. Added an opaque hashed grant, actor/server policy split, finite session statuses, bounded absolute/idle deadlines, locked open/touch/close/revoke operations, membership heartbeat revalidation, atomic capacity and expired-session recovery, and a scheduled bounded expiry action. No remote execution, route, browser terminal or existing queued-command behavior changed. | Focused lifecycle suite: **13 tests / 46 assertions**. Adjacent server/import/log/command/observability regressions: **78 tests / 609 assertions**. Fresh strict isolated full suite: **1,474 passed / 12,580 assertions / 1 unchanged baseline failure**. Migration fresh/rollback/reapply, command/schedule, required-PHP lint, scoped Pint and `git diff --check` passed; prior required-PHP browser/assets evidence remains **9 passed**. | `6e9e55f` — `feat: add troubleshooting session lifecycle`; `335ea42` — `docs: clarify troubleshooting grant lifetime` | Feature and correction commits fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Select and test the bounded server-side PTY/transport and local/remote cleanup contract before adding routes or UI. |
| Phase 8 bounded troubleshooting transport | The persisted session boundary had no process/input/output seam. Added injected transport and connection contracts, a pinned SSH PTY command adapter, validated terminal dimensions, bounded async local I/O, idempotent process-group/temporary-credential cleanup and fail-closed host-key checks. Existing one-shot command execution and legacy Runner behavior remain unchanged; no route, frame store, broker, remote execution or UI was enabled. | Focused transport suite: **8 tests / 25 assertions**. Adjacent server command/diagnostic/session regression set: **58 tests / 376 assertions**. Fresh strict isolated full PHP suite: **1,482 passed / 12,605 assertions / 1 unchanged baseline failure**. Required-PHP changed-file lint, full Pint and `git diff --check` passed; prior required-PHP browser/assets evidence remains **9 passed**. | `6013f19` — `feat: add bounded troubleshooting transport` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Add durable bounded encrypted frame relay and supervisor-owned broker lease/attempt/process guards before routes or UI. |
| Phase 8 durable frames and broker ownership | The transport seam had no durable exchange buffer or supervisor identity. Added encrypted, sequenced input/output frames with independent backpressure and bounded pruning; exact hashed lease, attempt and process guards; broker claim/connect/renew/release actions; a bounded supervisor-oriented broker command; and terminal cleanup integration. Input is marked sent before remote write for at-most-once semantics, output is acknowledged by sequence, expired leases fail closed and stale callbacks cannot affect a newer attempt. Existing one-shot command execution remains unchanged and no route/UI was exposed. | Focused troubleshooting/session/transport suite: **33 tests / 135 assertions**. Fresh strict isolated full PHP suite: **1,494 passed / 12,668 assertions / 1 unchanged baseline failure**. Required-PHP changed-file lint, full Pint and `git diff --check` passed. New migration pair fresh/rollback/reapply passed on disposable SQLite; whole rollback retains the unrelated pre-existing SQLite index/drop-column failure. Prior required-PHP asset/browser evidence remains **9 passed** and was not rerun for this PHP-only slice. | `5b54f3b` — `feat: add durable troubleshooting frame broker` | Feature commit fast-forwarded into canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Characterize and implement the policy-authorized HTTP/Livewire connect, input, output polling/ack, resize, close and reconnect boundary; keep exposure gated on remote cleanup proof. |
| Phase 0 | Product inventory and isolation/baseline were missing for this expansion. Created this ledger; no application behavior changed. | See baseline evidence above. | `590fa5a` — `docs: record product expansion baseline`; `27176fe` — `docs: record product expansion push` | Pushed to GitHub `origin/main` on 2026-09-12. | Completed by the Phase 1A preview-configuration characterization and implementation below. |
| Phase 1A | `PreviewDeploymentLifecycle::create()` copied the source website's encrypted environment text into previews, mixing lifecycle orchestration with preview configuration policy and risking source credentials in untrusted code. Added `PreviewEnvironmentConfiguration`, explicit preview-owned application/database values and sanitization of legacy previews on revised events. | `PreviewDeploymentTest.php`: 5 passed, 56 assertions. Adjacent provisioning/callback/environment tests: 36 passed, 304 assertions. Full isolated PHP suite: 1,320 passed, 1 baseline failure, 11,429 assertions; same `ProvisioningHardeningTest` `localhost` count mismatch as Phase 0. Pint and `git diff --check` passed. | `87a242f` — `feat: isolate preview environment configuration` | Pushed to GitHub `origin/main` on 2026-09-12. | Define trusted-branch/fork policy and explicit secret-scope approval, then address navigation/feedback and first-deployment guidance with focused browser evidence. |
| Phase 1B | Signed preview webhooks lacked explicit target-branch, target-repository and fork admission. Added provider-neutral metadata to `VerifiedRepositoryWebhook`, provider-specific normalization and injected `PreviewTrustPolicy`; forks, mismatched targets and unknown metadata are denied before any preview side effect, while close cleanup remains available. | Preview suite: 12 passed, 97 assertions. GitHub, GitLab and Bitbucket preview metadata paths are covered; adjacent repository webhook and provisioning callback regressions: 45 passed, 390 assertions. Pint and `git diff --check` passed. | `1c422d5` — `feat: enforce trusted preview pull requests` | Pushed to GitHub `origin/main` on 2026-09-13. | Design the explicit revision-bound preview secret-scope approval and dependent-resource credential boundary; then address navigation/feedback and first-deployment guidance. |
| Phase 1C | The safe baseline had no explicit, narrowly scoped way for a manager to authorize source runtime secrets. Added a scoped approval route/action/policy, source-environment linkage, version-bound approval records and a resolver that applies values only for the exact revision; preview-owned and dependent-resource credentials remain isolated. | Preview suite: 17 passed, 136 assertions. Adjacent preview/webhook/provisioning/project/environment/configuration recovery suites: 189 passed, 1,571 assertions. Full isolated PHP suite: 1,332 passed, 1 unchanged baseline failure, 11,510 assertions. Asset build, Pint and `git diff --check` passed; isolated migration rehearsal applied the new schema. | `80cbaec` — `feat: add revision-bound preview secret approvals` | Pushed to GitHub `origin/main` on 2026-09-13. | Resolve the documented mobile/tablet feedback discrepancy and add actionable first-deployment preflight guidance with focused browser evidence. |
| Phase 1D | The responsive layout opened the command palette at tablet width without a visible focus-restoration trigger, and mobile navigation omitted the desktop Settings shortcut. Added named tablet/mobile palette triggers, shared visible-trigger focus restoration and the existing account Settings destination while keeping Account as the sole current route. | `DashboardTest.php` and `LocalUiAssetTest.php`: 36 passed, 737 assertions. Accessibility browser suite: 3 passed across mobile/tablet/desktop. Mobile visual crawl: 1 passed. Vite build, Pint and `git diff --check` passed. | `9a0bea8` — `fix: restore responsive navigation focus` | Pushed to GitHub `origin/main` on 2026-09-13. | Add actionable first-deployment preflight guidance with focused browser evidence. |
| Phase 1D test contract | The broad visual audit checked the mobile dialog ID at desktop width even though the layout intentionally uses the desktop sidebar there. Selected `#primary-navigation` below the desktop breakpoint and `#desktop-navigation` at desktop widths; no application behavior changed. | Mobile and tablet visual-audit runs passed; corrected desktop visual-audit run: 1 passed in 1.4 minutes. | `575d86f` — `test: align responsive visual navigation audit` | Pushed to GitHub `origin/main` on 2026-09-13. | Add actionable first-deployment preflight guidance with focused browser evidence. |
| Phase 1E | The repository page showed a technical snapshot without actionable recovery links or sanitized distinction between invalid provider credentials, insufficient scopes and plan denial. Added injected `DeploymentPreflightGuidance`, preserved the persisted preflight shape, and rechecked the existing deployment entitlement inside `DeployRepositoryAction` before writes. | `RepositoryDeploymentTest.php`: 11 passed, 70 assertions. Adjacent deployment/preflight/environment/authorization/configuration coverage: 32 passed, 269 assertions. Mobile/tablet visual audit and corrected desktop audit passed; Vite, Pint and `git diff --check` passed. | `e1f985d` — `feat: add actionable first deployment guidance` | Pushed to GitHub `origin/main` on 2026-09-13. | Complete the Phase 2 configuration authoring/editor, dependency overview, secret-safe environment comparison and read-only observable-drift slice. |

| Phase 2A | The configuration authoring page had no bounded view of the application's recorded topology before a user prepared a review. Added an injected eager-loaded query collaborator and immutable secret-safe environment read model covering servers, websites, branch-matched repositories, latest build status, processes, resources and masked variable counts. | Focused overview/web coverage: 5 passed, 59 assertions. Full configuration/API/ownership batch: 183 passed, 1,775 assertions. The query-count regression proves eager-loaded reads remain constant as environments grow; sensitive command, resource configuration, variable value/ciphertext and foreign-project data are excluded. Full Pint, Vite build and `git diff --check` passed. No schema or review/apply behavior changed. | `1d5b571` — `feat: add configuration environment overview` | Pushed to GitHub `origin/main` on 2026-09-13. | Add the next smallest Phase 2 authoring slice: schema-aware starter guidance and review-safe authoring feedback using the existing version-2 parser, without flashing submitted commands or bindings. |
| Phase 2B | The version-2 form exposed raw YAML and JSON fields without explaining the parser's required structure or safe binding workflow. Added an injected authoring-guide collaborator with a parser-verified starter document, illustrative bindings and safe field guidance; the editable form remains blank so placeholders cannot be submitted accidentally. | Authoring/web coverage: 4 passed, 47 assertions, including parser acceptance and absence of credential material. Full Pint, Vite build and `git diff --check` passed. Existing validation still rejects malformed input without flashing documents or bindings into session old input. No schema, API, persistence or review/apply behavior changed. | `f928653` — `feat: add configuration authoring guide` | Pushed to GitHub `origin/main` on 2026-09-13. | Characterize and implement secret-safe comparison of desired/recorded environment state, explicitly separating any future observable remote state from local comparisons. |
| Phase 2C | Users could see one recorded environment at a time but had no safe way to compare environments. Added a manager-authorized GET comparison using the existing eager-loaded read model, project-scoped Form Request and immutable comparison result. The UI labels the result as recorded local metadata and excludes commands, variable keys/values, encrypted resource configuration and provider state. | Comparison/overview/web coverage: 7 passed, 81 assertions. This includes same-project validation, authorization before malformed input, invalid-ciphertext non-decryption and rendered secret/command exclusion. Full Pint, Vite build and `git diff --check` passed. No provider call, schema mutation, review/apply or remote-drift claim was introduced. | `6398087` — `feat: compare recorded environments safely` | Pushed to GitHub `origin/main` on 2026-09-13. | Define the smallest read-only observable-state adapter for supported provider fields, keeping it distinct from desired and recorded local configuration and documenting unavailable/unknown observations. |
| Phase 2D | Recorded local state was clearly separated from provider state, but there was no explicit way to inspect supported remote server metadata. Added a manager-authorized, opt-in observation query and request around the existing `ServerProvider::server()` contract, with organization scoping, safe selected columns and observed/unavailable/unknown outcomes. | Observation, overview, comparison and configuration web regressions: 12 passed, 110 assertions. Missing placement, no-provider-call behavior, sanitized provider failure, malformed-input authorization order, same-project validation, normalized differences and no persistence are covered. Pint, Vite build, route registration and `git diff --check` passed. | `6ebadb8` — `feat: add read-only provider observations`; `f1553fd` — `docs: record provider observation slice` | Feature and documentation commits pushed to GitHub `origin/main` on 2026-09-13. | Start Phase 3C: characterize exact preview-stack ownership and stale-attempt-safe cleanup after Phase 3B readiness. |
| Phase 3A | Preview lifecycle records described only the website/repository/environment, despite existing process/resource persistence and deployment-snapshot support for dependent services. Added template-driven stack declarations and an injected action that idempotently persists queue/scheduler processes and planned managed PostgreSQL/Valkey children for supported Laravel presets inside the existing transaction. | Preview/catalog and adjacent environment suites: 30 passed, 236 assertions. Full isolated PHP suite: 1,347 passed, 11,633 assertions. Child names, commands, generated database credential use, loopback Valkey binding, unsupported-preset behavior and repeated revision idempotency are covered. Changed-file lint, Pint and `git diff --check` passed. | `290577c` — `feat: declare preview application stacks`; `7adf1f4` — `docs: record preview stack manifest slice` | Feature and documentation commits pushed to GitHub `origin/main` on 2026-09-13. | Phase 3B readiness is now complete; continue with Phase 3C ownership-aware, retryable cleanup, then quotas. |
| Phase 3B | Phase 3A resources remained `planned` after deployment progress or failure. Added injected `PreviewStackReadiness` and a deployment-plan resource-stage boundary so preview resources transition to `provisioning`, `ready` or `failed` through existing signed callbacks and queued-job failure handling. | Focused readiness coverage: 21 passed, 170 assertions. Adjacent deployment/resource coverage: 24 passed, 157 assertions. Full isolated PHP suite: 1,351 passed, 11,652 assertions. Pint, changed-file lint and `git diff --check` passed. | `f780685` — `feat: record preview resource readiness`; `ad98671` — `docs: record preview resource readiness` | Feature and documentation commits pushed to GitHub `origin/main` on 2026-09-13. | Phase 3C cleanup is now complete; continue with Phase 3D atomic concurrent-preview quotas, then explicit initialization/secrets. |
| Phase 3C | Preview closure knew how to clean generic website state but not the exact processes and managed PostgreSQL/Valkey children created by a preview, and partial cleanup had no durable retry boundary. Added explicit ownership flags, an immutable non-secret cleanup manifest, locked capture, a unique leased cleanup job, exact script generation, lifecycle re-entry for cancellation/watchdog/failure and manager-only retry. | Focused cleanup/lifecycle suite: 23 passed, 219 assertions. Full isolated PHP suite: 1,357 passed, 11,718 assertions. Migration fresh/rollback/reapply, required-PHP Composer platform checks, Pint, Vite build, `git diff --check` and isolated Playwright asset suite (9 tests) passed. Local runner/script evidence does not establish cloud cleanup or provider acceptance. | `74165bd` — `feat: clean up preview stacks safely` | Feature commit pushed to GitHub `origin/main` on 2026-09-13. | Phase 3D: enforce atomic concurrent-preview quotas, then define explicit initialization/secrets and independent provider-readiness evidence. |
| Phase 3D | Preview capacity was checked before the transaction and only the project was locked, so concurrent pull requests could overcommit the last website/preview slot. Added organization-scoped active-preview usage, config-as-code plan limits and a transactionally serialized lifecycle check with an internal lock version for SQLite-compatible races. | `PreviewDeploymentTest.php`: 18 passed, 172 assertions. Independent-process quota race: 1 passed, 7 assertions. Broader quota/plan/billing batch: 34 passed, 224 assertions; adjacent cleanup/runtime/deployment: 19 passed, 150 assertions; project/tenancy: 9 passed, 49 assertions. Fresh isolated full PHP suite: 1,359 passed, 11,742 assertions. Migration fresh/rollback/reapply, required-PHP Composer validation/platform checks, Pint, Vite build and `git diff --check` passed. | `d722bad` — `feat: enforce concurrent preview quotas` | Feature commit pushed to GitHub `origin/main` on 2026-09-13. | Phase 3E: define explicit preview initialization/secrets and resource credential boundaries, then characterize independent provider-readiness evidence. |
| Phase 3E | The template-driven stack had no explicit first-release initialization or managed Valkey credential boundary. Added a curated `PreviewInitialization` data boundary, injected durable initialization lifecycle with revision/attempt/stale-callback guards, encoded marker-based execution inside the existing post-deployment stage, encrypted preview-only Valkey credentials and legacy passwordless preservation. | Phase 3E focused batch: 37 passed, 375 assertions. Adjacent lifecycle/deployment/callback batch: 56 passed, 473 assertions; callback integrity: 5 passed, 34 assertions; compatibility follow-up: 31 passed, 307 assertions. Fresh full suite at feature commit: 1,362 passed, 1 unchanged baseline failure, 11,785 assertions. Migration rehearsal, lint, Pint, Composer/platform checks, Vite and `git diff --check` passed. | `cf5da72` — `feat: add explicit preview initialization`; `fa114f0` — `fix: preserve legacy preview cache credentials` | Both commits fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Phase 3F: characterize independent provider-readiness evidence and keep it distinct from local lifecycle state. |

| Phase 3F | The existing provider observation discarded provider lifecycle state, so the UI could not distinguish a provider-reported ready server from a stopped one and local preview callbacks could be over-interpreted as remote health. Extended the existing `CloudServerData` result and three provider adapters with normalized transient readiness, then carried it through the existing manager-authorized observation query and view. | Focused provider contract/observation batch: 10 passed, 84 assertions. Fresh isolated full PHP suite: 1,366 passed, 1 unchanged baseline failure, 11,808 assertions. Required-PHP Composer validation/platform checks, Pint, Vite and `git diff --check` passed. No persistence, polling, reconciliation, remote mutation or route/API contract change. | `8a116dc` — `feat: expose provider readiness state` | Feature commit fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Phase 4: begin the smallest curated service-template slice with explicit version, compatibility/readiness and recovery metadata. |
| Phase 4A | Existing application presets had runtime defaults but no explicit versioned operational contract, and project creation did not record which curated definition supplied its defaults. Added immutable catalog/data boundaries and Laravel 1.0.0 metadata for compatibility, resources/credential modes, persistent data, readiness, limits, backup/restore, upgrade, recovery and deletion; new curated projects record `template_version`, while legacy/unpublished presets remain unversioned. | Focused catalog/project/runtime/preview batch: 38 passed, 326 assertions. Fresh isolated full PHP suite: 1,371 passed, 1 unchanged baseline failure, 11,839 assertions. Populated SQLite migration rollback/reapply and config-cache checks passed; Composer/platform, Pint, Vite and `git diff --check` passed. No installation, upgrade, remote mutation or queue contract change. | `925baf5` — `feat: version curated application templates` | Feature commit fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Phase 4B: characterize existing Node resource composition and add it only with complete lifecycle/recovery evidence. |
| Phase 4B | The generic Node preset had runtime defaults but no preview composition, and preview environments did not inherit the selected source runtime settings. Added a versioned Node service-template declaration for managed PostgreSQL/Valkey and copied only the existing runtime fields into preview environments. Reused the existing snapshot, encrypted credential, callback readiness, ownership-aware cleanup and retry paths; Node has no Laravel workers or automatic initialization. | Focused catalog/project/runtime/preview batch: 41 passed, 372 assertions. Adjacent preview/concurrency/cleanup/readiness/PostgreSQL/configuration-resource/runtime/project batch: 56 passed, 518 assertions. Fresh isolated full PHP suite with PHPUnit `:memory:` configuration: 1,374 passed, 1 unchanged baseline failure, 11,885 assertions. PHP lint, Composer/platform, Pint, Vite, config-cache and `git diff --check` passed. A file-database run was discarded because it invalidated the repository's isolation assertions. No remote mutation, dependency or queue contract change. | `b8c5871` — `feat: compose node preview resources` | Feature commit fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Phase 4C: characterize template installation/upgrade execution and assess one additional service only if its existing lifecycle can support it. |
| Phase 4C | Curated metadata needed characterization against the actual deployment, resource-installation and exact-cleanup paths before another service could be published. Added a lifecycle contract test for published Laravel/Node templates and explicitly deferred Mailpit because the resource model, configuration schema, provisioning, backup and cleanup lifecycle do not support it yet. Template upgrades remain reviewed deployments; no automatic version mutation was introduced. | Lifecycle characterization: 3 passed, 57 assertions. Adjacent service-template, release, PostgreSQL resource, preview cleanup, project-creation and preview-deployment batch: 41 passed, 408 assertions with `SESSION_DRIVER=array`. Pint and `git diff --check` passed. The unsupported `SESSION_DRIVER=sync` attempt failed during test setup and was discarded. | `818ebc0` — `test: characterize service template lifecycle` | Feature commit fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Completed by Phase 5A deployment evidence; proceed to Phase 5B monorepo change-impact characterization. |
| Phase 5A | Build details had lifecycle facts in separate status fields, setup stages and failure guidance, with no unified request-to-health evidence view. Added a plan-driven read collaborator, immutable timeline entries, exact revision/actor/approval context and the existing configuration-operation identity without adding writes or schema changes. | Timeline/history/log batch: **16 passed, 134 assertions**. Fresh isolated full PHP suite: **1,383 passed, 1 unchanged baseline failure, 11,979 assertions**. Pint, PHP lint and `git diff --check` passed. Encrypted configuration payload was not rendered. | `b5d1cab` — `feat: clarify deployment lifecycle evidence` | Pushed to GitHub `origin/main` on 2026-09-13. | Completed by the Phase 5B path-filter slice below; characterize per-service repository-root execution and multi-target impact next. |
| Phase 5B path filters | Automatic webhook deployment had no per-target path scope or persisted provider changed paths. Added bounded relative include/exclude globs, GitHub/GitLab extraction, conservative unknown handling, explicit skipped history, pending-path aggregation, request/UI configuration and dashboard/history/retention support. | Focused evaluator/webhook/request/history/dashboard/demo/retention batch: **57 passed, 976 assertions**. Fresh isolated full PHP suite: **1,392 passed, 1 unchanged baseline failure, 12,027 assertions**. Full Pint and `git diff --check` passed. | `c79c736` — `feat: add safe monorepo path filters` | Feature commit fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Completed by the per-service repository-root slice; implement a read-only multi-target impact preview without changing unavailable-path behavior. |
| Phase 5B repository roots | Deployment scripts and website-level maintenance jobs assumed one repository root per target. Added a validated optional service root, immutable build-payload snapshot/fallback, root-aware deployment scripts/jobs and an injected shared Caddy renderer. Default paths and historical/queued compatibility remain unchanged; no shared-dependency inference or multi-target orchestration was added. | Focused deployment-root/preview/rollback/backup/hooks/runtime/domain/security batch: **60 passed, 586 assertions**. Fresh isolated full PHP suite: **1,396 passed, 1 unchanged baseline failure, 12,086 assertions**. Required-PHP platform checks, Pint, Vite and `git diff --check` passed. | `72d7c69` — `feat: support per-service repository roots` | Feature commit fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Completed by the read-only multi-target impact-preview slice; proceed to Phase 6 verified backup-recovery characterization. |
| Phase 5B impact preview | The webhook evaluator could decide one target, but users could not preview the same changed-file set across their enabled push targets. Added a policy-protected GET preview that reuses the tenant-scoped inventory and pure evaluator, accepts bounded newline paths or explicit unavailable data, and reports affected/unaffected/unknown targets without any write or queue path. | Preview feature suite: **4 passed, 24 assertions**. Repository/deployment regression batch: **61 passed, 524 assertions**. Fresh isolated full PHP suite: **1,400 passed, 1 unchanged baseline failure, 12,111 assertions**. Full Pint, changed-file PHP lint, route registration and `git diff --check` passed; no frontend assets changed. | `3940a28` — `feat: preview repository deployment impact` | Feature commit fast-forwarded through canonical `main` and pushed to GitHub `origin/main` on 2026-09-13. | Phase 6: characterize backup completion versus verified recovery, then implement the smallest read-only recovery evidence slice while preserving restore and cleanup semantics. |

## Phase 1 exit verification

The corrected isolated full PHP suite ran with the repository's testing
configuration, including `APP_DEBUG=true` so existing validation-feedback
assertions exercise their intended rendered response. It passed **1,334 tests
and 11,533 assertions**, with the one unchanged Phase 0 failure in
`ProvisioningHardeningTest::test_website_database_user_is_local_only` (the
test expects three `localhost` occurrences and the current script contains
four). An earlier run with `APP_DEBUG=false` produced four additional response
rendering failures; those were environment-induced and the affected classes
passed when rerun with `APP_DEBUG=true`. No Phase 1 regression was found.

Phase 1 therefore met its local exit gate for the implemented scope:
preview configuration is explicit and secret-safe, trusted preview admission
and revision-bound secret approval are enforced, responsive navigation and
focus restoration work at supported breakpoints, and first-deployment
guidance distinguishes actionable provider and entitlement blockers. Phases
3A–3D have since added a local, template-driven manifest for the supported
Laravel worker/scheduler/PostgreSQL/Valkey stack, callback-backed local
resource readiness states, retryable ownership-aware cleanup and atomic
concurrent-preview quotas. Explicit initialization secrets remain Phase 3 work.
Local evidence
still does not establish the separate live acceptance drill or cloud/provider
acceptance.

## External acceptance still outstanding

Local tests do not establish the separate live acceptance drill. The following
remain explicitly external: approved restricted provider credentials and cloud
provision/deploy/rollback/backup/restore/cleanup, production SMTP, independent
monitoring/heartbeat destinations, production GitHub App configuration, approved
Stripe activation and live SSO/provider acceptance. Do not claim these as passing
until the appropriate evidence is recorded.

## Final cross-feature verification and requirement audit — 2026-09-14

### Isolation and runtime

The final audit used the pushed `product-expansion/phase8-local-verification`
checkout at `614a0ae` in
`/root/Documents/Codex/2026-09-14/buildpusher-product-expansion-final`.
It has independent locked Composer and npm dependencies, a new application key,
file-backed SQLite database, storage, sessions, cache and built assets. The
database was freshly migrated and seeded through
`2026_09_14_010000_add_catalog_synced_at_to_sizes`. A path assertion before the
cache commands resolved the database, storage, cache, sessions and filesystem
under that checkout only. No production credentials, cloud resources or
acceptance-drill files were used.

The browser server used `TELESCOPE_ENABLED=false` because the disposable seeded
database intentionally does not include Telescope's optional tables. This only
disabled optional local request recording; it did not change application routes,
provider behavior or feature configuration.

### Verification results

| Check | Result |
| --- | --- |
| Strict PHP suite | **1,515 passed, 5 failed, 12,809 assertions**. The five failures reproduce the fresh pre-Phase 9 baseline: four existing validation-message response assertions in `OperationalIncidentTest`/`OrganizationManagementTest`, plus the `ProvisioningHardeningTest` `localhost` occurrence mismatch. No Phase 9 test failed. |
| Required-PHP Pint | **Passed** with `/root/.local/share/buildpusher/php-8.5.10/bin/php vendor/bin/pint --test`. |
| Required-PHP Composer manifest/platform | `validate --no-check-publish` passed; `check-platform-reqs` passed through the pinned PHP 8.5.10 runtime. The system Composer libraries emit PHP 8.5 deprecation notices; no dependency or lockfile was changed. |
| Migration and cache rehearsal | **Passed**: fresh migration/seed, resolved-path assertion, `config:cache`, `route:cache` and `view:cache`. |
| Asset build | **Passed**: `npm run build`. |
| Built asset/layout and no-JavaScript browser checks | **9 passed** with the required PHP fixture runner. |
| Served Livewire/runtime smoke after route/config/view caching | **1 passed**. The actual versioned Livewire JavaScript returned HTTP 200 with a JavaScript content type and mobile navigation opened/closed without page errors. |
| Accessibility browser checks | **3 passed** across mobile, tablet and desktop. The previously documented tablet focus discrepancy is resolved in the current main line. |
| Broad visual route audit | **3 passed** across mobile, tablet and desktop, including the authenticated route crawl. The previously documented missing mobile Settings link is resolved. |
| Repository integrity | **Passed**: `git diff --check`; the isolated branch has no tracked changes after verification. |

The first visual-audit attempt was discarded as evidence because the temporary
`/tmp` worktree was removed while the server had Telescope enabled. The audit was
repeated from the durable isolated checkout with Telescope disabled, and all
three crawls passed. This final audit validates local behavior only; it does not
establish provider/cloud or live acceptance.

### Requirement audit and handoff

Phase 9's safe local scope is complete: cost reads are organization-scoped and
injected, catalog estimates are timestamped and distinguished from measured
telemetry, provider billing remains explicitly unavailable, preview quota and
lifetime are visible, server attribution is labeled without fractional cost
invention, and cleanup guidance remains review-only. The GET surface performs no
deletion, hibernation, status transition or job dispatch.

Across the product-expansion slices, routes, response envelopes, validation keys,
flash behavior, persisted status values, queued-job payloads, provider contracts,
tenant checks, leases, locks, stale-attempt guards and remote-call boundaries
were preserved. The remaining release gates are the authorized disposable cloud
drill, restricted provider credentials and cleanup evidence, production mail,
GitHub App configuration, approved billing activation, live monitoring and live
SSO/provider acceptance. Interactive terminal UI exposure also remains
deliberately gated. These are external work, not local test failures.

**Local implementation and verification gate: complete.** The final
documentation commit records this audit; its exact hash and push status are
reported in the handoff. The next task is to schedule the separately authorized
external acceptance work, not to claim it as complete locally.

## Follow-up baseline test-contract cleanup — 2026-09-14

### Problem and boundary

The final cross-feature audit identified five failures that predated the Phase 9
cost work. Four incident and organization tests asserted that a production-safe
HTTP 422 error page rendered the operation message, even though the existing
controllers intentionally preserve the message on the attached HTTP exception
while Laravel's non-debug page remains generic. The fifth assertion counted the
isolated callback URL's `localhost` along with the three SQL host literals in the
website database script.

This was a test-contract correction, not an application behavior change. The
existing 422 statuses, exception messages, no-write guarantees and local-only
database user grants remain unchanged. The provisioning test now scopes its
assertion to quoted SQL host literals and explicitly rejects wildcard grants.

### Verification and handoff

- Focused incident, organization and provisioning set: **28 passed / 177 assertions**.
- Complete strict PHP suite: **1,520 passed / 12,827 assertions** in 834.96 seconds.
- Required-PHP Pint and all strict warning, risky-test, deprecation and PHPUnit
  deprecation checks passed.
- Commit `711af3c` (`test: align baseline rejection assertions`) was pushed to
  `origin/fix/baseline-test-failures-20260914`, fast-forwarded into canonical
  `main` and pushed to `origin/main`.

The product-expansion local implementation and verification gate remains
complete. The next task is the separately authorized external acceptance work;
provider/cloud credentials, production integrations, billing, live monitoring,
SSO/provider acceptance and the live drill remain outstanding.

## Follow-up product slice — API access on every plan — 2026-09-14

### User problem and boundary

The control-plane API and scoped personal access-token workflow were previously
gated by the `api` entitlement, while only higher plans exposed that
entitlement. This prevented Free and lower-tier workspaces from using the same
automation surface. The change opens the existing API capability to every
configured billing plan and adds explicit plan-level request quotas. It does
not make paid deployment, scaling, backup, monitoring or other feature
entitlements available on lower plans.

### Implementation and preserved contracts

- `config/billing.php` grants `api` to Free, Starter, Pro and Team; Business
  already had it and Unlimited continues to use its wildcard entitlement.
- The named `api` rate limiter reads the configured plan quota and keys
  authenticated requests by current workspace, so workspace members share the
  owner plan's quota. Unauthenticated API endpoints retain the safe IP/free
  fallback.
- Existing Sanctum token abilities, token ownership and expiry, organization
  scoping, network policy, request authorization ordering and operation-specific
  entitlements remain unchanged.
- Pricing and billing screens show the quota. Exceeding it retains Laravel's
  normal HTTP 429 response and rate-limit headers.

| Plan | API requests per minute |
| --- | ---: |
| Free | 60 |
| Starter | 120 |
| Pro | 300 |
| Team | 600 |
| Business | 1,200 |
| Unlimited | 3,000 |

This is a focused configuration and middleware change rather than a new
interface, repository or action. The existing `Entitlements` and
`ControlPlaneAccess` collaborators remain the single access boundary; the
rate limiter is responsible only for request volume. This keeps the
responsibilities separate and avoids changing API response contracts.

### Verification and handoff

- Focused API, automation, entitlement, billing and analytics coverage:
  **58 passed / 273 assertions**.
- Complete strict PHP suite: **1,525 passed / 12,860 assertions** in 541.67
  seconds, with no warnings, risky tests or deprecations.
- Required-PHP Pint, `composer validate`, `composer check-platform-reqs` and
  `git diff --check` passed. Dependency lockfiles were unchanged.
- Feature commits `139fc1f` and `6749fed` were pushed to
  `origin/feat/api-access-all-plans-20260914`.
- Verification/documentation commit `c1122c3` was also pushed to that branch.
- The separate dev acceptance checkout and live/provider acceptance were not
  modified or claimed as complete.

The next task is to integrate this verified slice into `main`, push `main`,
and continue only with a separately scoped request.

## External provider acceptance attempt — 2026-09-15

### Scope and authorization

An explicitly authorized disposable DigitalOcean drill was run against the
isolated development application. The authorization was limited to the
smallest available droplet size and a maximum total spend of $10; no
production credentials, infrastructure or billing resources were used. The
drill started at the first disposable-server event,
`2026-09-15T20:37:35Z`, and the exact disposable server was deleted at
`2026-09-15T21:18:45Z`.

The controlled fixture was `natecorkish/Deployer-Test`. Its initial revision
was followed by pushed revision `dcdd54fdd2c7407531db9f8df0a9aecf4d5d4033`
(`test: add second deployment revision`). The fixture commit was pushed to
the requested repository; no application secrets were committed.

### Evidence and outcome

- The final disposable DigitalOcean droplet used the `s-1vcpu-512mb-10gb`
  size in `nyc1`; provisioning reached all stages and the website reached
  active status. An acceptance-only script newline workaround was needed for
  the current deployed application and is not recorded as a product fix.
- The first deployment cloned the fixture but failed when the signed revision
  callback received HTTP 419. The callback route was missing from the web
  CSRF exception list. This was corrected in commit `95db81e` and pushed to
  `origin/main`; the isolated dev checkout was intentionally not modified.
- The backup destination record was present and used over HTTPS, but the real
  backup attempt was rejected by the storage provider because its stored
  access key does not exist. No snapshot, restore or recovery verification was
  claimed. The retry-state defect found during this attempt was covered by
  commit `71e4ddb` and pushed to `origin/main`.
- The release audit was not run because its required successful deployment,
  two-revision rollback chain and verified backup did not exist. This attempt
  therefore does not establish cloud release acceptance.
- Cleanup was verified independently: provider lookup reported the
  disposable droplet absent while the unrelated pre-existing droplet remained
  present; the disposable local server, website, repository, build and backup
  records were removed by the server cleanup workflow. The user-created
  backup destination record remains.

### Resumption requirements

Deploy `origin/main` commits `95db81e` and `71e4ddb` to the isolated dev
application before repeating the deployment drill. Replace the backup
destination credentials through the dev application with valid S3-compatible
DigitalOcean Spaces access credentials; a DigitalOcean control-plane token is
not itself a Spaces access key. Then repeat the full disposable workflow and
run the acceptance audit before cleanup. Do not treat this failed attempt as
live acceptance.

## External provider acceptance attempt — 2026-09-15 (second run)

### Scope and responsibility boundaries

The separately authorized disposable drill was resumed in the isolated main
runtime at `/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`. The
runtime had its own SQLite database, application key, storage, caches, built
assets, web service and database queue worker. The canonical live checkout and
the separate acceptance-drill checkout were not used for runtime state.

The user-authorized target remained the smallest available DigitalOcean
droplet and a maximum total spend of `$10`. The controlled source repository
was `natecorkish/Deployer-Test`; all fixture changes were pushed there through
the connected source-control provider without committing application secrets.
The second run started with disposable server record creation at
`2026-09-15T21:49:29Z` and the server deletion event was recorded at
`2026-09-15T22:39:50Z`.

The application fixes found during the first and second attempts were kept in
small, cohesive commits. They preserve the existing provider contract,
callback identity, provisioning stages, release paths and cleanup ownership:

- `a78b32c` — make server package provisioning noninteractive;
- `1348e92` — tolerate an absent `SSH_CONNECTION` variable in the server
  provisioning script;
- `e144566` — separate website provisioning script stages;
- `3a3e9b1` — prepare Caddy access logs with the Caddy-owned permissions;
- `40240f9` — separate build provisioning script stages;
- `5825e04` — serve literal IP websites over HTTP while preserving HTTPS for
  named domains;
- `419b34c` — refresh PHP workers after normal release activation; and
- `b243d11` — refresh PHP workers before rollback health validation.

Each commit was tested in proportion to its change and pushed to
`origin/main`. The rollback change specifically keeps the runtime refresh in
the release-switch operation, where it applies to both queued rollback jobs
and their health check, rather than adding provider-specific behavior to the
controller or job.

### Real-provider evidence

- DigitalOcean droplet `600822789` used size `s-1vcpu-512mb-10gb` in `nyc1`,
  received address `206.189.234.122`, completed provisioning and reached the
  active state.
- Website provisioning reached active status on that server.
- The fixture's v4 revision
  `c8f83ff04567b88b5bbc1caa40109e1b880dc2f2` deployed successfully and served
  `hello world v4` with HTTP 200.
- A distinct v5 fixture revision
  `393772b29709449bb1f5b7aa6a80c1801f45cbe8` deployed successfully and served
  `hello world v5` with HTTP 200.
- The rollback action created a rollback build linked to the v4 build,
  completed successfully, refreshed PHP-FPM before health validation and
  served `hello world v4` with HTTP 200 after the switch.
- The built-in audit was run for the disposable project and provider with
  `--since=2026-09-15T21:49:29Z`. It passed cloud provisioning, website
  provisioning, two-revision deployment and rollback. It correctly reported
  offsite backup, restore drill and post-restore health verification as
  missing.

### Backup blocker and cleanup

The existing HTTPS backup destination was exercised, but DigitalOcean Spaces
rejected its stored access key with the sanitized provider error that the
access key does not exist in its records. No snapshot was produced. Therefore
no restore, restored-data comparison or health check after restore was run or
claimed. The destination record remains available in the isolated dev
application; it needs a valid DigitalOcean Spaces access-key/secret-key pair.
A DigitalOcean control-plane API token is not a Spaces S3 credential.
A read-only request to the DigitalOcean Spaces-key API using the connected
provider token returned HTTP 403, so no Spaces key was created or modified and
the account was left unchanged.

After the audit, the supported server deletion action removed the exact
disposable droplet and its owned SSH key. Independent provider lookups showed
the disposable identifier absent and the unrelated pre-existing droplet still
present. Local server, website, repository, build, project and environment
records were removed; the user-configured backup destination was intentionally
preserved. The isolated web service and worker were stopped, and no queued
jobs remained.

This second run establishes real disposable provisioning, deployment,
revision change, rollback and provider cleanup evidence, but it does not
establish complete cloud release acceptance. The next external task is to
replace the destination with valid Spaces credentials, repeat the backup,
restore and post-restore health portion, and rerun the audit before cleanup.
Do not claim the release gate as passed until those checks and independent
restored-data evidence exist.

## Backup destination setup improvement — 2026-09-16

### User problem and responsibility boundaries

The existing backup screen required users to know that a DigitalOcean Spaces
S3 access key is different from a DigitalOcean control-plane API token, find
the region-specific S3 endpoint, and discover connection failures only after a
backup job ran. Destination creation also had no edit or explicit verification
step.

This slice keeps the HTTP boundary in `BackupController`,
`StoreBackupDestinationRequest`, `UpdateBackupDestinationRequest` and
`TestBackupDestinationRequest`. `BackupDestinationPolicy` owns the manager,
workspace and destination abilities. `BackupDestinationCatalog` and the
immutable `BackupDestinationPreset` data object own provider setup guidance
and form-only endpoint derivation. `CreateBackupDestinationAction`,
`UpdateBackupDestinationAction` and `TestBackupDestinationAction` own
creation, safe credential rotation and remote verification respectively.
The shared destination form is used for both creation and editing.

This applies single responsibility and dependency inversion without adding a
generic repository or provider abstraction. The existing `Runner`,
`ResticRepository`, encrypted model casts, backup jobs and organization
scoping remain the integration and persistence foundations.

### Preserved behavior and safety guarantees

- DigitalOcean Spaces, Amazon S3, Cloudflare R2 and generic S3-compatible
  setup paths are presented without persisting a new provider column or
  changing YAML, API or queued-job schemas.
- Spaces and Amazon endpoints can be derived from a validated region; custom
  S3-compatible endpoints remain explicit. Bucket, prefix, HTTPS and existing
  validation constraints remain enforced.
- Access and secret values remain encrypted and are never repopulated into
  create/edit forms. They are excluded from validation old input at the global
  exception boundary, including failures before controller execution.
- Editing with blank credential fields preserves the encrypted values and the
  generated Restic repository password. Any edit resets verification and
  requires a fresh check.
- Active or queued backups block edits. Location changes are blocked when
  retained snapshots use the destination, preserving snapshot reachability;
  users are directed to create a new destination.
- Verification uses an active managed website server, performs the existing
  Restic availability/repository initialization flow outside a database
  transaction, records bounded sanitized failure evidence, and records
  successful verification only after the remote command succeeds.
- Policy and request checks preserve current-workspace authorization,
  organization-scoped website selection, backup entitlements and no-write
  behavior for denied actors.

### Verification evidence

- Focused destination, managed-backup, recovery-evidence and restore-
  verification suite: **22 passed / 188 assertions**.
- Full Pint: passed.
- PHP lint for all changed PHP files: passed.
- `git diff --check`: passed.
- Backup route listing: passed; the update and verification routes are
  registered under the existing authenticated web boundary.
- Full PHP suite: **1,468 passed / 12,575 assertions; 75 failed**. The
  failures are existing isolated-suite baseline findings concentrated in
  testing-environment cache configuration, rate-limit-sensitive account and
  security tests, recipe feedback, troubleshooting HTTP/transport, social
  authentication and two-factor flows. The four backup-related suites passed
  within that run; no unrelated failures were changed for this slice.
- `npm run build` was attempted in the isolated checkout but could not start
  because `node_modules/.bin/vite` is absent. No asset files were changed.
- No cloud resources, production credentials or acceptance-drill checkout
  were used.

### Commit and next task

- Implementation commit `d0dca6c` (`feat: simplify backup destination
  setup`) was pushed to `origin/main`.
- This documentation update is the next cohesive commit and will be pushed
  separately as required.
- Local implementation is complete. The next task is the separately
  authorized dev/provider acceptance: enter a valid DigitalOcean Spaces
  access-key/secret-key pair in the isolated dev application, run a real
  backup and restore verification, and record the result. The prior
  control-plane token failure means that external acceptance remains
  outstanding; local tests do not establish it.

## Test-environment isolation correction — 2026-09-16

### Problem and boundary

The fresh strict baseline exposed a test-harness mismatch rather than a
product regression. The application cache configuration correctly prefers
Laravel's current `CACHE_STORE` setting, but `phpunit.xml` only overrode the
legacy `CACHE_DRIVER`. The isolated runtime `.env` therefore made the test
suite use file-backed cache, leaking rate-limit state between tests. The
runtime registration flags also needed explicit test values so registration
protocol tests did not inherit development settings.

This is a test-only correction. `phpunit.xml` now explicitly sets
`CACHE_STORE=array`, `REGISTRATION_ENABLED=false` and
`REGISTRATION_ALLOW_FIRST_USER=true`, while retaining the legacy cache
override for compatibility. No application, persistence, API or production
runtime behavior changed.

### Verification and handoff

- The focused isolation, account, registration, recipe-notification,
  authentication, two-factor, access-request, sign-in-history and
  troubleshooting set passed **125 tests / 902 assertions**.
- The complete strict PHP suite passed **1,543 tests / 12,959 assertions**
  with no errors, failures, risky tests or deprecations.
- Commit `564157b` (`test: isolate Laravel testing cache settings`) was pushed
  to `origin/main`.
- The next task remains the separately authorized external acceptance drill:
  enter valid DigitalOcean Spaces S3 credentials in the isolated dev
  application, then run backup, restore, restored-data comparison,
  post-restore health verification and cleanup. The control-plane token is
  not a Spaces access-key/secret-key pair.

## External provider acceptance attempt — 2026-09-16 (third run)

### Scope and lifecycle evidence

The separately authorized disposable DigitalOcean drill resumed in the
isolated main runtime with the smallest available droplet size
`s-1vcpu-512mb-10gb` and the existing maximum total spend of `$10`. The
pre-existing provider droplet was inventoried and left untouched. The run
started at `2026-09-16T19:14:52Z`; the disposable provider identifier was
`601159938` in `nyc1` and its address was `157.245.139.91`.

The controlled fixture repository `natecorkish/Deployer-Test` received and
published revisions `9d6fe6b` (`v6`) and `7f72430` (`v7`). A disposable
BuildPusher project/environment was created before the audit-eligible build
chain, preserving the earlier build history instead of rewriting it:

- Build `22` redeployed the retained `v5` revision
  `393772b29709449bb1f5b7aa6a80c1801f45cbe8` into environment `12`.
- Build `23` deployed the distinct `v7` revision
  `7f724303d4f1a3dcdff3b4ec93107a00f6142fd9` into that environment.
- Build `24` completed a rollback linked to build `22` and restored the
  exact `v5` revision and retained release.

Cloud provisioning completed at stage 12, website provisioning completed at
stage 3, and each deployment completed at stage 15. Direct HTTP checks served
`hello world v5`, then `hello world v7`, then `hello world v5` again, each with
HTTP 200.

### Backup outcome

The encrypted Spaces credential fields were populated in the destination,
but both destination probes reached DigitalOcean Spaces and failed during
Restic repository initialization with `Access Denied`. This indicates that
the stored pair is mismatched or lacks the required bucket permissions; it
does not establish a host or HTTPS endpoint failure. `last_verified_at` stayed
null, no `WebsiteBackup` or `BackupRestore` record was created, and no restored
data or post-restore health claim is made.

The audit was captured before cleanup with:

```text
php artisan buildpusher:acceptance:audit 7 --provider=digitalocean --since=2026-09-16T19:14:52Z --json
```

It returned `incomplete`: cloud provisioning, website provisioning,
two-revision deployment and rollback passed; offsite backup, restore drill
and post-restore health verification were missing. This is recorded lifecycle
evidence only and does not claim release acceptance.

### Cleanup and exact next task

The supported server deletion action completed successfully. An independent
provider lookup confirmed identifier `601159938` absent while the unrelated
provider droplet remained. Disposable server, website, repository, project
and environment records were removed; the user-configured backup destination
was preserved; the queue is empty; and the dev web/worker services still
serve the domain successfully.

The next task is to replace the destination through the dev UI with a valid
DigitalOcean Spaces S3 access-key/secret-key pair that has the required
read/write/delete access to `builder-backup`, then repeat the bounded drill
from the start (creating the project/environment before deployment), capture
the backup, exact restore, restored-data comparison, post-restore health and
audit, and clean up before claiming completion. Credentials must not be
posted in chat. This external attempt produced no backup spend or release
acceptance pass.

## Additional Spaces verification retry — 2026-09-16

The destination was retried from a fresh disposable DigitalOcean host using
the smallest authorized size, `s-1vcpu-512mb-10gb`, in `nyc1`. The provider
identifier was `601169375`. Its initial SSH identity scan ran before the host
was ready; the existing remote-provisioning retry action then resumed from
stage 3 and completed provisioning at stage 12. A disposable website record
was created on that active host solely to provide the supported destination
test entry point.

Restic again reached `https://lon1.digitaloceanspaces.com` and failed while
initializing `builder-backup` with `Access Denied`. The destination remained
unverified (`last_verified_at` is null), and no backup, restore or
post-restore health evidence was created. This is consistent with an invalid
Spaces access-key pair or insufficient bucket permissions; it is not evidence
of a host or endpoint reachability failure.

The disposable server was deleted through `DeleteServerAction`, an
independent provider lookup confirmed identifier `601169375` was absent, and
the unrelated provider droplet remained. The disposable website was removed
with the server, the stale queued website job was consumed after restarting
the isolated worker that had reached its configured max runtime, and the
backup destination was preserved. This retry does not establish cloud
release acceptance. The next task remains replacing the stored pair through
the dev UI with a Spaces S3 key that has read/write/delete access to
`builder-backup`, then repeating backup, exact restore, restored-data
comparison and post-restore health verification.

## Serverless backup destination verification — 2026-09-16

### Responsibility problem and boundary

The previous destination check coupled storage credential verification to an
active managed website, its server connection and remote Restic installation.
That made a storage setup check unavailable until unrelated infrastructure had
already been provisioned, and the check also initialized a real Restic
repository as a side effect.

Commit `0ab3365` (`feat: verify backup destinations without servers`) moves the
storage-specific responsibility into `S3CompatibleStorageProbe`. It creates a
0600 local temporary file, sends its marker through signed HTTPS S3-compatible
`PUT`, `GET` and `DELETE` requests under a generated connection-test key, and
removes the local file in all outcomes. `TestBackupDestinationAction` remains
responsible for recording verification state and sanitized errors. The
controller, request and policy retain the existing authorization and response
boundary; the page no longer asks for a website or server.

### Preserved contracts and intentional change

- Existing route names, redirect responses, flash messages, authorization,
  encrypted credential fields and `last_verified_at`/`last_error` state are
  unchanged.
- Failed probes attempt remote cleanup after a successful write and never
  include response bodies or credential material in the recorded error.
- Real backup and restore jobs still use the existing per-website Restic
  repository on a managed server; this change only removes that dependency
  from connection verification. The first actual backup remains responsible
  for initializing its encrypted repository.
- No schema, API, YAML or queued-job serialization contract changed.

### Verification evidence

- Destination-focused coverage: **9 passed / 43 assertions**.
- Related backup, restore, recovery-evidence and acceptance coverage after the
  final hardening: **28 passed / 241 assertions**.
- Exact committed-tree strict PHP suite: **1,544 passed / 12,956 assertions**
  with no errors, failures, risky tests or deprecations.
- Full Pint, changed-file PHP lint and `git diff --check`: passed.
- The isolated dev destination probe reached the configured Spaces endpoint
  directly without selecting a website or server and returned a sanitized
  HTTP 403. The destination remains unverified; no backup or restore evidence
  is claimed because the stored Spaces pair still lacks valid access or
  permissions.

### Commit and exact next task

Implementation commit `0ab3365` was pushed to `origin/main`. The next task is
to enter a valid Spaces S3 access-key/secret-key pair with read/write/delete
access to the configured bucket through the dev UI, then run the real backup,
exact restore, restored-data comparison, post-restore health check and cleanup
drill. That external acceptance remains separate from this locally verified
serverless connection check.

## Spaces 403 diagnostic — 2026-09-16

### Finding

The real isolated probe was repeated after the serverless verification change.
Spaces returned the bounded XML code `InvalidAccessKeyId` with HTTP 403:
the access-key value currently stored on destination `2` is not recognized by
the Spaces endpoint. This is not an active-server, network or temporary-file
failure. If the control panel shows a valid key, the dev destination needs to
be updated with that exact current key and its matching secret; a regenerated
or revoked key, a regular DigitalOcean control-plane token, or a key from a
different account will produce this result.

The connected DigitalOcean control-plane credential was also checked with the
read-only Spaces-key listing endpoint, but it returned HTTP 401, so no
account-side key comparison was made and no credential was changed.

### Diagnostic boundary and verification

Commit `8e40ede` (`fix: expose safe backup provider errors`) now extracts only
the provider's bounded error code into the existing sanitized destination
error. Response bodies and all credential material remain excluded. The UI
therefore distinguishes `InvalidAccessKeyId`, `SignatureDoesNotMatch`,
`AccessDenied` and other provider outcomes without exposing XML responses.

- Destination-focused coverage: **9 passed / 43 assertions**.
- Exact committed-tree strict PHP suite: **1,544 passed / 12,956 assertions**
  with no errors, failures, risky tests or deprecations.
- Full Pint, changed-file PHP lint and `git diff --check`: passed.
- Commit `8e40ede` was pushed to `origin/main`.

### Exact next task

Save the current Spaces access key and matching secret together in the dev
destination, with Read/Write/Delete object permission for `builder-backup`,
then run Verify again. Do not post either value in chat. A successful check
will write, read and delete only its generated temporary object; real backup,
restore and cloud acceptance remain outstanding until those workflows pass.

## Successful serverless Spaces verification — 2026-09-16

After the destination was updated in the dev application, the final retry
completed successfully against `https://lon1.digitaloceanspaces.com` and the
`builder-backup` bucket. The direct application-host probe wrote, read and
deleted its generated temporary object without selecting or contacting a
website server. Destination `2` now has a populated `last_verified_at` and a
null `last_error`.

This confirms the new verification path works with the currently stored Spaces
pair. The preceding `InvalidAccessKeyId` result came from the older value that
was stored at that time; no credential material was printed or recorded. No
actual website backup, restore or post-restore health evidence has been
created yet. The next task is the separately authorized real backup and
recovery drill, which may use its existing managed website only for the actual
Restic workflow—not for connection verification.

## External provider acceptance — 2026-09-16 (successful backup and recovery drill)

### Responsibility boundary and scope

The remaining external acceptance slice exercised the existing provider,
website, deployment, rollback, backup, restore and health operations together
on an isolated `main` runtime. The runtime used its own SQLite database,
storage, cache, application key, dependencies and database queue worker. The
worker was configured with the asynchronous database queue before provisioning
so server initialization retained its normal retry and lease behavior. No
production credentials, infrastructure or the separate acceptance-drill
checkout were used.

The authorized run started at `2026-09-16T21:14:48Z` with the smallest
DigitalOcean droplet size, `s-1vcpu-512mb-10gb`, in `nyc1`, under the existing
`$10` maximum total-spend limit. The pre-existing `Codex` droplet was inventoried
and left untouched. The disposable provider identifier was `601196607`; it was
removed before this record was written.

### Deployment and rollback evidence

The controlled fixture `natecorkish/Deployer-Test` was used through the
connected GitHub provider. The clean release chain was:

- Build `27`, revision `5e61e1c69016b179f49d25c4f4ae2010738b06ad`, served
  `hello world v8` with HTTP 200.
- Build `28`, revision `375d556fa50e4f76b880f59f075995bf554036a8`, served
  `hello world v9` with HTTP 200.
- Build `29` rolled back to build `27` and restored `hello world v8` with
  HTTP 200.

An intermediate successful build changed an unused repository-root fixture
file. It was not counted as release evidence; the fixture was corrected to its
actual `public/index.php` document root before the clean v8/v9/rollback chain.
This preserves the distinction between recorded deployment success and the
served application result.

### Backup, restore and health evidence

Destination `2` was serverlessly reverified against the configured Spaces
endpoint before the actual backup. Backup `3` completed over HTTPS. A second
backup, `4`, captured a disposable marker in both shared storage and the
website database. After both values were changed, restore request `1` applied
the exact snapshot from backup `4` successfully. Independent checks confirmed
that both markers returned to their pre-backup values. A separate post-restore
health check returned HTTP 200 and persisted a healthy result.

The bounded audit was captured before cleanup:

```text
php artisan buildpusher:acceptance:audit 8 --provider=digitalocean --since=2026-09-16T21:14:48Z --json
```

It returned `passed` for all seven recorded checks: cloud provisioning,
website provisioning, deployment, rollback, offsite backup, restore drill and
health verification. The audit remains a lifecycle record; the independent
restored-data comparison above supplies the data-integrity evidence.

### Cleanup and result

The two drill snapshots were forgotten and Restic subsequently reported zero
snapshots and zero raw data after pruning. The supported server deletion
workflow removed the disposable cloud server, website, repository, project
and environment records. Independent verification found provider identifier
`601196607` absent while the pre-existing `Codex` droplet remained active. The
configured Spaces destination was preserved, the queue had no pending jobs,
and `https://buildpusher.com/` continued to return HTTP 200.

The separate ListObjects diagnostic returned HTTP 403, so metadata cleanup
was unverified at this checkpoint. The September 17 follow-up below successfully
listed and removed the three remaining metadata objects with the existing key;
the earlier attribution to key permissions was unproven. No broader bucket
deletion was attempted. No credentials or raw remote output were recorded.

This establishes one disposable provider deployment, rollback, backup, exact
restore, restored-data comparison, health and cleanup cycle. It does not
establish production acceptance, billing behavior, multi-provider behavior,
provider-backed preview-stack readiness, PostgreSQL/Valkey recovery or
independent monitoring destinations.

### Commit and exact next task

This evidence-only update requires no application-code change. After the
verification record is committed and pushed, the next task is release-gate
review: keep production mail, independent monitoring/heartbeat destinations,
GitHub App configuration, billing/SSO acceptance and provider-backed preview
acceptance explicitly separate from this successful disposable drill.

## Final release-gate review — 2026-09-16

### Scope and responsibility boundary

This review verifies the integrated `main` tree after the local Phase 9 work
and the disposable provider drill. It makes no application-code or dependency
change. The isolated runtime remained separate from the canonical live
checkout, with its own SQLite database, storage, cache, application key,
dependencies, route/config/view caches and database queue worker.

The initial aggregate browser invocation was intentionally discarded because
its fixture hook omitted `BROWSER_PHP_BINARY` and selected system PHP 8.3.6.
The required-PHP asset suite had already passed independently; the complete
browser run was then repeated with PHP 8.5.10 explicitly configured.

### Verification evidence

- The strict PHP suite passed **1,544 tests / 12,956 assertions** with no
  failures, errors, risky tests or deprecations.
- Required-PHP Pint, Composer manifest validation, Composer platform checks
  and `git diff --check` passed. Composer emitted only deprecation notices
  from the system Composer libraries; lockfiles were unchanged.
- The required-PHP asset fixture suite passed **9 tests**, including all light
  and dark 320/390/768/1440 layouts and no-JavaScript provider submission.
- The complete browser suite passed **16 tests**: accessibility at mobile,
  tablet and desktop sizes; all asset fixtures; no-JavaScript provider
  submission; served Livewire runtime; and mobile/tablet/desktop product-page
  crawls.
- After creating route, configuration and view caches and restarting only the
  isolated services, the served Livewire/mobile smoke passed **1 test**. The
  isolated web and worker services were active and `https://buildpusher.com/`
  returned HTTP 200. The runtime resolved to the isolated SQLite database,
  file cache, database queue and isolated storage path.

### Result and exact next task

The local implementation and the authorized disposable provider evidence are
complete. No additional local feature slice is justified by the current
evidence. Remaining release gates are production mail, independent
monitoring/heartbeat destinations, GitHub App configuration, billing/SSO,
provider-backed preview readiness, PostgreSQL/Valkey recovery and other
provider-specific acceptance. Spaces metadata cleanup was unverified at this
checkpoint and was subsequently completed on September 17 for the exact drill
prefix, as recorded below; no broader bucket deletion was attempted.

Documentation commit `5e76bf0` was pushed to `origin/main`, and canonical
`main` was fast-forwarded to the same commit. The exact next task is separately
authorized release-gate acceptance when those integrations and credentials are
available; it is not a local-test completion claim.

## Preview PostgreSQL cleanup correction — 2026-09-17

Continuing preview acceptance exposed a remote execution error: database and
role deletion shared one `psql --command`, so PostgreSQL rejected
`DROP DATABASE` inside the implicit transaction. Repeated command options
correct the SQL boundary within the existing script service. The queue/action
responsibilities, owned target identities, error propagation, retries, leases,
authorization and public contracts are preserved.

The fresh focused baseline passed 13 tests / 136 assertions. Three new Bash
execution regressions failed against the old generator, then the focused
cleanup/readiness/template batch passed **16 tests / 142 assertions** after
the fix. A disposable PostgreSQL 16.15 cluster independently reproduced the
original failure and verified successful deletion, idempotency, shared-data
preservation, partial failure/retry and non-owner denial. All test databases
and roles were removed and the temporary server was stopped.

The full strict PHP suite passed **1,547 tests / 12,962 assertions**; full
Pint, PHP syntax and diff checks passed. The complete isolation, commands,
tooling cleanup and limits are in
[the verification record](preview-postgresql-cleanup-2026-09-17.md).
Bug-fix commit `70d7c78` was pushed to `origin/main` and fast-forwarded into
canonical `main` and the dev runtime. The exact next task was the isolated
dev worker's normal-exit restart correction, recorded below.

## Isolated dev worker restart correction — 2026-09-17

The worker exited normally after `--max-time=3600`, but its isolated service
used `Restart=on-failure` and stayed stopped. The queue was empty. The local
unit now matches the repository installer's existing `Restart=always`
contract. Unit validation passed; a real Laravel queue-restart signal caused
exit status zero and automatic replacement after three seconds, with
`NRestarts=1` and the service active/running.

This runtime-only correction and its verification are recorded in
[the runtime record](dev-worker-restart-2026-09-17.md). Source application code,
queue semantics and credentials did not change. The prior complete strict
suite remains 1,547 tests / 12,962 assertions. The exact next task is to
recheck the earlier drill's exact disposable Spaces repository prefix, then
resume preview-stack and recovery acceptance. This runtime record was committed
and pushed as `7e461a5`, then integrated into canonical `main` and the dev runtime.

## Disposable Spaces metadata cleanup — 2026-09-17

A prefix-scoped ListObjectsV2 request using libcurl signing and the existing
dev credentials returned HTTP 200. The earlier diagnostic's 403 did not prove
incorrect credentials or missing permissions; its cause remains undetermined.
The exact disposable prefix `buildpusher/websites/10/` contained three metadata
objects totaling 825 bytes, with no snapshot, data or lock objects. Their
timestamps matched the September 16 drill and the website was already absent.

A fresh inventory had to match the three exact keys, sizes and timestamps
before deletion. Each explicit deletion returned HTTP 204; a subsequent
non-truncated listing returned HTTP 200 and zero current objects. No broader
prefix, configured destination, credentials or server was changed. Historical
versions and multipart uploads were not inspected or purged, and no recovery
copy of the empty metadata was retained.

See [the evidence record](spaces-drill-cleanup-2026-09-17.md). This slice changes
documentation only; the application remains covered by the strict 1,547-test /
12,962-assertion run and full Pint. After this record is committed and pushed,
the exact next task is preview-stack acceptance, including PostgreSQL/Valkey
readiness and cleanup, distinct from the completed generic recovery drill.
This evidence was committed and pushed as `c85fc77`, then integrated into
canonical `main` and the dev runtime.

## Managed Valkey start failure propagation — 2026-09-17

The existing resource script ignored an existing container's failed start and
continued to its success callback. Removing that suppression preserves the
existing command-rendering boundary and makes the failure use the deployment's
existing fail-fast path. No resource identity, credential, callback number,
schema, serialized job or successful-operation behavior changed.

The fresh focused baseline passed 16 tests / 171 assertions. A new executable
Bash test failed on the old existing-container failure path; all four new and
existing-container success/failure cases pass after correction. The related
resource/configuration/preview/template batch passed **31 tests / 261
assertions**. The full strict PHP suite passed **1,551 tests / 12,974
assertions**, with full Pint, syntax, lockfile and diff checks passing.
See [the verification record](managed-valkey-start-2026-09-17.md).

After this cohesive fix is committed and pushed, the exact next task is to
prepare managed resources before first-deployment Laravel migrations without
renumbering persisted callback stages. Separate the shared command-rendering
extraction from the intentional timing fix. Full configuration/preview and
recovery acceptance still follows; no cloud host was created for this slice.

Valkey correction `14e3bf5` was committed, pushed and integrated into canonical
`main` and the dev runtime; the dev worker recycled successfully afterward.

## Managed-resource command renderer — 2026-09-17

First-deployment review found that migrations precede managed-resource
creation. The preparatory extraction separates snapshot-based resource command
rendering into an injected `ManagedResourceScript`, while the existing
`ConfigureResourcesScript` retains stage reporting. It changes no timing and
preserves the 15-stage protocol. This applies single responsibility and explicit
constructor collaboration to a concrete reuse requirement, without adding an
interface or generic orchestration layer.

Five frozen-time script comparisons are byte-identical; the relevant execution,
resource-safety, preview and provisioning suites passed **36 tests / 391
assertions**. Changed-file Pint and diff checks passed. The preceding full
baseline remains 1,551 tests / 12,974 assertions. See
[the verification record](managed-resource-preparation-2026-09-17.md).

Commit/push this pure extraction before adding the first-deployment regression
and early resource preparation. The exact next task is that separate timing fix;
configuration/preview provider acceptance remains outstanding.

Extraction commit `b3070a3` was pushed and integrated before the timing fix.

## First-deployment resource preparation — 2026-09-17

`InstallDependenciesScript` now uses the shared injected renderer to prepare
captured managed resources before dependency hooks, runtime build commands and
Laravel migrations. Stage 11 still reconciles the same identities and emits its
original callback; the plan, callback validation, build model and serialized
job formats are unchanged. This intentionally advances remote creation, so a
later application failure may leave resources for identity-preserving retry or
the existing ownership-aware preview cleanup. External resources stay excluded.

Three new executable cases failed before the correction; all four managed,
legacy, external and failure-path cases pass afterward. The related deployment,
resource, callback, approval, health, preview, monorepo and timeline batch passed
**71 tests / 659 assertions**. The complete strict PHP suite passed **1,555
tests / 12,986 assertions**, with no failures, errors or skips. Full Pint,
syntax, manifest validation, locked-platform checks and diff checks passed;
only the system Composer's existing deprecation notices remain.

A disposable PostgreSQL 16.15 cluster executed the generated creation and
cleanup commands. Dependency and migration doubles connected to the real
database and wrote a marker after creation; the sequence passed twice with
callbacks 4/7/11 and shared data preserved. Exact preview cleanup passed, all
smoke-test data was removed, and the cluster was stopped. This is not a complete
Laravel deployment, Valkey protocol/persistence test or cloud acceptance claim.

See [the verification record](managed-resource-preparation-2026-09-17.md) and
the updated operator contract. Timing fix `f3cd675` was pushed and integrated
into canonical `main` and the dev runtime. The exact next task is configuration-specific provider
review/apply/delivery/idempotency/recovery acceptance, followed by the complete
preview stack. No new cloud resource was created in this continuation.

## Publication checkpoint — 2026-09-17

Every cohesive slice below was committed and pushed before advancing:

| Commit | Result |
| --- | --- |
| `70d7c78` | PostgreSQL cleanup uses separate database/role requests; real SQL and failure/retry checks pass. |
| `7e461a5` | Dev worker normal-exit restart recovery is documented and verified. |
| `c85fc77` | Exact Spaces drill metadata cleanup is verified; earlier permission attribution is corrected. |
| `14e3bf5` | Failed managed Valkey starts stop before a successful resource callback. |
| `b3070a3` | Shared managed-resource renderer preserves byte-identical stage output. |
| `f3cd675` | Resources are prepared before first-deployment hooks and migrations, without callback renumbering. |

The latest application commit is integrated in both canonical `main` and the
isolated dev runtime. Full strict PHP verification passes **1,555 tests /
12,986 assertions**; full Pint and dependency/platform/diff checks pass, with
the system Composer's existing deprecation notices recorded. A fresh dev smoke
check found both services active, `NRestarts=3` on the automatically recycled
worker, zero pending jobs and HTTP 200 for the homepage and its actual rendered
CSS/JavaScript assets. The runtime still resolves its isolated dev SQLite,
storage, file cache and database queue. The canonical checkout's untracked
controller modernization plan remains untouched.

The exact next task is the documented configuration-specific disposable
provider acceptance sequence, then the complete preview stack and recovery
cycle. Use the already authorized fixture/provider scope and spending limit;
do not interpret the local SQL or Bash checks as complete cloud acceptance.
Production mail, independent monitoring, GitHub App, billing/SSO and other
separately scoped release gates remain outstanding. No new droplet was created
in this continuation, and the separate acceptance-drill checkout was not changed.

## Configuration-specific provider acceptance — 2026-09-17

The isolated dev runtime completed the configuration-as-code acceptance
sequence against the authorized DigitalOcean and GitHub provider connections.
The disposable resources were created through the normal application actions,
not direct provider-only setup, and were removed through the supported cleanup
workflow before this record was written. No credential material or secret
values were printed or persisted in this record.

The complete evidence, including exact request shapes, response statuses,
operation/build identifiers and cleanup checks, is in
[configuration-acceptance-2026-09-17.md](configuration-acceptance-2026-09-17.md).

### Responsibility boundary and preserved contracts

- The API Form Requests continued to validate the versioned YAML document and
  binding arrays; the existing planner and reconciler produced the changes;
  the configuration application/review services retained ownership,
  freshness, lease, atomic-claim and no-op behavior; and the existing delivery
  and result services continued to dispatch and reconcile builds.
- Policies and the `control-plane:manage` middleware remained the authorization
  boundary. A foreign workspace placement was rejected with the existing
  generic 422 response before any application, operation, remote resource or
  secret side effect.
- The exact review input was revalidated after source-secret rotation. The old
  review was rejected without a new application or operation, and the rotated
  value was absent from the response.
- Reapplying the same review returned the same application and operation. A new
  unchanged review reused the existing deployment intent and did not create a
  duplicate secret version or remote deployment.
- Approval, idempotent cancellation, explicit retry, stale failure handling and
  terminal result refresh retained their existing statuses and response
  envelopes. A controlled Caddy outage produced a real health-check failure;
  no automatic replacement was created. Restoring Caddy and explicitly
  retrying produced one replacement that succeeded; repeating retry returned
  that same replacement.
- Removing the staging environment planned four local changes and marked
  remote data/services as unchanged. Apply removed the local environment,
  process, resource, variable and ownership rows while preserving the
  independently owned website, repository, server and remote data until the
  separate disposable cleanup step.

### Evidence summary

- Project `9`, disposable staging environment `15`, website `11`, repository
  `9`, server `16` and DigitalOcean droplet `601315670` were used only for this
  run. The source fixture revision was
  `375d556fa50e4f76b880f59f075995bf554036a8`.
- The initial plan returned HTTP 200 with five changes and no mutation.
  Review `1` applied as application `1`/operation `1`; build `30` succeeded
  and the real HTTPS fixture response was `hello world v9` (SHA-256
  `4c8a16eb64c35d6e94867485acedd39130ff69e2ea17e6ab95917862841bb090`).
- Same-review apply was idempotent. Unchanged review `2` created application
  `2` but reused operation `1`; no additional build or secret version was
  created. Freshness review `3` returned HTTP 422 after source-secret
  rotation and produced no side effects.
- Review `4` created application `3`/operation `2`. Approval blocking was
  observed; two cancellation requests left the operation/build canceled with
  no remote process. Explicit retry returned operation `3`/build `32`; a
  repeated retry returned the same replacement, which succeeded.
- Review `6` created application `5`/operation `5`. With Caddy stopped only on
  the disposable host, build `34` failed at the final health check with the
  sanitized message `Deployment health check failed (exit code 1)`. Result
  refresh marked the operation failed and application `remote_failed` without
  an automatic replacement. Explicit retry returned operation `6`/build `35`
  on both first and repeated requests; after Caddy was restored, build `35`
  and the application succeeded at `2026-09-17T07:09:17Z`.
- Removal review `7` applied as local-only application `6` with no operations.
  Reapplying it returned application `6`; a new absent-environment review `8`
  applied as local-only application `7` with no operations.
- The website cleanup job completed, the repository and website records were
  removed, and the server deletion action removed the cloud server and owned
  SSH key. DigitalOcean inventory returned no matching droplet or acceptance
  SSH key. Project deletion then removed the disposable project and its
  source-environment records. The queue was empty after cleanup; two unrelated
  historical failed jobs remained in the isolated dev database.

### Scope and limitations

This verifies configuration-specific provider behavior and cleanup. It does
not claim that the same run completed the broader preview-stack lifecycle,
managed PostgreSQL/Valkey provider readiness, the generic release/rollback/
backup/restore drill, production acceptance or the separate live acceptance
drill. Those remain distinct release gates. The existing full strict local
suite remains **1,555 tests / 12,986 assertions**, with full Pint and
dependency/platform checks recorded in the preceding verification entries.

The exact next task is provider-backed preview-stack acceptance using the
already authorized disposable scope, followed by its cleanup and a separate
record of any provider-specific limitations.

## Provider-backed preview-stack acceptance — 2026-09-17

The representative Laravel preview stack passed provider-backed acceptance on
the isolated dev runtime. The run used the normal signed repository webhook,
preview lifecycle, deployment, managed-resource readiness and cleanup paths
with the authorized DigitalOcean/GitHub connections. It verified that preview
configuration does not copy source secrets, that generated PostgreSQL and
Valkey credentials are independent, and that queue, scheduler, PostgreSQL and
Valkey resources become ready before the application is considered healthy.

The fixture exercised an initial open, a revision update, an intentional HTTP
503 health failure, restoration to the previous release without a manual
PHP-FPM reload, a healthy retry, close and reopen. The reopen cycle exposed
and then verified the fix for stale environment-slug collision: commit
`c582dd8` creates the next unused preview slug so a later cleanup record cannot
target a newly reopened stack. Commit `3895a31` refreshes the configured
PHP-FPM service after failed-release restoration so opcache does not continue
serving the failed candidate.

Both cleanup generations removed their owned process units, Valkey containers
and volumes, PostgreSQL databases and roles, deployment directories and queue
work. The disposable server and project were then removed through the normal
application actions, and provider inventory contained no remaining acceptance
droplet. Full non-secret evidence is in
[the dedicated acceptance record](preview-stack-acceptance-2026-09-17.md).

Focused deployment/health checks passed **14 tests / 119 assertions** and
preview/cleanup/readiness checks passed **35 tests / 337 assertions**. The
fresh complete strict PHP suite passed **1,556 tests / 13,004 assertions** with
no failures, warnings, risky tests or deprecations. Required-PHP Pint,
Composer validation/platform checks, the asset build and `git diff --check`
passed. The rebuilt asset/layout browser suite passed **9 tests**, and the
served Livewire/mobile smoke passed **1 test**; the isolated dev service and
worker were active and the homepage plus both rendered assets returned HTTP
200.

This completes the local cross-feature verification and the representative
provider-backed acceptance scope. The exact next task is release handoff and
separately authorized external acceptance. Production deployment, all-provider
parity, independent monitoring/heartbeat destinations, GitHub App
configuration, billing/SSO, deeper PostgreSQL/Valkey recovery and the live
acceptance drill remain outstanding.

## Dev runtime navigation deployment — 2026-09-17

The isolated dev runtime serving `https://buildpusher.com` was fast-forwarded
on `main` to `1750222`, following the pushed implementation commit `215da0d`.
The runtime remains the local/dev Caddy target at `127.0.0.1:8010` with its
isolated SQLite database, storage, file cache and database queue. The canonical
checkout and separate acceptance-drill checkout were not changed.

Laravel config, route and view caches were rebuilt; the Vite production assets
were rebuilt; and the PHP 8.5.10 dev server was restarted with `--no-reload`.
The local runtime and `https://buildpusher.com/login` both returned HTTP 200.
Runtime navigation inspection confirmed the merged Applications, Template
library, Billing and usage, and Account and security entries, with no legacy
standalone Environments, Builds, Repositories, Recipes, Gallery, Billing,
Costs and usage or Settings entries. The authenticated browser journey against
the dev domain passed on mobile, tablet and desktop (**3 tests**).

This is dev-runtime deployment evidence only. It does not claim production
release or completion of the separate live acceptance drill. The exact next
task is separately authorized release handoff and external acceptance.
