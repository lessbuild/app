# BuildPusher chat handoff

## Latest continuation — provider-backed preview-stack acceptance — 2026-09-17

The representative Laravel preview stack passed on the isolated dev runtime
through the real signed repository webhook and disposable DigitalOcean
lifecycle. The run verified independent preview credentials and secret
boundaries, queue/scheduler plus managed PostgreSQL/Valkey readiness, revision
updates, an intentional HTTP 503 health failure, previous-release recovery
without a manual PHP-FPM reload, a healthy retry, close, reopen and exact
cleanup. The reopened stack used a new environment generation, so its cleanup
could not target the original generation.

The detailed non-secret evidence is in
[verification/preview-stack-acceptance-2026-09-17.md](verification/preview-stack-acceptance-2026-09-17.md).
The application fixes are `3895a31` and `c582dd8`; both are pushed to
`origin/main`. Focused deployment/health checks passed **14 tests / 119
assertions**, and preview/cleanup/readiness checks passed **35 tests / 337
assertions**. The fresh complete strict PHP suite passed **1,556 tests /
13,004 assertions**; required-PHP Pint, Composer validation/platform checks,
the asset build, `git diff --check`, the rebuilt asset/layout browser suite
(**9 tests**) and the served Livewire/mobile smoke (**1 test**) also passed.
The isolated dev service and worker were active, and the homepage plus both
rendered assets returned HTTP 200. The disposable server, source website,
repository, project, preview resources and provider droplet were removed
through supported cleanup.

This completes local cross-feature verification and the representative
provider-backed acceptance scope. The next task is separately authorized
release handoff and external acceptance. Keep production mail, independent
monitoring/heartbeat destinations, GitHub App configuration, billing/SSO,
broader provider-specific recovery and the separate live acceptance drill
explicitly outstanding.

## Configuration-specific provider acceptance — 2026-09-17

The isolated dev runtime completed the configuration-as-code provider
acceptance sequence using the authorized DigitalOcean/GitHub connections. It
verified read-only planning, exact review/apply, real delivery, repeated
apply/review idempotency, source-secret freshness rejection, foreign-binding
denial, approval/cancellation, explicit retry, a reversible Caddy health
failure, terminal result refresh, local-only environment removal and
supported cleanup of the disposable cloud resources.

The detailed non-secret evidence is in
[verification/configuration-acceptance-2026-09-17.md](verification/configuration-acceptance-2026-09-17.md).
Project 9, website 11, repository 9 and server 16 were removed; DigitalOcean
droplet 601315670 and its acceptance SSH key were absent in post-cleanup
inventory. The fixture revision was
`375d556fa50e4f76b880f59f075995bf554036a8`; build 30 delivered the real
`hello world v9` response, build 34 recorded the controlled health failure,
and explicit retry build 35 succeeded. No credential or secret value is
recorded.

This closes the configuration-specific gate only. Provider-backed preview
stack readiness, managed PostgreSQL/Valkey recovery, the generic recovery
drill, production integrations and the separate live acceptance drill remain
outstanding. The exact next task is the provider-backed preview-stack cycle.

## Preview acceptance continuation — 2026-09-17

The PostgreSQL preview cleanup script had a real execution defect despite the
earlier passing suite: database and role deletion shared one `psql --command`,
which PostgreSQL treats as a transaction and rejects for `DROP DATABASE`.
The fix submits two separate commands with the existing stop-on-error and
idempotency behavior. The new Bash execution regressions fail before the fix.
A disposable PostgreSQL 16.15 cluster also reproduced the original failure and
verified successful/repeated deletion, shared-data preservation, partial
failure/retry and non-owner denial after the fix.

Work is isolated in
`/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-preview-cleanup-Amr47o`
on its own `main`, using independently copied locked dependencies/assets,
a fresh application key, SQLite in memory and separate storage. See
[the verification record](verification/preview-postgresql-cleanup-2026-09-17.md)
for the final checks and publication status in the progress ledger.

Cleanup fix `70d7c78` is pushed to `origin/main` and integrated into both
canonical `main` and the dev runtime. The strict full suite passed
**1,547 tests / 12,962 assertions**, with full Pint and syntax/diff checks
passing.

The dev worker was found stopped after its normal one-hour exit. Its isolated
unit now uses the repository installer's `Restart=always` policy. A real
Laravel queue-restart signal caused a clean exit and an automatic replacement
three seconds later; the queue was empty. See
[the runtime record](verification/dev-worker-restart-2026-09-17.md).
The Spaces follow-up is also complete: a correctly signed, prefix-scoped
listing succeeded with the existing key. The three empty-repository metadata
objects left by the September 16 drill were explicitly deleted; a fresh listing
found zero current objects under `buildpusher/websites/10/`. Earlier wording
blaming key permissions for the diagnostic 403 was not established. See
[the cleanup record](verification/spaces-drill-cleanup-2026-09-17.md).
The managed Valkey resource script also no longer reports success after a
failed existing-container start. Its new executable regression failed against
the old code; the full strict suite now passes **1,551 tests / 12,974 assertions**
with full Pint. See [the verification record](verification/managed-valkey-start-2026-09-17.md).
Valkey correction `14e3bf5` is pushed and integrated. The preparatory managed-
resource renderer extraction now preserves byte-identical output across five
snapshot variants; 36 focused tests / 391 assertions pass. See
[the preparation record](verification/managed-resource-preparation-2026-09-17.md).
Extraction `b3070a3` is pushed and integrated. First-deployment resource
preparation now runs before dependency hooks and migrations, with all 15
callback stages preserved and stage 11 retaining its normal reconciliation.
The full strict suite passes **1,555 tests / 12,986 assertions**; full Pint,
syntax, Composer validation/platform and diff checks pass. A disposable real
PostgreSQL check verified creation before hook/migration doubles, repeat
execution, shared-data preservation and exact cleanup; the cluster is stopped.
Timing fix `f3cd675` is pushed and integrated into canonical `main` and the dev
runtime. Both dev services are active, the queue is empty, and the homepage and
its rendered CSS/JavaScript assets return HTTP 200. The preparation record above
documents the intentional timing change and limits.
The configuration-specific provider acceptance sequence is now complete; see
the section above. Next is the full provider-backed preview stack and recovery
cycle. These remain separate from the local checks and the earlier generic
disposable backup/restore drill.

## API access follow-up — 2026-09-14

All billing plans now include the existing scoped control-plane API. The
workspace quota is plan-based and uses the following per-minute limits: Free
60, Starter 120, Pro 300, Team 600, Business 1,200 and Unlimited 3,000.
Authenticated workspace members share the current workspace owner's quota;
the existing Sanctum abilities, organization authorization, network policy and
non-API feature entitlements still apply. Exceeding the quota retains the
standard HTTP 429 response and rate-limit headers.

The implementation is in feature commits `139fc1f` and `6749fed`, and
documentation is in `c1122c3`; all three are pushed to
`origin/feat/api-access-all-plans-20260914`. Focused coverage passed **58
tests / 273 assertions** and the complete strict PHP suite passed **1,525 tests
/ 12,860 assertions**. The required-PHP Pint, platform, diff and dependency
checks passed. The verification ledger is
`docs/verification/api-access-all-plans-progress.md`.

## Latest follow-up — 2026-09-14

The previously recorded five final-audit failures were test-contract issues,
not Phase 9 application regressions. Commit `711af3c` updates the four
incident/organization assertions to inspect the preserved operation message on
the production-safe HTTP 422 exception while retaining the existing status and
no-write guarantees. It also scopes the website database hardening assertion to
quoted SQL `localhost` literals so the isolated callback URL is not counted.

The focused follow-up set passed **28 tests / 177 assertions**. A complete
strict run from the isolated PHP 8.5 checkout passed **1,520 tests / 12,827
assertions** with no warnings, risky tests or deprecations. The commit was
pushed to `origin/fix/baseline-test-failures-20260914`, fast-forwarded into
canonical `main` and pushed to `origin/main`.

The product-expansion local implementation and verification gate is complete.
The authorized cloud/provider drill, production integrations, billing, live
monitoring, SSO/provider acceptance and the separate live drill remain external
release gates; no local test is being represented as live acceptance.

## Product expansion current checkpoint — 2026-09-14

The product-expansion sequence is active on `main`. Phase 7F's disabled-by-default
revision-aware post-deployment observation aggregate, leased execution/read
surface and bounded environment-evidence integration are complete locally at
feature commits `c6eff04` and `0390030`; the Phase 7G named-investigation-view
implementation is complete at feature commit `a2351fa`, following the shared
website health probe extraction
at `3e4c337`, the revision-aware post-deployment observation characterization
and the Phase 7E stable alert identity and occurrence-metadata slice
at feature commit `aae111c`, the alert-grouping characterization commit
`43d4e43`, the
Phase 7D canonical shareable investigation URL at feature commit `c757413`,
characterization commit `c9b1e00` and the Phase 7C explicit
incident-to-deployment evidence links at feature commit `3e79f4f`,
the Phase 7A environment evidence context, observability inventory and Phase 6B isolated
restore-verification execution slice. It was implemented in
the isolated clone `/tmp/buildpusher-product-expansion-uHhkwZ`, fast-forwarded
into canonical `main` and pushed to GitHub `origin/main`. Building on the Phase 3A
manifest, Phase 3B readiness states,
Phase 3C ownership-aware cleanup, Phase 3D organization-locked quotas,
Phase 3E initialization/credential boundaries and Phase 3F provider
observations, curated Laravel and generic Node presets now have a versioned
operational contract and new projects record the installed template version
without rewriting legacy rows.

Phase 8's fixed server-host diagnostic implementation is now complete at
feature commit `4add5b9`, the persisted troubleshooting-session lifecycle
boundary is complete at feature commit `6e9e55f` with wording correction
`335ea42`, and the bounded transport/process-ownership seam is complete at
feature commit `6013f19`. Durable encrypted frames and the bounded broker
ownership command are complete at feature commit `5b54f3b`; all were
fast-forwarded into canonical `main` and
pushed to GitHub `origin/main`. The server detail page exposes a separate policy-
authorized asynchronous snapshot/action/job boundary. It requires the stored
pinned SSH host identity, runs a versioned fixed scalar probe, retains only
typed safe checks, and protects duplicate requests, retries, leases and stale
attempts. The existing arbitrary command, metrics, logs, provisioning and
provider-health paths remain separate; this is not an interactive terminal.

The policy-authorized troubleshooting-session HTTP lifecycle boundary is now
complete at feature commit ee62249. Its JSON routes create a short-lived
opaque grant, return safe status/heartbeat metadata and close idempotently
using nested scoped binding, bearer validation and private no-store responses.
The focused HTTP suite passed 8 tests / 51 assertions; the combined
HTTP/session/transport/broker regression set passed 41 tests / 186 assertions;
the fresh strict isolated suite passed 1,502 tests / 12,720 assertions with
the same unchanged provisioning baseline failure. No frame payloads, lease
hashes, credentials or tokens after creation are returned.

The bounded frame HTTP transport is now complete at feature commit 188f3b9.
Nested scoped JSON routes accept byte-preserving input and validated resize
frames for execute-authorized sessions, and return bounded decrypted output or
acknowledge output sequences for connect-authorized sessions. Existing locked
actions retain frame encryption, limits, broker ownership and stale-attempt
guards; no SSH work occurs in the request. Payloads, ciphertext, frame IDs,
lease hashes and bearer grants are not returned, and protocol responses are
private and no-store. The focused transport suite passed 15 tests / 94
assertions; the combined HTTP/session/transport/broker set passed 35 tests /
183 assertions; the fresh strict isolated suite passed 1,509 tests / 12,762
assertions with the same unchanged provisioning baseline failure. The local
supervisor installer contract is complete in `e9b1ed1`. A disposable Debian 12
LXD system container with systemd, OpenSSH and an isolated network namespace
has since exercised the actual application broker through normal stop,
broker-PID loss/restart, network-interface partition cleanup and
new-session-only reconnect. This is installed-host-equivalent local evidence,
not a full VM or cloud/provider acceptance claim; browser-terminal exposure
remains deliberately gated.

The remote-cleanup/reconnect characterization is now recorded in the progress
ledger. Normal broker return stops the local process group and releases
temporary SSH files. The SSH command now runs a foreground interactive Bash
process in the allocated PTY, so local channel closure provides the tested
normal remote-shell cleanup path without the earlier nested wrapper. The new
daemon installer declares a UUID-addressed broker template with restart and
control-group semantics plus a bounded 15-second reconciliation timer. HTTP
activity and broker renewal recheck
membership; removed members are revoked, while an execute-role downgrade
denies subsequent shell input. Reconnect remains intentionally new-session-
only: a terminal or revoked grant must never be resumed.

The focused diagnostic suite passed **16 tests / 90 assertions**; adjacent
server/import/log/command/observability regressions passed **45 tests / 373
assertions**. The fresh strict isolated full PHP suite passed **1,461 tests /
12,534 assertions** with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure.
Required-PHP lint, Pint, Composer/platform, route-cache, shell, diff, Vite and
required-PHP browser asset checks passed, including **9 browser tests**. No
provider/cloud/live-acceptance claim is made. The interactive transport and
host-execution characterization is now recorded in the progress ledger, and
the lifecycle slice passed **13 tests / 46 assertions** with the adjacent
server/import/log/command/observability set passing **78 tests / 609
assertions**. The transport suite passed **8 tests / 25 assertions** and the
adjacent server command/diagnostic/session set passed **58 tests / 376
assertions**; the fresh strict isolated full suite passed **1,482 tests /
12,605 assertions** with the unchanged provisioning baseline failure. The
durable frame/broker slice passed **33 tests / 135 assertions**; the fresh
strict isolated full suite passed **1,494 tests / 12,668 assertions** with the
same unchanged baseline failure. The new migration pair passed a disposable
SQLite fresh/rollback/reapply rehearsal. Input is encrypted and marked sent
before remote write for at-most-once semantics; output is sequenced,
acknowledgeable and bounded, and exact lease/attempt/process guards fail closed
on expiry or stale callbacks. The frame HTTP slice passed **15 tests / 94
assertions**, the combined transport set passed **35 tests / 183 assertions**
and the fresh strict isolated suite passed **1,509 tests / 12,762 assertions**
with the same unchanged baseline failure. The local installed-host-equivalent
evidence is recorded below; provider/cloud acceptance, the separate live drill
and browser-terminal exposure remain outstanding. The Phase 9 source-attribution
slice is complete in `fab31ef`: the cost page now uses an injected
`InfrastructureCostQuery`, records `sizes.catalog_synced_at` during catalog
refresh and distinguishes catalog estimates, measured CPU telemetry and
unavailable provider billing. The focused cost/catalog/provider set passed
**10 tests / 39 assertions** with strict warning/deprecation flags.
Feature commit `b4fa99f` adds the bounded, read-only preview quota/lifetime
projection through `PreviewUsageQuery`; the cost and preview regression set
passed **28 tests / 272 assertions** with strict warning/deprecation flags.
Attribution and review-only cleanup commits `907811d` and `e6ff82c` now
label direct/shared/unallocated server relationships and link expired
previews to the policy-protected project review page. The final cost
regression set passed **8 tests / 40 assertions**, including assertions that
expired preview reads do not change status, closure or the queue. Phase 9
local scope is complete. The next implementation task is the final
cross-feature verification and requirement audit; provider billing, automatic
cleanup and provider/cloud acceptance remain separate.

The final local verification gate is now complete from the durable isolated
checkout `/root/Documents/Codex/2026-09-14/buildpusher-product-expansion-final`
at `614a0ae`. The strict PHP suite recorded **1,515 passed, 5 failed and 12,809
assertions**. Those five failures reproduce the fresh pre-Phase 9 baseline:
four existing incident/organization validation-message response assertions and
the known provisioning `localhost` count mismatch. No Phase 9 test failed.
Required-PHP Pint, Composer validation/platform checks, fresh migration/seed,
config/route/view caching, `npm run build` and `git diff --check` passed. The
required-PHP asset/browser suite passed **9 tests**; the cached served-Livewire
smoke passed **1**; accessibility passed **3** across mobile/tablet/desktop;
and the broad authenticated visual audit passed **3** across mobile, tablet and
desktop. The earlier tablet focus and mobile Settings-link discrepancies are
resolved in the current main line. The first discarded visual attempt used a
temporary `/tmp` worktree whose optional Telescope table was absent; the durable
rerun disabled only optional Telescope recording and is the valid evidence.

Phase 9's safe local scope and the complete local product-expansion verification
gate are complete. The cost surface remains estimate/telemetry/provider-billing
honest, preview quota/lifetime and attribution are read-only, and cleanup
signals do not delete or dispatch. Remaining release work is explicitly
external: authorized cloud/provider deployment and cleanup evidence, production
mail, GitHub App, billing, monitoring, SSO/provider acceptance and the separate
live drill. The interactive terminal UI remains deliberately gated. The exact
documentation commit and push status are recorded in GitHub and the progress
ledger; canonical `main` remains the integration branch.

The earlier post-supervision complete strict isolated suite passed **1,514
tests / 12,797 assertions** with the same single provisioning baseline failure.
A fresh strict run after the PTY-lifetime fix completed with **1,510 passing
and 5 failing tests / 12,780 assertions**: four unrelated existing
validation-message response assertions in the incident and organization
management tests, plus the same provisioning baseline mismatch.
Repository-wide Pint, Composer validation/platform checks, route-cache
creation, installer shell syntax and `git diff --check` passed.
Additional disposable local checks confirmed that a transient systemd
control-group stop removes its child. After the PTY-lifetime fix in `8ad2e52`,
the actual broker remained live through short input, normal transient-systemd
stop removed the remote process, and exact broker-PID loss caused one restart
with control-group cleanup. These do not establish cloud/provider acceptance.
A deterministic disposable local `sshd` check then exercised the real
`SshServerTroubleshootingTransport` across five sequential encrypted-key
connections; each accepted bounded input and returned a proof marker. The
later Debian LXD host-like exercise added installed systemd, worker-loss,
partition and reconnect evidence. No production credentials, cloud resources
or acceptance-drill files were used.

The LXD host-like verification delivered encrypted marker input/output through
the real broker, left no remote interactive shell after normal systemd stop,
cleaned the remote shell after exact broker-PID loss and restart, and left no
shell after an isolated `eth0` detach/reattach partition test. The stale lease
remained until explicit revocation. A newly authorized session received a new
opaque identity, connected after reattachment and released its lease. The
current strict isolated full-suite baseline remains **1,510 passing and 5
failing tests / 12,780 assertions**: four existing incident/organization
validation-message assertions and the known provisioning `localhost` count
mismatch.

The supervisor wiring slice passed the combined supervisor/broker/HTTP/
transport suite (**40 tests / 209 assertions**) and the daemon-installer
contract suite (**2 tests / 56 assertions**), plus required-PHP lint, scoped
Pint, `bash -n` and `git diff --check`. It proves bounded UUID unit discovery,
pre-start policy revocation and local process-lifecycle declarations; it does
not prove an installed systemd host, worker-loss cleanup, network-partition
cleanup or remote process termination.

Phase 8's structured-diagnostics characterization is complete at `f084951`,
and the typed control-plane diagnostic report is complete at feature commit
`2f7d719`. `OperationalDiagnostics::report()` now carries immutable,
enum-categorized checks while `run()` preserves the exact existing CLI, JSON,
health, public-status and cache projection. The focused diagnostic set passed
**20 tests / 159 assertions**; the strict isolated full suite passed **1,445
tests / 12,443 assertions** with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure.
Required-PHP Composer validation/platform checks, PHP lint, full Pint, route
cache and `git diff --check` passed. This PHP-only slice made no frontend
changes. The fixed server-host diagnostic contract is implemented in `4add5b9`
and is recorded in the progress ledger, including pinned host identity, an
allowlisted versioned script, bounded scalar parsing, lease/retry behavior,
latest-result retention and a safe server-page read surface. Interactive
terminal transport remains deferred.

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

The first connected-observability slice adds a policy-authorized environment
context at `GET /observability/environments/{environment}/context`, linked from
the observability dashboard and project environment cards. A finite `24h`,
`7d` or `30d` Form Request boundary feeds an injected query collaborator that
loads recent or active environment builds, bounded website health observations,
metadata-only current runtime-log snapshots and explicitly related operational
incidents. Existing build, health, runtime-log and incident routes remain the
authorization and sensitive-content boundaries. The context does not render
encrypted log bodies, health errors/endpoints, incident summaries/resolutions,
provider credentials or environment secrets; it performs no writes, queued
jobs, provider calls or causal inference. Adjacent signals are labeled as
evidence to investigate, not proof of causation.

Phase 7B extends that context with validated repository-service selection,
active/successful/unsuccessful deployment groups and incident-severity filters.
Service filtering narrows deployment records only; shared health, runtime and
infrastructure signals remain visible because the current model cannot safely
attribute them to one repository target. Rejected, failed and canceled builds
are grouped as unsuccessful. Cross-organization attached website/server
relations are discarded before any evidence query.

Phase 7D characterized the existing notification saved-filter preference and
rejected reusing it for observability: it is user-scoped JSON with no
workspace/resource authorization, environment identity, expiry or retention
contract. The environment context now exposes a canonical shareable URL built
only from normalized `window`, `service`, `deployment` and `severity` filters.
Unknown query input is excluded, every visit rechecks the current environment
and resource policies, and the link contains no secrets or sensitive evidence.
Named shared views remain a separate future design requiring an
organization-owned persistence boundary.

Phase 7C keeps the existing incident-centre link and adds a separate
`Open deployment evidence` action when a deployment incident's resource ID
resolves to one of the already bounded, tenant-scoped builds in the context.
The action goes through the existing `BuildPolicy` route and therefore opens
the established revision, timeline, bounded log and configuration-identity
surface without placing encrypted incident or configuration bodies in the
context response. Other incident categories do not receive guessed links, and
the UI continues to describe adjacent signals as evidence rather than proof of
causation.

Phase 7E characterization found that operational incidents already group an
active failure by workspace/category/resource under a unique key, append
occurrence events under a row lock and clear that key on recovery. Website,
provider and metric monitors add transition, consecutive-breach and cooldown
guards. Direct `IncidentNotifier::fail()` calls still send each database and
external alert event, so incident grouping is not delivery deduplication.
The completed `OperationalIncidentAlert` boundary now carries non-secret
`incident_id`, `incident_occurrences` and `dedup_key` metadata on external
deliveries. PagerDuty uses the supplied stable key, and legacy queued payloads
retain their previous fallback; inbox delivery, webhook frequency, retries and
recovery behavior remain unchanged. The deployment plan's health stage is one
immediate retried HTTP probe, while periodic website checks are separate,
website-scoped records with no revision relationship or post-deployment
observation window. Phase 7F keeps these separate and now adds an opt-in,
monitoring-entitled observation aggregate with its own build/revision/path
identity. The successful-build callback snapshots the non-secret target in the
encrypted build payload, creates one pending record after commit, is idempotent
for duplicate callbacks and supersedes older active observations for the same
website/repository under a locked transaction. The post-commit path now queues a
unique observation job; `RunDeploymentObservationAction` claims one row with a
short lease, probes the immutable target outside the transaction, and records
only a still-current result. Queue retries are bounded, due work and expired
leases are recovered by a minute scheduler, and stale claim/revision/target
results cannot overwrite newer work. The build detail surface shows only
bounded status, check count, HTTP status and timestamps; claim tokens and remote
error text are not rendered. The bounded environment context now retains
active observation outcomes outside its selected time window, applies the
existing service and tenant filters, and renders only an immutable
revision/website-matched summary. It excludes remote error text, target
URLs/paths, claim tokens and lease metadata. Continuous website health history
and the deployment plan's immediate health probe remain separate.

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
required-PHP asset/browser suite (**9 passed**) passed. The fresh strict
isolated full suite at `375c643` passed **1,412 tests / 12,234 assertions**,
with the same unchanged `ProvisioningHardeningTest` localhost-count failure.
The focused observability/deployment/health/log run passed **51 tests / 462
assertions**, including **4 tests / 34 assertions** for the context.
Required-PHP Composer validation/platform checks, PHP lint, full Pint, Vite,
route registration, `git diff --check` and the required-PHP asset/browser suite
(**9 passed**) passed. Provider/cloud acceptance and the separate live drill
remain outstanding. The Phase 7C focused observability/deployment/health/incident
run passed **26 tests / 218 assertions**; its strict isolated full suite passed
**1,412 tests / 12,236 assertions** with the same unchanged baseline failure.
PHP lint, full Pint, route-cache creation, `git diff --check` and the required-
PHP asset/browser suite (**9 passed**) also passed. The Phase 7D URL focused
observability/deployment/health/incident run passed **26 tests / 222
assertions**; the fresh strict isolated full suite passed **1,412 tests /
12,241 assertions** with the same unchanged baseline failure. Required-PHP
Composer validation/platform checks, PHP lint, full Pint, route-cache creation,
`git diff --check` and the required-PHP asset/browser suite (**9 passed**) also
passed. The Phase 7E alert, incident, website-health and deployment-observation
characterization run passed **55 tests / 615 assertions**; no application
behavior or schema changed in that characterization commit. The alert metadata
implementation then passed the focused alert/incident/observability run
(**24 tests / 205 assertions**) and the broader alert, incident, website-health
and deployment regression set (**55 tests / 608 assertions**). The fresh strict
isolated full PHP suite passed **1,413 tests / 12,248 assertions**, with the
unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure (the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check` and the required-PHP
asset/browser suite (**9 passed**) passed. The Phase 7F deployment-health,
website-monitoring/history, observability-context and repository-deployment
characterization run passed **44 tests / 497 assertions**. The shared probe
extraction then passed **35 tests / 441 assertions**; the fresh strict isolated
full PHP suite passed **1,416 tests / 12,274 assertions**, with the same
unchanged `ProvisioningHardeningTest::test_website_database_user_is_local_only`
failure. Required-PHP Composer validation/platform checks, PHP lint, full Pint,
route-cache creation, `git diff --check` and the required-PHP asset/browser
suite (**9 passed**) passed. The Phase 7F shared probe extraction then passed
**35 tests / 441 assertions**; the subsequent observation aggregate slice
passed **68 tests / 517 assertions** in its focused/regression run. The leased
execution/read-surface slice passed **14 tests / 87 assertions** in its final
focused observation run. The fresh
strict isolated full PHP suite at `c6eff04` passed **1,429 tests / 12,359
assertions**, with the unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check` and the required-PHP
asset/browser suite (**9 passed**) passed. The subsequent environment-context,
deployment-observation and observability regression run passed **39 tests /
295 assertions**. The fresh strict isolated full PHP suite at `0390030` passed
**1,433 tests / 12,383 assertions**, with the same unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check`, Vite and the required-PHP
asset/browser suite (**9 passed**) also passed. The focused notification-inbox
and observability characterization run passed **34 tests / 261 assertions**;
the named-view implementation then passed **10 tests / 48 assertions**, and
the adjacent observability/notification regression set passed **44 tests /
309 assertions**. The fresh strict isolated full PHP suite at `a2351fa` passed
**1,443 tests / 12,433 assertions**, with the same unchanged
`ProvisioningHardeningTest::test_website_database_user_is_local_only` failure
(the test expects three `localhost` occurrences and the current script
contains four). Required-PHP Composer validation/platform checks, PHP lint,
full Pint, route-cache creation, `git diff --check`, Vite and the required-PHP
asset/browser suite (**9 passed**) also passed. The feature adds no API
envelope, notification-preference or evidence-snapshot change; no provider,
cloud or live-acceptance claim is made. That earlier checkpoint is superseded
by the Phase 8 characterization and typed-report commits recorded above; the
exact next task is the fixed server-host structured diagnostic contract before
any interactive terminal transport.
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

## Historical interruption point: environment removal (superseded)

The following section records an earlier handoff state. Whole-environment
removal was subsequently implemented, tested and documented on the current
main line, including `ApplicationConfigurationEnvironmentRemovalTest`,
`ApplicationConfigurationRemovalWorkflowTest` and the application-configuration
contract documentation. Do not use the historical checklist below as the
current implementation status.

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

At that earlier handoff no environment-removal test file had been added and no
tests or build had been run after the patch. That historical gap is closed on
the current main line; live migration rollout and external acceptance remain
separate concerns.

## Historical next-work list (superseded)

1. Inspect the interruption patch. Add focused removal tests: valid/remove-only/mixed schemas, duplicates/conflicts/unknown keys, read-only plan, each child shown, manual/foreign/stale ownership rejection, production/protected rejection, active builds and operations, automation/load-balancer/preview safeguards, post-review state/access changes, rollback, absent-target/same-review/new-review retries, and preservation of remote target records/build history.
2. Exercise equivalent web/API removal-only workflows with empty bindings and the explicit warning. Audit FK cascade effects and deployment/dependency races; do not infer concurrency safety from sequential tests.
3. Update the contract and operator docs with the tested removal behavior and precise remaining gaps.
4. Finish operator recovery/retry controls, broader deduplication/repository-change coverage, true database concurrency/deployment-start races, resource credential/managed-resource audit, parser pre-expansion limits and runtime-validator parity.
5. Run full configuration suites and the whole application regression suite, plus rendered UX checks. No complete full-suite passing result was recovered from the earlier long run; do not claim one.
6. Finish migration/rollout verification and requirement-by-requirement completion audit before moving to full-stack previews. Deferred live release gates remain deferred, not passed.

## Current next work

The planned local product-expansion slices and their final verification are
complete. Continue only with the separately authorized external acceptance
work, or begin a separately scoped product request with its own inventory and
verification ledger. Do not provision paid cloud resources or use production
credentials without explicit authorization and restricted access details.

Useful commands, from the actual repository:

```sh
git status --short
php artisan test --filter='ApplicationConfiguration|ConfigurationOperation|ConfigurationOwnership'
php artisan test tests/Feature/DashboardTest.php --stop-on-failure
npm run build
git diff --check
```

`phpunit.xml` configures an isolated in-memory SQLite database and testing cache paths. Verify test isolation before running destructive migration tests. Local `playwright` is available in `node_modules`; Chromium was launched headlessly with `--no-sandbox`. Do not assume old server/browser/test sessions are still alive; inspect live handles before reusing or restarting them.

## External provider acceptance attempt — 2026-09-15

An explicitly authorized disposable DigitalOcean drill used the smallest
available droplet size with a $10 maximum-spend limit. The fixture repository
`natecorkish/Deployer-Test` received and pushed a second revision
(`dcdd54fdd2c7407531db9f8df0a9aecf4d5d4033`). The disposable server reached
active provisioning and the website reached active status, but the first
deployment failed at the signed revision callback with HTTP 419 because that
route was missing from the CSRF exception list. Commit `95db81e` fixes the
callback boundary and is pushed to `origin/main`; commit `71e4ddb` separately
fixes remote backup retry state and is also pushed to `origin/main`.

The configured HTTPS backup destination rejected its stored access key, so no
backup, restore or recovery verification was claimed. The exact disposable
droplet and provisioning key were deleted and provider lookup confirmed the
disposable resource was absent while the unrelated existing droplet remained.
The isolated dev checkout was not modified. Before resuming, deploy the two
main commits to that dev app and replace the destination with valid Spaces
S3 credentials. The real-provider release audit and live acceptance remain
outstanding.

## External provider acceptance attempt — 2026-09-15 (second run)

The disposable drill was resumed in the isolated main runtime
`/root/Documents/Codex/2026-09-15/buildpusher-main-runtime` with its own
database, application key, storage, caches, assets and queue worker. The
authorized target was the smallest DigitalOcean droplet with a `$10` maximum
spend, using `natecorkish/Deployer-Test` as the controlled fixture. The second
run started at `2026-09-15T21:49:29Z` and the server deletion event was recorded
at `2026-09-15T22:39:50Z`.

The main-line fixes used by the runtime were pushed after verification:
`a78b32c`, `1348e92`, `e144566`, `3a3e9b1`, `40240f9`, `5825e04`, `419b34c`
and `b243d11`. They cover noninteractive package provisioning, missing SSH
environment handling, separated provisioning stages, Caddy log permissions,
literal-IP HTTP behavior, PHP-FPM refresh after activation and PHP-FPM refresh
before rollback health validation.

Real evidence from droplet `600822789` in `nyc1` included active provisioning,
active website setup, v4 deployment (`c8f83ff04567b88b5bbc1caa40109e1b880dc2f2`),
distinct v5 deployment (`393772b29709449bb1f5b7aa6a80c1801f45cbe8`) and a
successful rollback to v4. Direct HTTP checks returned the expected v4/v5
fixture responses with status 200. The acceptance audit passed provisioning,
website setup, two-revision deployment and rollback, but reported backup,
restore and post-restore health as missing.

The configured Spaces destination rejected its stored access key because the
key does not exist in the provider account. No snapshot or restore evidence
exists, and no cloud release-acceptance pass is claimed. A DigitalOcean
control-plane API token is not a Spaces S3 access-key/secret-key pair.
A read-only Spaces-key API probe with the connected provider token returned
HTTP 403; no account credential was created or modified.

Cleanup was independently verified: the disposable droplet is absent, the
unrelated existing droplet remains, all disposable local application records
were removed, the user-configured destination remains, and the isolated web
service/worker were stopped. To resume, update the destination in the dev app
with valid Spaces credentials, then repeat backup, exact restore, restored-data
comparison, post-restore health and the audit before deleting the next
disposable target.

## Backup/Spaces setup improvement — 2026-09-16

The isolated `main` runtime implemented and pushed `d0dca6c` (`feat: simplify
backup destination setup`). The backup destination screen now provides
DigitalOcean Spaces, Amazon S3, Cloudflare R2 and generic S3-compatible setup
presets, derives the standard Spaces/Amazon endpoint from the region, and
explains that Spaces S3 credentials are separate from a DigitalOcean
control-plane token. The form is shared by create/edit, never repopulates
credentials, and links to provider setup guidance.

Managers can now edit a destination and explicitly verify it through an active
managed website. Credential rotation preserves blank fields and the generated
Restic repository password; active backups and retained-snapshot location
changes are guarded. Verification reuses the existing runner and Restic
configuration, initializes an empty repository when needed, records bounded
sanitized errors, and updates verification state only after success. No schema,
API, YAML or queued-job contract changed.

Focused backup coverage passed: 22 tests and 188 assertions. Full Pint, PHP
lint and `git diff --check` passed. The full PHP run recorded 1,468 passing
tests and 75 unrelated baseline failures; the backup suites passed. The
isolated asset build could not start because Vite is not installed in that
checkout, and no asset files changed. No cloud resources or production
credentials were used.

The next external task remains to enter a valid DigitalOcean Spaces S3
access-key/secret-key pair in the isolated dev application and repeat real
backup, restore, restored-data comparison and post-restore health checks. The
previous control-plane token mismatch means this acceptance is still
outstanding.

## Dev host and test baseline correction — 2026-09-16

The dev domain was switched back to the BuildPusher `main` runtime at
`/root/Documents/Codex/2026-09-15/buildpusher-main-runtime`. The unrelated
temporary `/tmp/jobsite-quality` process that had been serving port 8010 was
stopped without changing its files. The existing Caddy reverse proxy already
targeted port 8010; `buildpusher-dev-main.service` is now active and enabled,
and its matching database queue worker is active and enabled, with no pending
jobs. The isolated runtime has HTTPS `APP_URL` and `ASSET_URL`. Fresh Vite
assets were built. `https://buildpusher.com/` returned HTTP 200, the CSS asset
returned HTTP 200, and a real-domain headless browser check confirmed the
stylesheet was applied. The old mixed-content HTTP asset URL is gone.

The fresh strict suite initially found that the isolated runtime's
`CACHE_STORE=file` and registration flags leaked into PHPUnit because the
test configuration only overrode legacy cache settings. Commit `564157b`
adds explicit `CACHE_STORE=array`, `REGISTRATION_ENABLED=false` and
`REGISTRATION_ALLOW_FIRST_USER=true` test settings. The focused correction
set passed 125 tests / 902 assertions; the complete strict suite passed
1,543 tests / 12,959 assertions with no errors, failures, risky tests or
deprecations. The commit was pushed to `origin/main`.

The product-expansion implementation remains locally complete through Phase
9. The only outstanding planned acceptance is real DigitalOcean Spaces
backup, restore, restored-data comparison, post-restore health and cleanup;
valid Spaces S3 credentials are still required. A DigitalOcean control-plane
token cannot perform that acceptance.

## External provider acceptance attempt — 2026-09-16 (third run)

The separately authorized disposable drill resumed in the isolated main
runtime with the smallest DigitalOcean droplet size
`s-1vcpu-512mb-10gb` and the `$10` maximum total-spend limit. The existing
provider droplet was inventoried and left untouched. The run started at
`2026-09-16T19:14:52Z`; disposable droplet `601159938` was created in `nyc1`,
reached active provisioning, and was later deleted through the supported
server workflow.

The controlled fixture `natecorkish/Deployer-Test` received revisions
`9d6fe6b` (`v6`) and `7f72430` (`v7`). After creating a disposable project and
staging environment before the audit chain, BuildPusher recorded:

- build `22`: environment-linked redeploy of revision
  `393772b29709449bb1f5b7aa6a80c1801f45cbe8` (`v5`);
- build `23`: distinct revision
  `7f724303d4f1a3dcdff3b4ec93107a00f6142fd9` (`v7`); and
- build `24`: successful rollback linked to build `22`.

Direct HTTP checks returned `hello world v5`, `hello world v7`, and
`hello world v5` after rollback, all with HTTP 200. The acceptance audit,
captured before cleanup with `--since=2026-09-16T19:14:52Z`, passed cloud
provisioning, website provisioning, two-revision deployment and rollback.

The encrypted Spaces fields were populated, but verification reached the
bucket and failed during Restic initialization with `Access Denied` on two
attempts. `last_verified_at` remains null; no snapshot, backup, restore or
post-restore health evidence exists. The stored pair is mismatched or lacks
the required bucket permissions and must be replaced through the dev UI; it
was not printed or copied into this record.

Cleanup was independently verified: droplet `601159938` is absent while the
unrelated existing provider droplet remains, disposable local infrastructure
records are gone, the backup destination is preserved, the queue is empty,
and the dev web/worker services remain active with the domain returning HTTP
200. This run does not establish complete cloud release acceptance. The next
task is to enter a Spaces S3 pair with read/write/delete access to
`builder-backup`, then repeat the bounded drill from the start and capture
backup, exact restore, restored-data comparison, post-restore health and the
audit before cleanup.

## Additional Spaces verification retry — 2026-09-16

A fresh disposable DigitalOcean host was created with the smallest
authorized size, `s-1vcpu-512mb-10gb`, in `nyc1` (provider identifier
`601169375`). Its first SSH identity scan raced host readiness; the existing
remote-provisioning retry action resumed at stage 3 and completed the host at
stage 12. A disposable website provided the active-server entry point for the
supported backup-destination test.

Restic reached `https://lon1.digitaloceanspaces.com` but again received
`Access Denied` while initializing `builder-backup`. The destination remains
unverified, with no backup, restore or post-restore health evidence. The
stored pair is therefore still invalid or lacks the required bucket
permissions; it was not printed or copied into this handoff.

The disposable server and website were removed through the supported cleanup
workflow, provider absence was independently confirmed, the unrelated
provider droplet remained, the stale queue job was consumed after restarting
the isolated worker, and the destination was preserved. This retry does not
establish cloud release acceptance. Replace the Spaces S3 pair through the
dev UI, then repeat the backup, exact restore, restored-data comparison and
post-restore health checks before claiming completion.

## Serverless backup destination verification — 2026-09-16

The backup connection check no longer requires an active website or managed
server. Commit `0ab3365` adds `S3CompatibleStorageProbe`, which writes a
generated marker from a 0600 local temporary file to a temporary S3-compatible
object over signed HTTPS, reads it back, deletes it, and cleans up the local
file. The controller, Form Request, policy, flash messages, encrypted fields
and verification-state persistence remain compatible. Actual backup and
restore jobs still use the existing remote Restic workflow.

The destination page now has one serverless Verify action per destination.
Focused coverage passed 9 tests / 43 assertions; related backup coverage passed
28 tests / 241 assertions; and the exact committed tree passed the strict full
PHP suite with 1,544 tests / 12,956 assertions. Full Pint, PHP lint and
`git diff --check` passed. A real isolated-dev probe reached the configured
Spaces endpoint directly and received sanitized HTTP 403, so the stored Spaces
pair still needs replacement and no cloud backup/restore acceptance is claimed.

Commit `0ab3365` is pushed to `origin/main`. Next task: enter a valid Spaces S3
access-key/secret-key pair with bucket read/write/delete permissions in the dev
UI, then repeat the real backup, exact restore, comparison, health and cleanup
drill without provisioning a server merely to verify the destination.

## Spaces 403 diagnostic — 2026-09-16

The real isolated probe returned HTTP 403 with the provider code
`InvalidAccessKeyId`. The request reached Spaces directly; the access-key value
currently stored for the dev destination is not recognized. If the control
panel shows a valid key, save that exact current key and its matching secret in
the destination together. A regenerated/revoked key, a regular DigitalOcean
control-plane token, or a key from another account will produce this result.

The connected DigitalOcean control-plane credential returned HTTP 401 on the
read-only Spaces-key listing endpoint, so no account-side comparison was made
and no credential was changed.

Commit `8e40ede` makes the UI show only a safe provider error code, such as
`InvalidAccessKeyId`, `SignatureDoesNotMatch` or `AccessDenied`; response XML
and credentials are still excluded. The exact-tree strict suite passed 1,544
tests / 12,956 assertions, focused backup coverage passed 9 tests / 43
assertions, and Pint/lint/diff checks passed. The commit is pushed to
`origin/main`.

Next: save a matching Spaces key pair with Read/Write/Delete object permission
for `builder-backup`, retry Verify, then run the real backup/restore acceptance.

## Successful serverless Spaces verification — 2026-09-16

After the destination was updated in the dev application, Verify succeeded
against `https://lon1.digitaloceanspaces.com` / `builder-backup`. The direct
probe wrote, read and deleted its generated temporary object without selecting
or contacting a website server; destination `2` now has `last_verified_at` set
and `last_error` is null. The earlier `InvalidAccessKeyId` response belonged
to the older stored value. No credential material was printed or recorded.

Actual website backup, restore, post-restore health and cloud acceptance are
still outstanding. The next real recovery drill may use the managed website
for Restic execution, but connection verification no longer needs it.

## External provider acceptance — 2026-09-16 (successful backup and recovery drill)

The authorized disposable acceptance run succeeded on the isolated `main`
runtime. It used the smallest DigitalOcean droplet size
`s-1vcpu-512mb-10gb` in `nyc1`, with a `$10` maximum total-spend limit. The
run started at `2026-09-16T21:14:48Z`; disposable provider identifier
`601196607` was deleted afterward. The existing `Codex` droplet was inventoried
and left untouched. The isolated worker used the database queue so provisioning
and retry semantics remained asynchronous.

Using `natecorkish/Deployer-Test`, the clean release chain deployed revision
`5e61e1c` and served `hello world v8` (HTTP 200), deployed revision `375d556`
and served `hello world v9` (HTTP 200), then rollback build `29` restored the
v8 release (HTTP 200). An earlier successful build changed an unused fixture
root file and was excluded from the chain after correcting the actual
`public/index.php` document root.

Spaces destination `2` was reverified serverlessly before backup. Backup `3`
completed over HTTPS; backup `4` captured a disposable storage marker and
database marker. After mutation, restore request `1` successfully restored the
exact backup, and independent checks confirmed both markers returned to their
pre-backup values. A separate post-restore health check returned HTTP 200.

The pre-cleanup command
`buildpusher:acceptance:audit 8 --provider=digitalocean --since=2026-09-16T21:14:48Z --json`
returned `passed` for cloud provisioning, website provisioning, deployment,
rollback, offsite backup, restore and health verification. The two snapshots
were forgotten; Restic then reported zero snapshots and zero raw data. The
disposable server, website, repository, project and environment records are
gone; provider inventory shows only the pre-existing droplet; destination `2`
remains; the queue is empty; and the dev domain still returns HTTP 200.

The separate ListObjects diagnostic returned HTTP 403, leaving metadata
cleanup unverified at this checkpoint. The September 17 follow-up above
successfully listed and removed the three remaining metadata objects using
the existing key; the earlier attribution to key permissions was unproven. No
broader bucket deletion was attempted, and no credentials or raw remote output
were recorded. This proves one disposable provider deployment/recovery cycle,
not production, billing, multi-provider, preview-stack, PostgreSQL/Valkey or
independent-monitoring acceptance.

The next task is release-gate review and handoff. Keep production mail,
monitoring/heartbeat destinations, GitHub App configuration, billing/SSO and
provider-backed preview acceptance explicitly separate from this successful
disposable drill.

## Final release-gate review — 2026-09-16

The isolated `main` runtime completed the final local checks after the
disposable drill: the strict PHP suite passed **1,544 tests / 12,956
assertions**; required-PHP Pint, Composer validation/platform checks and
`git diff --check` passed; the asset fixture suite passed **9 tests**; and the
corrected complete browser run passed **16 tests** across accessibility,
responsive layouts, no-JavaScript provider submission, served Livewire and the
mobile/tablet/desktop product crawl. Route/config/view caches were then
created only in the isolated checkout, its services restarted, and the served
Livewire/mobile smoke passed **1 test** with the dev domain returning HTTP
200.

The first aggregate browser command was discarded because it omitted the
required `BROWSER_PHP_BINARY` and selected system PHP 8.3.6 for its fixture
hook; the complete run was repeated with PHP 8.5.10. No application code,
dependencies, production infrastructure or credentials changed in this
review. The local product-expansion scope and authorized disposable provider
drill are complete. Production mail, independent monitoring/heartbeat
destinations, GitHub App configuration, billing/SSO, provider-backed preview
readiness, PostgreSQL/Valkey recovery and other provider-specific acceptance
remain outstanding. The then-unverified Spaces metadata cleanup was completed
on September 17 for the exact drill prefix, as recorded above.

The exact next task is separately authorized release-gate acceptance when the
remaining integrations and credentials are available.

## UI modernization completion — 2026-09-17

The UI modernization plan is complete on the isolated `main` checkout. The
implementation introduced shared semantic design tokens and Blade UI
primitives, consolidated the workspace navigation into clear groups, and
modernized every inventoried page family through the final alert, empty-state,
form, inventory and configuration consistency slices. The changes preserve
routes, authorization, validation/error bags, flash feedback, Livewire and
non-JavaScript behavior, persisted values and workflow side effects.

Final local evidence:

- Strict PHP 8.5.10 suite: **1,556 passed / 12,791 assertions**.
- Pint and PHP 8.5.10 Composer platform checks: passed.
- Vite build and explicit asset fixture suite: **9 passed**.
- Complete isolated Playwright suite: **18 passed / 1 skipped** across
  accessibility, assets, navigation, Livewire, no-JavaScript provider
  submission and mobile/tablet/desktop product crawls. The skipped case is
  the intentionally opt-in deployed-runtime check.
- Cached isolated runtime: config/route/view caches passed and served
  Livewire/mobile smoke: **1 passed**.
- `git diff --check`: passed.

The first browser attempt was discarded because its disposable built-in server
used an in-memory session driver; the corrected isolated file-session run
passed without an application change. No production checkout, credentials,
cloud resources or acceptance-drill environment were changed. Cohesive UI
commits through `299ba20` are pushed to `origin/main`; this synchronized
documentation slice is the final commit for the plan.

Production release, live acceptance, provider acceptance, billing, mail,
external monitoring and other integration gates remain separate and are not
claimed by these local UI results.

## Moving to a new chat

Use this same local repository so uncommitted/untracked work remains available. A handoff note supplies project state, not the complete old transcript. The new chat should explicitly read it. Do not keep two chats editing this worktree concurrently; stop/pause any old-chat long-running goal through the UI before resuming in the new chat. This handoff does not itself transfer or complete the goal.
