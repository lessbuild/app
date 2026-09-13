# BuildPusher product expansion progress

Status: Phase 3D complete. Preview safety, trust/secret boundaries, responsive
navigation, first-deployment guidance, recorded configuration
authoring/comparison, explicit provider observations, a template-driven preview
stack manifest, callback-backed local resource readiness and retryable
ownership-aware cleanup and atomic concurrent-preview quotas are complete;
explicit initialization secrets and independent provider-readiness verification
remain incomplete.

Date: 2026-09-13

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
| Preview deployments | A pull-request webhook opens or updates a preview, provisions a website/repository/environment, queues the build, reports to GitHub, closes/expires and cleans up. Settings are managed from project preview routes. The core entry point is `PreviewDeploymentLifecycle`; the project preview action/request and repository webhook path complete the flow. | `PreviewDeployment`, `PreviewDeploymentLifecycle`, `PreviewEnvironmentConfiguration`, `PreviewTrustPolicy`, `UpdateProjectPreviewsAction`, preview settings request, `ProjectPolicy`, `AddWebsiteJob`, `ReportGitHubPreviewJob`, `DeploymentRequest`, `PlanLimits`, `Entitlements`, `PreviewStackCatalog`, `ConfigurePreviewStackAction`, `QueuePreviewStackCleanupAction`, `CleanupPreviewStackJob` and `PreviewStackCleanupScript`; `PreviewDeploymentTest` covers open/update/close, settings, entitlement ordering, source-secret exclusion, legacy-preview sanitization, provider trust metadata, idempotent child declarations, cleanup/retry behavior and capacity release. `PreviewDeploymentConcurrencyTest` covers the last-slot race. | Existing with completed configuration, trust, local readiness, ownership-aware cleanup and atomic quota boundaries, with remaining lifecycle gaps. New and revised previews receive explicit preview-owned configuration instead of copied source environment text. Updated/reopened code execution is limited to the configured target branch and configured target repository; forks and unknown trust metadata are denied. Supported Laravel presets persist queue/scheduler process declarations and planned managed PostgreSQL/Valkey children, while unsupported presets remain unchanged. Explicit remote initialization/secrets and independent provider-readiness verification remain outstanding. | Phases 1 and 3A–3D establish safe configuration, trust/secret approval, navigation, first-deployment guidance, a template-driven local stack manifest, callback-backed resource states, durable exact-identity cleanup and transactionally serialized concurrent-preview quotas. Continue with explicit initialization/secrets and provider-readiness evidence. Completion still requires open/update/fail/retry/close/reopen/expire lifecycle tests, independent provider evidence and separate cloud acceptance. |
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
4. **Phase 3 — complete preview environments.** Phases 3A through 3D now
   declare the representative Laravel worker/scheduler/PostgreSQL/Valkey stack,
   record callback-backed planned/provisioning/ready/failed resource states and
   capture exact owned identities for retryable close/expiry cleanup and
   transactionally serialize concurrent-preview quotas. Continue with explicit
   initialization secrets and independent provider-readiness evidence;
   completion still requires full-stack lifecycle and cloud evidence.
5. **Phase 4 — curated service templates.** Add only supported, versioned
   templates with installation, readiness, upgrade, restore and deletion evidence.
6. **Phase 5 — deployment clarity and monorepo support.** Improve timeline and
   change-impact visibility while preserving existing deployment strategies.
7. **Phase 6 — verified backup recovery.** Separate backup completion from
   verified application/control-plane recovery and make cleanup visible.
8. **Phase 7 — connected observability and troubleshooting.** Connect deployment,
   environment, log, health and incident context with bounded queries.
9. **Phase 8 — interactive troubleshooting.** Design and implement sessions only
   after structured diagnostics and the host transport/security model are clear.
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
task is Phase 3E: define explicit preview initialization and secret/resource
credential boundaries, then characterize independent provider-readiness
evidence.

## Slice ledger

| Slice | Problem and boundary | Tests/evidence | Commit | Push status | Exact next task |
| --- | --- | --- | --- | --- | --- |
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
