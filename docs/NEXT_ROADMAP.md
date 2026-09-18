# Next development sequence

Reviewed 2026-09-17. Work one item at a time; passing a narrow test does not establish completion of a whole workflow. The original roadmap checkmarks describe existing implementation, not demonstrated production parity.

## Current acceptance checkpoint — 2026-09-17

The configuration-specific provider acceptance sequence and the representative
provider-backed Laravel preview-stack sequence are complete on the isolated dev
runtime. The combined evidence covers plan/review/apply, real delivery,
idempotency, secret freshness, workspace ownership, approval/cancellation,
explicit retry, reversible health failure, independent preview credentials,
managed PostgreSQL/Valkey readiness, revision updates, close/reopen generation
isolation and exact cleanup. See
[configuration acceptance](verification/configuration-acceptance-2026-09-17.md)
and [preview-stack acceptance](verification/preview-stack-acceptance-2026-09-17.md).
The final local cross-feature verification is now complete: the strict PHP
suite passed **1,559 tests / 12,864 assertions**, required-PHP Pint and
Composer checks passed, the assets rebuilt successfully, the asset/layout
browser suite passed **9 tests**, and the served Livewire/mobile smoke passed
**1 test**. The next task is separately authorized release handoff and
external acceptance. This remains separate from broader provider-specific
recovery, production/live acceptance, independent monitoring, GitHub App
configuration and billing/SSO. The subsequent flat mobile-navigation
restoration is also pushed on `main` and passed its authenticated
mobile/tablet/desktop browser journey (**3 tests**).

## Competitor comparison

These are comparisons of documented capabilities against inspected source, not hands-on competitor benchmarks or claims about pricing.

| Reference | Documented capability | BuildPusher evidence and opportunity |
| --- | --- | --- |
| [Render Blueprints](https://render.com/docs/blueprint-spec) | Declarative service and database configuration | `WorkflowConfiguration` updates schedules, scaling and processes on existing environments. Expand to reproducible application topology with validation and a reviewable change plan. |
| [Render previews](https://render.com/docs/preview-environments) | PR environments instantiate Blueprint services and datastores, support initialization and automatic cleanup; existing data is not copied | `PreviewDeploymentLifecycle` persists a template-driven Laravel queue/scheduler/PostgreSQL/Valkey stack manifest, signed deployment callbacks record planned/provisioning/ready/failed local resource status, an ownership-aware leased cleanup job captures exact preview identities for retryable close/expiry cleanup, organization-locked quota checks prevent concurrent over-allocation, curated initialization plus encrypted managed-Valkey credentials are explicit, and the existing manager-authorized observation can now show normalized provider server readiness. A representative DigitalOcean/GitHub Laravel cycle is provider-verified; broader provider combinations and production parity remain separate. |
| [Coolify service catalog](https://coolify.io/docs/services/overview) | Broad catalog of deployable services | `config/application-templates.php` supplies framework presets. Phase 4A versioned the Laravel contract and Phase 4B adds the Node preset with the existing managed PostgreSQL/Valkey lifecycle; a broader catalog still needs lifecycle-backed installation, upgrades and recovery support. |
| [Laravel Forge](https://laravel.com/forge) | Laravel VPS offers shared interactive browser terminals | BuildPusher has queued command execution and retained output. Interactive sessions require additional lifecycle, access and disconnect handling. |

## Ordered implementation backlog

1. **Acceptance-audit correctness locally — implemented and verified.** Repository/website/server ownership and placement checks, rollback artifact identity, alternative rollback and backup selection, strict calendar-date validation, and per-backup HTTPS evidence are implemented. Regression coverage includes valid repeat drills, mixed-workspace records, mismatched artifacts, stale and out-of-order evidence, and destination re-verification. The command explicitly separates recorded lifecycle evidence from restored-data validation, real-provider provenance and cleanup. The acceptance, managed-backup and deployment-approval suites passed together (12 tests, 124 assertions). The nullable backup-evidence migration was applied successfully; historical backups were not backfilled. Live acceptance remains deferred below.
2. **Application configuration as code — implemented and locally verified; configuration-specific provider acceptance completed September 17.** Version 2 provides portable topology, strict parsing/bindings, read-only plans, exact reviewed apply, ownership/adoption, explicit child/environment removal, encrypted snapshots, durable deployment intents and explicit retry/cancel recovery. Fixture portability, rollback, web/API behavior, independent-process SQLite races and migration rollout/rollback are covered. The final application suite passed **1,060 tests / 10,179 assertions**, with production build and rendered UI checks passing. See [the verification record](verification/application-configuration-2026-09-06.md) and [operator contract](application-configuration.md). The live SQLite migration rollout and database-copy rehearsal completed September 8 with existing data preserved; readiness and the configuration-delivery timer pass. See [the rollout record](verification/configuration-rollout-2026-09-08.md). The configuration-specific provider sequence verified plan/review/apply, delivery, idempotency, freshness, ownership, approval/cancellation, retry, reversible failure/recovery, environment removal and disposable cleanup; see [the provider record](verification/configuration-acceptance-2026-09-17.md). The representative preview-stack provider cycle is recorded separately below.
3. **Complete preview environments — local implementation and representative provider acceptance complete.** Phases 3A through 3F add the local, template-driven Laravel queue/scheduler/PostgreSQL/Valkey stack manifest, callback-backed resource readiness states, exact-identity ownership-aware retryable cleanup, organization-locked concurrent-preview quotas, curated one-time initialization, encrypted managed-Valkey credential boundaries and normalized one-time provider-readiness observation. A disposable DigitalOcean/GitHub cycle also verified signed open/update/close/reopen behavior, independent preview credentials, managed-resource readiness, health failure/recovery and exact cleanup for the representative Laravel stack. Broader provider adapters, production acceptance and deeper PostgreSQL/Valkey recovery remain separate.
4. **Curated service templates — Phase 4 complete locally.** The Laravel and generic Node presets expose version `1.0.0` metadata for compatibility, generated credentials, persistent data, readiness, limits, backup/restore, upgrade, recovery and deletion, and new curated projects record the installed version. Node previews inherit the selected environment runtime and compose managed PostgreSQL/Valkey through the existing snapshot, readiness and cleanup paths. Lifecycle characterization confirms the shared installation/resource/cleanup boundary; template upgrades remain reviewed deployments. Mailpit and other additional services remain deferred until their complete lifecycle exists. Source tests do not imply live-provider verification.
5. **Deployment clarity and monorepo support — Phase 5B complete locally.** The build detail page has a bounded, plan-driven request/approval/provision/build/application-preparation/release/traffic/resource/health/finalization timeline plus exact revision, actor and configuration-operation evidence. Automatic push deployments support optional per-target include/exclude globs with bounded GitHub/GitLab changed paths, explicit skipped delivery history and conservative unknown-provider behavior. Repository targets can opt into a validated relative service root across the existing deployment, runtime, Caddy, logging, scheduled-task and restore paths; default and legacy behavior remains unchanged. A read-only impact-preview page now evaluates the same path rules across enabled workspace push targets and shows affected/unaffected/unknown results without writes, jobs or provider calls. Shared-dependency inference, multi-target orchestration and automatic cross-service coordination remain out of scope. The next item was verified backup recovery.
6. **Verified backup recovery — supported isolated verification implemented locally.** Managed website backup completion, per-backup HTTPS transport evidence, completed in-place restores and independent recovery verification are separate dashboard indicators. A manager can queue an exact-snapshot verification through a policy/request/action boundary; the post-commit job restores to a same-server temporary MySQL target, checks database/storage/.env integrity, runs a Laravel `artisan migrate:status` smoke check, records bounded failure-stage/duration/cleanup evidence and exposes retry after failure. The existing restore remains an in-place production workflow. The first slice supports Laravel/MySQL service roots with a stored managed-server root credential; PostgreSQL, external targets, arbitrary runtime smoke checks and scheduled restore drills remain outstanding. A disposable DigitalOcean deployment, rollback, HTTPS backup, exact restore, restored-data comparison, health check and cleanup cycle passed on September 16; production and broader provider acceptance remain separate. Phase 7A now adds a policy-authorized, bounded environment evidence context linking deployments, health observations, runtime-log metadata and explicitly related incidents. The next code item is bounded service/deployment and incident-severity filters.
7. **Connected observability and troubleshooting — Phase 7G named investigations complete locally.** The environment evidence context connects recent or active deployments, website health observations, metadata-only runtime snapshots and explicit incident relationships through a current-workspace policy, finite time windows and per-collection limits. It validates repository-service selection, active/successful/unsuccessful deployment groups and incident severity without hiding shared signals that cannot be safely attributed to one repository. Deployment incidents have a separate link to the existing policy-protected build detail, which exposes the revision, timeline, bounded log and configuration identity when available; the incident-centre path remains available for response history. Existing sensitive routes recheck authorization; encrypted log output, incident summaries and health errors remain excluded, and adjacent signals are labeled as evidence rather than causation. Existing notification saved filters were characterized and rejected as an observability store because they lack workspace/resource authorization, environment identity, expiry and retention semantics. The context now exposes a canonical URL generated only from normalized filters, excluding arbitrary query input and persistence. Operational incident grouping and source-monitor transition guards remain unchanged, while external alert payloads carry immutable non-secret `incident_id`, `incident_occurrences` and `dedup_key` metadata; PagerDuty uses the supplied key and legacy queued payloads retain the old fallback. Inbox delivery, external delivery frequency, retries and recovery behavior are unchanged. The extracted `WebsiteHealthProbe` now shares bounded remote execution and immutable results without changing periodic website history, thresholds or the immediate deployment probe. Successful deployments can now opt into a separate encrypted-payload, revision-bound observation aggregate through the existing monitoring entitlement; target identity is snapshotted before remote work, duplicate callbacks are idempotent and newer deployments supersede older active observations. A post-commit unique job now claims each observation with a short lease, probes outside database transactions and records only current revision/target results; bounded queue retries, minute-level due/expired-lease scheduling, terminal expiry/failure/supersession outcomes and stale-claim protection are covered. Build details expose only bounded observation metadata and continue to separate it from continuous website health. Named investigation views now add organization-owned, environment-bound, opaque-UUID handoff records with finite filters, current-resource revalidation, explicit 7/30/90-day expiry, creator/manager deletion and bounded scheduled retention. Opening a view redirects to the canonical context, so no evidence snapshot or bearer authorization is created. Provider/cloud acceptance remains separate.
8. **Interactive troubleshooting — structured diagnostics first.** The typed control-plane diagnostic report, fixed server-host diagnostic implementation, minimal persisted session authorization/lifecycle boundary, bounded server-side transport/process-ownership seam, durable encrypted frame relay, bounded broker ownership command, policy-authorized session lifecycle HTTP boundary, bounded frame transport routes and local daemon-supervisor wiring are complete locally; the exact legacy CLI/JSON/HTTP projection remains preserved. The fixed probe uses the pinned SSH identity, an application-owned command allowlist, bounded output/timeout, sanitized failure stages, latest-result retention and leased stale-attempt protection. The separate session boundary provides opaque hashed grants, connect/execute policy decisions, finite outcomes, bounded absolute/idle expiry, membership heartbeat revalidation, idempotent close/revoke and scheduled expiry without changing queued commands. The transport boundary provides an injected pinned-SSH adapter, bounded async local input/output, validated resize and idempotent local process/temporary-credential cleanup. Durable frames are encrypted, sequenced and independently bounded; input is marked sent before remote write, output is acknowledged by sequence, and exact lease/attempt/process guards fail closed on expiry or stale callbacks. The broker command runs a bounded supervisor-oriented polling window. The JSON routes create, heartbeat/status and close sessions, and now accept bounded input, output polling, output acknowledgment and resize requests with nested scoped binding, bearer revalidation, no-store responses and secret-safe metadata. The daemon installer now declares a UUID-addressed broker template with restart and control-group semantics plus a bounded 15-second reconciliation timer. A disposable local `sshd` exercise verified five sequential real-adapter sessions; a disposable Debian 12 LXD system container with systemd, OpenSSH and its own network namespace then verified the actual application broker through normal systemd stop, exact broker-PID loss/restart, interface-detach partition cleanup and new-session-only reconnect. The foreground `exec bash` PTY fix is pushed in `8ad2e52`. This is installed-host-equivalent local evidence, not a full VM or cloud/provider acceptance claim. The local lifecycle gate is complete; keep the browser terminal deliberately gated. Provider/cloud acceptance and the separate live drill remain outstanding.
9. **Resource usage and cost visibility — complete locally within the planned safe scope.** The cost page exposes an organization-scoped read model, records provider-catalog observation timestamps, distinguishes catalog estimates from measured CPU telemetry, states that provider billing is not connected, keeps unknown prices out of totals, shows exact active-preview quota usage and bounded lifetime/expiry projections, labels direct/shared/unallocated server-to-project evidence, and provides review-only cleanup signals. Dollar amounts remain at server level; no provider invoice import, guaranteed spending cap, automatic cleanup or cloud acceptance is claimed.

This ordering is an engineering judgment: close audit correctness first, then build configuration foundations before multiplying deployment options. It does not authorize paid infrastructure creation.

## Current checkpoint — 2026-09-14

The Phase 8 fixed server-host diagnostic implementation is complete locally in
feature commit `4add5b9`, the persisted troubleshooting-session lifecycle
boundary is complete in feature commit `6e9e55f` with wording correction
`335ea42`, and the bounded transport/process-ownership seam is complete in
feature commit `6013f19`; durable encrypted frames plus the bounded broker
ownership command are complete in feature commit `5b54f3b`. The server page
now exposes a policy-authorized,
asynchronous fixed probe with a pinned SSH identity, bounded scalar parser,
latest typed snapshot, lease/retry protection and stale-attempt guards; no raw
output or secrets are retained. The existing arbitrary command, metrics, logs
and provider paths remain separate. The Phase 7F environment-evidence
integration is complete locally in feature commit `0390030`. The bounded
context now includes safe, exact
revision/website-matched outcomes for explicitly requested post-deployment
observations, retains active observations outside the selected time window and
preserves the existing service filters, tenant authorization and secret-safe
read boundary. Phase 7G characterization confirmed that personal notification
presets cannot safely serve cross-member observability views; feature commit
`a2351fa` now adds the separate organization-owned named investigation view
with finite validated filters, policy rechecks, opaque identifiers, explicit
expiry, atomic retention bounds, scheduled pruning and a redirect back to the
canonical context. Phase 8's structured-diagnostics characterization and
typed control-plane report are complete locally at `f084951` and `2f7d719`;
`run()` preserves the existing consumer projection. The fixed server-host
diagnostic contract is implemented and verified in `4add5b9`; its focused
suite passed **16 tests / 90 assertions**, the adjacent regression set passed
**45 tests / 373 assertions**, and the full strict isolated suite passed
**1,461 tests / 12,534 assertions** with the unchanged provisioning baseline
failure. Required-PHP quality and asset/browser checks passed, including **9
browser tests**. The interactive transport characterization and lifecycle
implementation are recorded in the progress ledger. The lifecycle suite
passed **13 tests / 46 assertions**, the adjacent server/import/log/command/
observability set passed **78 tests / 609 assertions**, and the full strict
isolated suite passed **1,474 tests / 12,580 assertions** with the unchanged
provisioning baseline failure. The broker now performs live lease revalidation
and bounded frame relay in a supervisor-oriented command, and the lifecycle
HTTP boundary now exposes bounded input, output polling/acknowledgment and
resize operations with bearer revalidation and no-store responses. Feature
commit `e9b1ed1` now adds the bounded UUID-only supervisor scan and daemon
installer contract: eligible brokers are started through a systemd template
with restart and control-group cleanup semantics, while ineligible sessions
are revoked before any start. A disposable Debian 12 LXD system container
with systemd, OpenSSH and an isolated network namespace then exercised the
actual application broker through normal stop, exact broker-PID loss/restart,
network-interface partition cleanup and new-session-only reconnect. This is
installed-host-equivalent local evidence, not a full VM or cloud/provider
acceptance claim. The local lifecycle gate is complete; browser-terminal
exposure remains gated and the separate live/provider acceptance track remains
outstanding.

After the supervision slice, the earlier complete strict isolated suite passed
**1,514 tests / 12,797 assertions** with the same single provisioning baseline
failure. A fresh strict run after the PTY-lifetime fix completed with **1,510
passing and 5 failing tests / 12,780 assertions**: four unrelated existing
validation-message response assertions in the incident and organization
management tests, plus the same provisioning baseline mismatch.
Repository-wide Pint, Composer validation/platform checks, route-cache
creation, installer shell syntax and `git diff --check` also passed.
Additional disposable local checks confirmed that a transient systemd
control-group stop removes its child. After the PTY-lifetime fix in `8ad2e52`,
the actual broker remained live through short input, normal transient-systemd
stop removed the remote process, and exact broker-PID loss caused one restart
with control-group cleanup. These do not establish cloud/provider acceptance.
A separate
deterministic disposable `sshd` exercise used the real
`SshServerTroubleshootingTransport` and five sequential encrypted-key sessions;
each accepted bounded input and returned a proof marker. This validates the
local adapter boundary; the later LXD host-like exercise added the installed
systemd, worker-loss, partition and reconnect evidence recorded in the progress
ledger.

The Phase 9 source-attribution slice is complete in `fab31ef`: the cost
controller delegates to an injected `InfrastructureCostQuery`, catalog
refreshes record `sizes.catalog_synced_at`, and the UI distinguishes catalog
estimates, measured CPU telemetry and unavailable provider billing. Its focused
cost/catalog/provider set passed **10 tests / 39 assertions**. Feature commit
`b4fa99f` adds the bounded, read-only preview quota/lifetime projection via
`PreviewUsageQuery`; the cost and preview regression set passed **28 tests /
272 assertions**. Attribution and review-only cleanup commits `907811d` and
`e6ff82c` add direct/shared/unallocated relationship labels and a policy-
protected review path without writes or jobs on GET; the final cost regression
set passed **8 tests / 40 assertions**. Phase 9 local scope is complete. The
next task is the final cross-feature verification and requirement audit;
provider billing, automatic cleanup and provider/cloud acceptance remain
separate.

The final local audit was completed from an independently configured durable
checkout at `614a0ae`. The strict PHP run recorded **1,515 passed, 5 failed and
12,809 assertions**; the five failures reproduce the fresh pre-Phase 9 baseline
(four existing incident/organization validation-message assertions and the
known provisioning `localhost` count mismatch), with no Phase 9 test failure.
Required-PHP Pint, Composer manifest/platform checks, fresh migration/seed,
configuration/route/view cache creation, asset build and `git diff --check`
passed. The required-PHP asset suite passed **9 tests**, the cached served
Livewire smoke passed **1 test**, accessibility passed **3 tests** across
mobile/tablet/desktop and the broad visual audit passed **3 tests** across its
authenticated route crawls. The prior tablet focus and mobile Settings-link
browser discrepancies are resolved on the current main line. This remains
local evidence; the authorized cloud/provider drill, production integrations,
billing, monitoring and live acceptance remain deferred release gates.

## Latest local verification follow-up — 2026-09-14

The five failures recorded by the final audit were resolved as test-contract
corrections in `711af3c`, without changing application behavior. Incident and
organization tests now assert the preserved message on the production-safe 422
exception while retaining the existing status and no-write checks. The website
database hardening test now counts only quoted SQL `localhost` host literals,
excluding the isolated callback URL, and rejects wildcard grants explicitly.

The complete strict PHP suite now passes **1,520 tests / 12,827 assertions**;
the focused follow-up set passed **28 tests / 177 assertions**. The commit was
pushed after creation and fast-forwarded into canonical `main`, which was then
pushed to GitHub. This does not change the separate external acceptance status.

## Final release-gate review — 2026-09-16

The integrated isolated `main` runtime passed the strict PHP suite with
**1,544 tests / 12,956 assertions**, required-PHP Pint, Composer validation and
platform checks, and `git diff --check`. The required-PHP asset fixture suite
passed **9 tests**. The corrected complete browser run, with
`BROWSER_PHP_BINARY` pinned to PHP 8.5.10, passed **16 tests** across
accessibility, asset layouts, no-JavaScript provider submission, served
Livewire, and mobile/tablet/desktop product-page crawls. After route,
configuration and view caches were created in the isolated checkout, the
served Livewire/mobile smoke passed **1 test** and the isolated application
continued to return HTTP 200.

An earlier aggregate browser command omitted the required PHP override and was
discarded after its fixture hook selected system PHP 8.3.6; it is not counted
as application evidence. No dependencies or application code changed in this
review. Local Phase 9 scope and the authorized disposable provider
deployment/rollback/backup/restore/cleanup evidence are complete. Production
mail, independent monitoring/heartbeat destinations, GitHub App configuration,
billing/SSO, provider-backed preview readiness and PostgreSQL/Valkey recovery
remain separate acceptance gates.

## UI modernization completion — 2026-09-17

The planned UI modernization work is complete on isolated `main`. Shared
semantic tokens and Blade UI primitives now support a consistent hierarchy,
status language, spacing, responsive layout, focus treatment, forms,
navigation, cards, alerts, badges and empty states. Workspace navigation is
grouped by user intent while existing routes and destinations remain intact.
The complete page-family pass covered dashboard, applications, deployments,
infrastructure, data/recovery, operations, observability, workspace/account,
templates/community, public/authentication, repositories, websites, providers,
servers, configuration and remaining compatibility surfaces.

The final strict PHP suite passed **1,556 tests / 12,791 assertions** using
PHP 8.5.10. Pint, the required-PHP Composer platform check, Vite build,
`git diff --check`, and the explicit 9-test asset suite passed. The complete
isolated browser run passed **18 tests** and skipped only the opt-in deployed
live-runtime check; it covered accessibility, responsive layouts,
no-JavaScript provider submission, grouped navigation, served Livewire and
mobile/tablet/desktop product crawls. Generated config/route/view caches and
the cached served-Livewire/mobile smoke also passed.

No production deployment, dependency upgrade, credential use, paid cloud
operation or live acceptance is implied. The next task is separately
authorized release-gate/external acceptance work, or a new product request
with its own inventory and verification record.

## Navigation consolidation follow-up — 2026-09-17

The UI modernization follow-up completed the intended hub model rather than
only grouping the existing links. `WorkspaceNavigation` now exposes
Applications for the project, environment, deployment and repository family;
Template library for recipes and gallery; Billing and usage for billing and
cost visibility; and Account and security for the existing account/settings
surface. Applications, Gallery, Billing and Costs retain contextual links so
the underlying route destinations remain discoverable without restoring a
long duplicate primary menu.

No route, authorization, persistence or workflow contract changed. The
focused PHP matrix passed **64 tests / 743 assertions**; Pint, view cache,
diff checks and the Vite build passed; the responsive asset suite passed **9
tests**; served navigation passed **3 tests** across three viewports; and the
accessibility/visual crawl passed **6 tests**. Commit `215da0d` is pushed to
`origin/main`. This is local/dev evidence only; production release and live
acceptance remain deferred gates.

## UI hierarchy verification — 2026-09-18

The application-wide UI improvement sequence is complete locally on isolated
`main`. The final pass covers the shared shell/navigation, all major inventory
and detail families, recovery/operations, observability, workspace/account,
community/template, public documentation/status, authentication, billing,
search and admin surfaces. The result-first/progressive-disclosure patterns
are covered by focused feature tests and the full regression suite.

The strict PHP 8.5.10 suite passed **1,602 tests / 13,171 assertions**;
full Pint, the required-PHP Composer platform check, Vite build, route/view
caches and `git diff --check` passed. The implementation is pushed to
`origin/main`, and the isolated runtime services remain active. Browser
measurements after the latest slices are deferred because Chromium crashes on
the host's exhausted root disk; they are not counted as passing evidence.

The next work is external/live acceptance only when separately authorized:
production release gates, physical-phone checks, provider-backed acceptance,
mail, billing, GitHub App, independent monitoring and the remaining recovery
drills.

## Deferred release gates

The September 17 preview review found and corrected a PostgreSQL cleanup
transaction error that the earlier script-text tests did not detect. A real
disposable PostgreSQL 16.15 cluster verified deletion, repeat execution,
shared-data preservation and partial-failure retry. See
[the verification record](verification/preview-postgresql-cleanup-2026-09-17.md).
Full-stack provider and recovery checks below remain necessary.

The same continuation corrected false success after a managed Valkey restart
failure and prepares managed resources before first-deployment dependency hooks
and Laravel migrations. A separately committed command-rendering extraction
keeps the original 15-stage callback protocol intact. The current strict suite
passes **1,555 tests / 12,986 assertions**, with full Pint and dependency/platform
checks passing. Real disposable PostgreSQL creation/repeat/cleanup checks pass;
they do not establish full Laravel/Valkey or provider acceptance. See
[the preparation record](verification/managed-resource-preparation-2026-09-17.md).

- Independent monitoring endpoints and live heartbeat/status verification.
- Provider-backed preview-stack readiness, PostgreSQL/Valkey recovery, independent monitoring/heartbeat destinations and other provider-specific acceptance not covered by the successful disposable backup/recovery drill.
- Production mail delivery, GitHub App configuration and approved billing activation as recorded in the original roadmap.

Live tests remain required before release. Deferral does not count as passing them.
