# Workspace activity progress

## 24 September 2026 — include Deployer builds in Core activity

Core's workspace activity page now combines cross-app connection delivery steps with a read-only Deployer build activity provider. The provider is registered by Deployer and owns the lookup and mapping from local builds into the Core activity contract.

Before it returns a build, the provider requires an active Core Deployer project association, a current member product grant, an active local Deployer organization membership, and an active explicit mapping from the Core project to the build's canonical environment. It does not search unmapped Deployer projects or environments. Statuses are translated into the shared activity vocabulary; provider failure text is not rendered. Each item links to the existing authorized Deployer build page using a request-local organization context, which preserves the user's saved workspace selection.

When Deployer's activity tables are unavailable, the module returns an unavailable snapshot and Core still renders the other providers' activity. The workspace page distinguishes that degraded state from a genuinely empty activity feed.

Deployment activity now also reports the measured callback stage count against the current `RepositoryDeploymentPlan`; successful legacy builds are treated as complete according to the established timeline rules. The latest progress test was added with the user-requested test deferral and has not yet been run.

Verification on 24 September:

- `php artisan test --compact tests/Feature/Core/WorkspaceWorkflowActivityTest.php`: **4 tests, 42 assertions passed**. Coverage includes mapped succeeded and approval-pending builds, exclusion of unmapped environments, and hiding items after mapping disconnection, grant revocation, or local membership loss; module outage leaves connection delivery activity visible.
- `php artisan test --compact tests/Feature/SignalThemeArchitectureTest.php tests/Feature/Core/WorkspaceWorkflowActivityTest.php tests/Feature/Core/ProjectConnectionDiagnosticsTest.php tests/Feature/Core/ProjectProductLinksTest.php`: **37 tests, 223 assertions passed**.
- Production Vite build and the Signal theme browser regression passed in `npm run test:signal-theme`.

## 24 September 2026 — include Analytics CSV exports

Analytics now registers a module-owned activity provider for CSV exports. It rechecks the active Core Analytics project grant, explicit site resource mapping, project-workspace-to-Analytics-workspace mapping, local workspace membership, and current Core product grant before returning an item. Core receives only safe status text and a link to the Analytics-host export record; filters, tokens, and raw job failure messages are not included. Expired pending work is reported as unknown, while completed history stays visible with its download retention explained.

The Analytics export record supports status, site-scoped ID download, and a locked owner/admin retry for failed records that have not expired. Retry clears private failure detail, refreshes retention, and queues the existing CSV job. Viewers retain record/download access under the existing site policy but cannot retry. Existing token-hash status and download URLs remain compatible. Analytics workspace roles now disappear as soon as the required Core grant is revoked, and project links reject sites mapped to a different Core workspace.

Verification on 24 September:

- `ANALYTICS_ENABLED=true ANALYTICS_HOST=analytics.test TRUSTED_HOSTS=analytics.test vendor/bin/phpunit tests/Modules/Analytics/Feature`: **41 tests, 322 assertions passed**. Coverage includes activity isolation across Analytics workspace mappings, revoked grants, safe export status, owner/admin retry, viewer denial, site-scoped ID download, and expired-file denial.
- `vendor/bin/pint --test` passed for all changed PHP files; `git diff --check` passed.

## 24 September 2026 — include Analytics event processing (verification deferred)

Analytics ingestion batches now appear in the same shared activity provider as report exports. It uses the existing mapped-site/project authority checks, includes queued and processing work plus recent terminal batches, and links to the mapped site's Analytics dashboard. The summary exposes only accepted-event counts and generic lifecycle guidance; batch IDs, event payloads, and stored exception text are not selected or displayed. The workspace feed sorts both task types together and applies one bounded result limit.

The Analytics feature regression now covers a failed mapped batch, exact workspace isolation, a mapped site result link, and redaction of the batch UUID and failure message. This test is written but has not been run, following the request to hold tests until plan completion. PHP syntax, Pint formatting, and Blade compilation are the available checks; the I8 acceptance and production queue-failure rehearsal remain open.

## 24 September 2026 — add Deployer provisioning activity (verification deferred)

The Deployer provider now also reads server and website provisioning state only through currently authorized, explicitly mapped environments. It verifies each resource's organization against the mapped Deployer project, checks a website's assigned server against the environment mapping, and reports source-plan stage counts without inventing per-stage timestamps. The shared feed links to the existing host-local resource screen and never copies raw provisioning errors, credentials, or IP addresses.

A feature test now covers mapped failed/queued states, stage counts, Deployer links, cross-organization mapping rejection, and redaction. It remains unrun until the implementation plan is complete, as requested. Static PHP syntax checks and `git diff --check` pass; no test command has been run for this slice.

The broader I8 background-task center, remaining module task providers, retry/cancellation for other task types, and customer-data rehearsal remain open.

## 24 September 2026 — add Monitor telemetry processing activity (verification deferred)

Monitor now has a module-owned activity provider for queued, processing, retrying, completed, failed, and unrecognized telemetry receipts, plus scheduled uptime checks and heartbeat runs. It reads these records only through currently accessible Monitor environments explicitly mapped to an active Core project, rechecks local workspace membership and the matching source workspace, reports bounded receipt counts, and links back to the Monitor monitor/environment page. It includes active checks/runs and recent unhealthy, unknown, failed, or timed-out results while leaving routine successful checks out of the feed. It does not select payloads, receipt keys, batch/run identifiers, processing tokens, check evidence/reasons, error codes, or raw failure content. The Monitor service provider registers it only when the Monitor host/module is enabled.

The shared Monitor project-link adapter now also constrains applications and environments to the source workspace explicitly mapped to the same Core project workspace, protecting summaries and project destinations as well as this activity feed.

Feature coverage now seeds separate Core and Monitor databases and checks failed receipt processing, queued and unhealthy checks, a timed-out heartbeat, omission of a routine successful check, current environment mapping, matching local workspace ownership, safe Monitor destinations, and failure-detail redaction. The test is authored but remains unrun until plan completion, as requested; only PHP syntax and diff checks have run.

The broader I8 background-task center still needs product task coverage beyond Deployer builds/provisioning, Monitor telemetry/checks/heartbeats, and Analytics CSV exports/event processing. Provider delivery tasks, retry/cancellation for other task types, and customer-data rehearsal remain open.

## 24 September 2026 — require exact Deployer workspace mapping (verification deferred)

Deployer project-resource links now require the reconciled source organization to resolve to the same Core workspace as the mapped Core project, in addition to current user and product access. This prevents a member who belongs to two workspaces from surfacing an app resource from one workspace on another workspace's project page. A regression case covers an authorized member whose source organization is deliberately mapped to their other workspace. The test remains unrun until plan completion.

## 24 September 2026 — include backup and recovery tasks (verification deferred)

The Deployer activity adapter now contributes running backups, restores, and independent restore verifications for websites attached to authorized mapped environments. It retains current queued/running tasks and only recent terminal history, links to the existing backup history, and leaves detailed evidence and recovery controls in Deployer. The query excludes backup snapshot IDs and stored error text; the regression covers status mapping, old-record bounds, and redaction. This regression remains unrun until plan completion.

## 24 September 2026 — include mapped Deployer operational tasks (verification deferred)

The Deployer provider now also reads scheduled-task runs, database clones whose source and target environments are both explicitly mapped, and server-command state for organization-validated servers. It returns generic statuses and existing result-page links without selecting task output, shell commands, or database errors. Regression cases cover current mapping requirements and sensitive-field omission; tests remain unrun until plan completion.

## 24 September 2026 — include Deployer configuration delivery tasks (verification deferred)

The Deployer activity provider now includes durable configuration operations that have not yet reserved a deployment build. It verifies the operation environment against the authorized Core mapping and verifies the configuration review belongs to that exact Deployer project. Once a build exists, the build activity remains the single feed item for that deployment. Active operations remain visible; terminal pre-build records are limited to 30 days. The result link returns to the existing Deployer project page, while payloads and failure codes are neither selected nor rendered.

A regression now covers queued and failed delivery states, mismatched review projects, duplicate suppression after build reservation, old-record bounds, and payload/failure-code redaction. The test is authored but has not been run, per the user's request to defer tests until the plan is complete. PHP syntax, formatting, view/build checks, and diff checks remain available without running tests.

## 24 September 2026 — include Monitor alert-notification deliveries (verification deferred)

Monitor's activity provider now includes queued, sending, retrying, accepted, failed, uncertain, and canceled alert-notification deliveries associated with currently mapped Monitor incidents. It verifies the incident's monitor or alert-rule environment against the active Core project mapping, then checks both the delivery and destination belong to the mapped Monitor workspace. Monitor owners/admins receive the existing authorized delivery detail/retry link; other members go to the authorized incident page. Alert payloads, destinations, attempt codes, delivery identifiers, and processing tokens are not selected for display; queued, sending, retrying, failed, and uncertain deliveries remain visible, while accepted and canceled history is bounded to 30 days.

The Monitor regression now covers queued, failed, and accepted delivery states, workspace/destination/environment mismatches, old terminal records, manager/member link authorization, and secret redaction alongside telemetry/check/heartbeat activity. This test remains unrun until the complete plan is finished.

## 24 September 2026 — include Deployer preview setup and cleanup (verification deferred)

Deployer preview deployments, preview initialization, and preview stack cleanup now join workspace activity through an unambiguous active Core project-to-Deployer project mapping and current product access. The provider reports generic lifecycle states, a bounded initialization-attempt count, and the existing Deployer preview section link. Active setup/cleanup remains visible, with terminal records limited to 30 days. It does not select or render preview URLs, source branches, revisions, cleanup manifests, or provider errors. Preview activity remains available when the project has no mapped environment; unlinked projects remain excluded.

The regressions cover active preview provisioning, failed preview and initialization, queued and failed cleanup, exclusion of unlinked, stale, and ambiguously mapped project history, existing Deployer result links, and redaction. They are authored but have not been run, per the request to defer tests until the plan is complete. Static syntax, formatting, and diff checks are being used in the meantime.

## 25 September 2026 — include Deployer repository webhook deliveries (verification deferred)

Deployer activity now includes repository webhook deliveries that have not reserved a build. The adapter follows an authorized mapped environment to its website and repository, verifies that the local website and repository belong to the mapped Deployer organization, and checks that every active Core environment mapping for that website resolves to one Core project. Ambiguous shared websites fail closed. Build-linked deliveries stay represented by the canonical build row; unlinked pending receipts remain visible, and terminal skipped, unavailable, and superseded receipts are limited to 30 days. Results link to the existing repository detail screen. The shared activity contains only a generic state and timestamp; delivery identifiers, revisions, commit messages, changed paths, and payloads are neither selected nor rendered.

A deferred regression covers pending, received, blocked, skipped, superseded, old terminal, build-linked, cross-organization, and ambiguously mapped records, including result links and redaction. It has not been run under the plan-wide test deferral. No queue action or retry behavior was added; Deployer remains authoritative for webhook processing.

## 25 September 2026 — include Deployer server diagnostics (verification deferred)

Deployer's existing server diagnostic snapshots now appear in Core activity only through active mapped environments. The adapter verifies the local server organization and checks every active Core environment mapping attached to that server, failing closed when the mappings point at different projects or source organizations. Queued and leased running work remains visible; a running snapshot without a live lease is reported as unknown rather than as active progress. Recent completed diagnostics remain visible for 30 days, and items link to the existing authorized server screen. The Core summary does not select or display diagnostic checks, failure text/stages, retry tokens, or queue internals.

The existing Deployer operational activity regression now includes queued and lease-expired diagnostics, old-history exclusion, server-organization mismatch exclusion, safe result links, and redaction. It is authored but remains unrun until the complete plan is ready. Diagnostic retry and cancellation remain owned by Deployer.

## 25 September 2026 — include Deployer log-refresh state (verification deferred)

The Deployer activity adapters now share one operational resource-map service for safe website and server projections. Website and server log snapshots are summarized using allowlisted categories, their current state, timestamps, and the existing authorized resource pages. Queued and refreshing work remains visible, terminal records are bounded to 30 days, and stale, unsupported, cross-organization, or ambiguous resources are excluded. Log content and error fields are not selected, and Deployer continues to own log retrieval and refresh controls. Recent website-health check outcomes also appear with a generic success or failure, linked to the authorized health-check history and bounded to 30 days. Endpoint URLs, response codes, and health-check errors stay in Deployer. Regression coverage is authored for active and terminal states, unsupported categories, old snapshots/checks, organization mismatch, result links, and redaction; it remains unrun until plan completion.

## 25 September 2026 — include post-deployment observations (verification deferred)

Revision-bound Deployer deployment observations now appear with their mapped build activity. The projection verifies that the observation's build environment and website match the exact active Core environment mapping, retains active observations plus terminal results from the last 30 days, and maps expired worker leases to unknown progress. It links back to the authorized build page without selecting the revision, observation URL, probe results, HTTP evidence, claim token, or error text. The deferred regression covers active and completed states, expired leases, old rows, a mismatched website, result links, and redaction.

## 25 September 2026 — include Deployer load-balancer configuration state (verification deferred)

Deployer activity now projects mapped load-balancer configuration state from the Deployer database. It requires the balancer environment, balancer organization, dedicated server organization, and Core project mapping to agree. Pending configuration remains visible; failed history and recent successful applications are bounded, and the item links to the existing authorized Deployer load-balancer screen. Hostnames and provider error text are never selected or displayed. All load-balancer apply entry points now persist `pending` before dispatch, so an older successful state is not shown as current while a changed configuration is queued; a dispatch failure leaves a generic failed state, and the existing worker continues to record remote apply success/failure.

The deferred regression adds mapped pending/failed/succeeded states, stale-success exclusion, unmapped and cross-organization exclusion, the existing product result link, and hostname/error redaction. It has not been run under the plan-wide test deferral.

Remote removal stays durable in Deployer: the action records `removing` before dispatch, the record remains until the remote Caddy file is removed and validated, and final or dispatch failure records a generic `removal_failed` state. A bounded scheduled reconciler now requeues removals left in `removing` for at least ten minutes, closing the process-crash gap between the database state update and queue dispatch; unique removal jobs coalesce repeated scheduler dispatches, and a composite status/timestamp index supports the scan. Apply-state changes and node mutations now lock the same balancer row, so a removal cannot race a node write. The load-balancer page shows progress, confirms the DNS boundary, and lets managers retry cleanup. Apply and remove jobs share a per-balancer remote-operation lock, and apply completion cannot overwrite a newer removal state. Core maps cleanup statuses to pending/failed workflow activity without reading the hostname or error detail. Removal lifecycle, retry, queue-failure, scheduled recovery, status presentation, shared-lock, and activity regression cases are authored but remain unrun under the plan-wide deferral. PHP lint, Pint, Artisan command discovery, scheduler listing, load-balancer route listing, and `git diff --check` passed. Provider/scheduled-scaling acceptance and the wider I8 inventory remain open.
