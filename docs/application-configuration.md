# Application configuration contract

Version 2 implementation and operator contract. Existing version 1 workflow uploads remain supported. Local verification and rollout instructions are recorded below; deployment to the working application's database and live-provider release verification remain separate.

## Intended workflow

Upload a document, validate it, review a plan, then apply that exact plan. Validation and planning must not modify application state or contact cloud providers. Apply must recheck workspace permission, entitlements, deployment activity, document identity and the state used to generate the plan. A stale plan requires a new review.

The configuration describes project environments, their runtime, named processes, named resources and secret references. It uses logical names for portable topology. Existing servers, websites and secrets are supplied through explicit workspace-scoped bindings; credentials never belong in the document or plan response.

The web authoring page includes a **Current environment overview** before the
binding form. It is a read-only projection of recorded local state: each
environment shows its branch, runtime, server, website, matching repository,
latest recorded build status, processes, resources and masked variable counts.
It does not contact a provider or compare against remote state, and it never
renders commands, variable keys or values, encrypted resource configuration or
provider credentials. Review and apply remain the only paths that can change
configuration.

The same page includes a parser-valid version-2 starter document, an
illustrative bindings object and a concise field guide. The numeric binding in
that example is a placeholder and must be replaced with an ID from the
workspace catalog. The guide does not prefill the editable form, and invalid
YAML/JSON submissions retain the existing secret-safe behavior: submitted
documents and bindings are not flashed into session old input.

Managers can select two environments on the authoring page to view a
**Recorded environment comparison**. The comparison is limited to local
metadata such as type, branch, runtime, protection, dependency identity and
status, process/resource descriptors and masked variable counts. It does not
contact a provider, inspect remote state or claim to detect drift. Commands,
variable keys and values, encrypted resource configuration and credentials are
excluded. Corrective changes still go through a new configuration review and
apply.

Each recorded environment also has an **Observe provider** action. This is an
explicit, manager-authorized one-time read through the existing
`ServerProvider` contract for DigitalOcean, Hetzner Cloud or Vultr. It compares
only the normalized server fields that the contract already returns: provider
identifier, name, region, size, image and public/private addresses. The result
is observed remote state, separate from desired configuration and recorded local
state. A difference is informational; it never writes to BuildPusher, changes a
provider resource or starts reconciliation. No provider request is made when
the normal authoring page or local comparison is opened.

The observation reports **unavailable** when the environment has no
workspace-owned provider placement or provider identifier, and **unknown** when
the provider cannot confirm the current state. Those outcomes do not expose
provider response bodies, credentials or exception details. Fields not exposed
by the shared provider contract remain outside this report, so the feature does
not claim comprehensive remote drift detection.

## Version 2 example

```yaml
version: 2
environments:
  staging:
    type: staging
    placement: staging_site
    runtime:
      type: node
      build_command: npm ci && npm run build
      start_command: npm start
      port: 3000
    processes:
      worker:
        type: worker
        command: npm run worker
        replicas: 1
    resources:
      cache:
        type: redis
        managed: true
    variables:
      API_TOKEN:
        secret_ref: application_api_token
        scope: runtime
```

Bindings resolve `staging_site` and `application_api_token` within the target workspace. A secret reference names an existing secret source; applying it must preserve encrypted storage and normal variable-version history. No implicit production-to-preview secret copying is allowed.

### Preview configuration safety

New pull-request previews use an explicit, preview-owned baseline configuration. It includes a fresh application key, a preview URL and marker, `APP_DEBUG=false`, and independent local database credentials derived from the preview website's generated identity. The source website's encrypted environment text is not a fallback and is never copied into a new preview. This keeps source application keys, provider credentials, mail credentials and other source values unavailable unless a later, separately reviewed preview-secret workflow explicitly approves them.

Preview websites created before this boundary are not rewritten by a migration. The next non-close preview event rewrites the website environment with the safe baseline before the revision is queued; closing and reopening also reaches that safe path. A legacy preview that receives no lifecycle event must be closed and recreated before it is used with untrusted code. Preview configuration is encrypted at rest, and the existing provisioning scripts continue to receive only the preview-owned values. The trust and approval boundaries below govern later preview events without changing this legacy transition behavior.

Preview provisioning now also requires provider pull-request metadata to identify the configured target branch and target repository, and to confirm that the source is not a fork. Forks, cross-repository requests, cross-branch requests and missing trust metadata are rejected before any preview record, child resource or job is created. Close events remain available for cleanup even when a provider omits the metadata needed to execute code. Fork execution is intentionally disabled until host-level isolation can be demonstrated; no secret-scope approval can override that boundary.

Workspace managers may explicitly approve selected runtime/all secret-variable names for an existing preview. The approval records only the exact pull-request revision, source environment and current variable versions; it does not persist plaintext values. A subsequent verified event for that same revision resolves the encrypted values only when every identity, version and scope still matches. Rotation, reclassification, revision changes and preview closure fail closed and require a new approval. Preview-owned application/database credentials are reserved and cannot be overridden. Website environment text, provider access, build/post-deployment commands and dependent-resource configuration are never included in this workflow; dependent resources require their own preview-owned lifecycle boundary.

Supported Laravel presets now declare a preview stack containing queue and
scheduler processes plus managed PostgreSQL and Valkey resources. The preview
lifecycle persists these declarations by stable child name in its existing
transaction and reuses the normal process/resource actions, so repeated
verified webhook events do not duplicate them. The database resource uses the
preview website's generated encrypted password; the source website's
environment and secret values remain excluded. Declarations begin with status
**planned**, move to **provisioning** before the preview build is dispatched,
and move to **ready** or **failed** from the existing signed resource-stage and
failure callbacks. This is durable local lifecycle evidence, not an independent
provider health check. New preview-owned Valkey declarations are loopback-bound
and receive a random `REDIS_PASSWORD` stored in the encrypted resource
configuration; the managed-resource script shell-escapes it for
`--requirepass`. Existing preview resources preserve their current credential
state, including legacy passwordless containers, so a revision update does not
rotate a live resource without a coordinated migration.

Preview stack children are explicitly marked as preview-owned. When a preview
closes or expires after it is idle, BuildPusher captures the original
environment, website, server and deployment-slug identities plus only exact,
non-secret process/resource identifiers in a durable cleanup record. A unique
leased job then performs bounded, retryable cleanup for the generated worker/
scheduler units, PostgreSQL database/role and Valkey container/volume. Repeated
events are idempotent; a reopened preview cannot retarget an old cleanup; manual
or shared children are excluded; invalid identities fail closed; and cleanup
failures remain visible for manager-authorized retry. Existing generic
website/Caddy/MySQL cleanup remains a separate operation. The migration adds
ownership flags with a false default, so historical children are deliberately
not treated as preview-owned without a later explicit declaration.

Concurrent preview capacity is organization-scoped and is checked in the same
retrying transaction that creates a new preview stack. `PlanLimits` counts
previews whose lifecycle is not closed; a closed preview releases capacity only
when `closed_at` is recorded. A legacy closed row with no closure timestamp is
counted fail-safe. The configured concurrent-preview limits are `0` for Free
and Starter, `5` for Pro, `10` for Team, `20` for Business and unlimited for
Unlimited. The lock-version column added to organizations is internal
write-side serialization state for SQLite-compatible locking; it is not a
reservation counter, and active usage remains derived from preview records.
Capacity denial preserves the existing `preview_limit_reached` response and
creates no preview children or job.

Supported Laravel preview templates explicitly declare the curated
`php artisan db:seed --force` initialization command. It runs once after the
first candidate release is active, within the existing post-deployment stage,
and writes a marker only after success. Its encrypted payload is bound to the
preview revision and build attempt; failed or interrupted attempts are
retryable, while stale callbacks cannot complete a newer attempt. The command
must be safe to repeat because remote execution is at-least-once. The default
is sample-data initialization; production-data copying is not part of this
workflow. Existing preview rows are migrated as `not_configured` and are not
retroactively executed; a later revision change can opt them into the curated
command. Templates without a declaration remain unconfigured.

The existing manager-authorized **Observe provider** read now includes the
provider's normalized server readiness and safe lifecycle state when the
selected DigitalOcean, Hetzner Cloud or Vultr adapter reports them. A server is
shown as ready only when the provider reports its running state and a public
address; missing lifecycle data is unknown and a reported stopped/off state is
not ready. This is a one-time observation, not persisted desired state.

Provider readiness remains distinct from BuildPusher's local preview/resource
callbacks. It does not prove SSH access, application health,
PostgreSQL/Valkey/process health, remote drift or successful cleanup. Provider
credentials, live state transitions and the separate acceptance drill still
require external verification.

External resources (`managed: false`) accept `variable_refs`, mapping connection-variable names to secret binding names, for example `variable_refs: {AWS_SECRET_ACCESS_KEY: storage_key}`. Sources must permit runtime use. Values are copied into encrypted resource configuration and deployment snapshots, never into the document or plan response. An explicit empty map clears those resource variables; omitting the map preserves existing external-resource configuration. Managed resources reject this override.

Changing an existing resource's type or management mode requires detaching it in a separate reviewed apply before attaching the replacement. This workflow does not migrate remote data or reuse old credentials across incompatible resource types.

New deployment snapshots record whether each resource is managed. External resources never request managed provisioning, including external connection references using localhost. Historical snapshots without this flag retain their original behavior. An environment supports one managed Valkey resource because its port is assigned per environment; omitted existing resources count toward that limit. Detachment preserves the remote container and its occupied port: use the remote service's cleanup workflow before deploying a replacement under another name.

## Validation and ownership

- Reject unknown fields, unsupported versions, malformed names, duplicate YAML keys, invalid runtime combinations and unresolved references before planning changes.
- Bound document size to 50,000 bytes, parser nesting to 12 levels and expanded structure to 10,000 nodes. Reject YAML aliases and merge aliases before expansion, as well as object tags. Literal commands can contain YAML metacharacters when quoted or written as block strings.
- Match runtime/process/resource limits to existing environment validators, with explicit errors rather than clamping invalid values.
- Recheck all resource ownership in the application service, including direct callers; controller authorization alone is insufficient.
- Show logical changes and affected names in plans. Omit secret values and existing encrypted command/configuration contents from responses and activity records.
- Process/resource names are at most 50 characters; environment, variable and binding names are at most 100. Numeric/boolean strings are rejected rather than coerced. Node/Python require a nonblank start command and port; Docker requires a valid relative Dockerfile path.
- Active preview environments and their website bindings remain owned by the preview lifecycle. Close that preview before adopting or managing its environment/target with configuration. This prevents two workflows from changing the same branch or deleting the same target.

## Reconciliation and recovery

Objects created by configuration carry durable ownership metadata identifying their project, logical name and configuration revision. Existing manual objects require explicit adoption before reconciliation can modify them. Duplicate logical names must be prevented by database constraints.

Set `adopt: true` on each existing environment, process, resource or variable that may be adopted. Adoption of an environment does not adopt its children. The plan shows `adopt` for those manual objects and `adoption_required` for objects lacking consent. This flag is part of the reviewed document fingerprint; it cannot be added at apply time. It is harmless for newly created or already-managed objects, so the document remains reusable. Adoption never overrides a conflicting ownership record.

Omitting an object preserves it. Removal uses an explicit removal list included in the reviewed plan. Removing a datastore never silently deletes its contents; plan output distinguishes detachment from remote destruction, and remote data deletion remains a separate explicit operation.

Within an environment, use `remove: {processes: [worker], resources: [cache], variables: [API_TOKEN]}` to remove named configuration-owned children. An object cannot be declared and removed in the same document. Manual objects must first be adopted in a separate reviewed apply. A resource removal is shown as `detach` with `remote_data_deleted: false`; a previously removed object is shown as `absent`, allowing safe retries.

Whole-environment removal uses a root list:

```yaml
version: 2
remove:
  environments: [staging]
```

Supply `bindings: {}` to the API, or `{}` in the browser's bindings field. Other environments may be declared in the same document; a slug cannot be both declared and removed. Omitted environments remain untouched. The plan enumerates each process/variable removal, resource detachment and the environment itself. It explicitly reports `remote_data_deleted: false` and `remote_services_changed: false`.

Removal requires configuration ownership of the environment and every child, with no stale or conflicting ownership. Production/protected environments, active builds, unfinished configuration operations, deployment/scaling schedules, scheduled tasks, load balancers and active/incompletely closed previews block removal. Resolve those dependencies through their own workflows, then create a new review. Revalidation occurs inside the apply transaction, including after concurrent changes.

Applying removal deletes the listed local environment configuration and secret-version history. Websites, servers, repositories, remote workloads/data, build history and configuration receipts remain; nullable historical environment links are cleared. **Removal does not stop workloads or reduce provider charges.** A repeated request for the same applied review returns its receipt; a new removal review for an absent environment is a safe no-op. Injected failure after the actual local cascade rolls back the environment, children, versions, ownerships and receipt together.

Local configuration changes apply in one transaction under project and affected-resource locks. Persist an application record and intended remote operations in the same transaction; workers consume durable operations after commit. Repeated apply of the same document and bindings must produce no duplicate objects or jobs. Remote failure leaves inspectable operation state and supports retry; it must not be reported as a successful remote deployment merely because local configuration was saved.

## Completion evidence

1. Recreate a fixture project using different valid workspace bindings and compare its non-secret topology.
2. Plan without mutation; apply exactly the reviewed plan; reject stale plans and changed permissions.
3. Reapply without duplicate resources, secret versions or remote operations.
4. Reject foreign bindings, unknown fields and unsupported runtime/resource combinations.
5. Preserve omitted manual objects; explicitly review adoption and removal.
6. Demonstrate transactional rollback and recovery of failed remote operations.
7. Provide the same validation, planning and application behavior through the web workflow and automation interface, with operator documentation.

Implementation remains on this feature until these requirements have direct evidence. The live paid-provider release drill remains separately deferred.

### Verification scope

| Requirement | Local evidence |
| --- | --- |
| Recreate non-secret topology using different bindings | `ApplicationConfigurationPortabilityTest` |
| Read-only plan, exact reviewed apply, permission/freshness checks | Planner, bindings, transaction and managed-placement tests |
| No duplicate objects, versions, receipts or deployment intents | Transaction, deployment, retry and independent-process concurrency tests |
| Strict input/ownership/resource handling and encrypted secrets | DocumentSafety, ResourceSafety, Ownership and Variables tests |
| Omission, explicit adoption and complete removal | EnvironmentRemoval and RemovalWorkflow tests |
| Atomic failure recovery and inspectable remote failures | Transaction, EnvironmentRemoval, Retry and Cancellation tests |
| Web/API parity and operator workflows | Web, API, RemovalWorkflow, Retry and Cancellation tests; `public/openapi.json` |
| Upgrade, rollback and reapply without corrupting existing data | Migration test using a disposable populated SQLite database |

Concurrency tests use independent PHP processes and connections to a real temporary SQLite database. They hold transactions open while competing requests begin, covering same-review receipt reuse, stale distinct reviews, concurrent explicit retry producing one replacement build, a deployment committing during removal, and a manual child appearing during removal. SQLite takes a write reservation before reading reviewed state; row-locking databases use project/resource locks, with bounded transaction retries. These tests do not constitute MySQL/PostgreSQL engine certification. Test the deployed database engine during its rollout rehearsal.

Browser checks render the real removal-review, configuration upload/history and applied-receipt responses with built CSS and Livewire at 320, 390, 768 and 1440 pixels in light/dark modes (24 layouts). They check the removal warning, history links, reachable apply/cancel buttons, no horizontal overflow and no JavaScript errors. HTTP tests separately exercise submission/review/apply and receipt/retry/cancel behavior.

## Operation processing (internal implementation)

An environment can request `deploy: {repository: app}` with an explicit `repositories.app` binding to a deployment-ready repository on its bound website. Apply captures an encrypted deployment snapshot and records `awaiting_dispatch`; saving configuration is not remote success.

`php artisan buildpusher:configuration:process --limit=100` processes up to 100 due operations (valid limit: 1–500), observes approval gates, retries queue delivery using the same build, and synchronizes terminal build results. Exception details and secret snapshots are not printed. Failed remote builds remain failed; this processor does not automatically start a replacement deployment. The scheduler runs every minute without overlap after the receipt tables and retry columns exist. The application scheduler and deployment queue worker must both be running; this change does not install or start those processes.

Repository identity includes deployment inputs (repository/workspace/provider/website identity, source URL, branch, command values, website server/deployment slug and base environment); webhook bookkeeping and re-encryption of unchanged commands do not create a new intent. Managed database credential, deployment-slug and server-address changes invalidate a pending review. The website's base environment is captured with resource/variable values in the encrypted build payload, including an intentionally empty base; historical payloads retain their previous fallback. Build serialization hides this payload. Permission, entitlement, target, repository and deployment gates are checked again when reserving a build and immediately before a queued configuration build starts remote execution. A newly required approval is never inherited from a prior attempt.

### Recovering an operation

Open a recent receipt from the configuration page, the saved review's URL, or its application endpoint. Receipt reads synchronize recorded build outcomes. Status and failure codes exclude exception details and secrets. Workspace managers can inspect applied receipts and cancel pending work after its original requester loses access; unapplied reviews remain private to their requester.

| Situation | Operator action |
| --- | --- |
| `delivery_failed` / expired delivery lease | Restore the queue connection and run/wait for the processor. It reuses the existing build. |
| `awaiting_approval` | Approve or reject the build using existing deployment controls. |
| `blocked` by deployment activity/window/lock | Resolve the named gate and let the processor recheck. |
| `blocked` by changed repository/target/access/entitlement | Restore the reviewed prerequisites, or cancel the old pending intent and submit a new review. |
| Recorded failed/canceled deployment | Select **Retry failed deployment** or POST the retry endpoint. |
| Unwanted pending intent | Select **Cancel pending deployment** or POST the cancel endpoint. |
| Remote execution already running | Use the build's existing deployment cancellation controls. Configuration cancellation refuses running/deploying/timing-out builds. |

An explicit retry preserves the old operation/build and creates one durable replacement with the same encrypted configuration snapshot. Current inputs and access must still match; otherwise submit a new review. Repeating the retry request returns that same replacement, including after it completes. A subsequent failure requires explicitly retrying the replacement operation ID. Shared application receipts include the replacement and retain the original history. A canceled intent without a build can likewise be retried when its reviewed prerequisites are still valid. Cancellation preserves saved local configuration and remote services; repeating cancellation is safe.

## Web and API workflow

Workspace owners and administrators can open **Configuration as code** from an application page. Submit YAML and JSON bindings, review the named changes, then apply the saved review. The binding reference lists workspace website IDs, secret-variable IDs and repository IDs without exposing credentials. Reviews expire after 15 minutes. Changed state or revoked access requires a new review; submitted commands are not flashed into the browser session on validation errors.

API requests use the existing `/api/v1` authentication, API entitlement and workspace network policy. A token needs the `manage` ability and its user must have workspace management access.

| Method and path (under `/api/v1/projects/{project}`) | Request | Result |
| --- | --- | --- |
| `POST /configuration/plan` | `document` YAML string and `bindings` object | Read-only plan; no saved review |
| `POST /configuration/reviews` | Same input | Review ID, plan and expiry (HTTP 201) |
| `POST /configuration/reviews/{review}/apply` | Empty body | Application receipt; repeated requests reuse the receipt |
| `GET /configuration/applications/{application}` | No body | Stored application and operation status, without secret payloads |
| `POST /configuration/applications/{application}/operations/{operation}/retry` | Empty body | Receipt plus `retry_operation_id`; repeated requests reuse the replacement |
| `POST /configuration/applications/{application}/operations/{operation}/cancel` | Empty body | Receipt with pending intent canceled; no remote process is stopped |

Example binding shape: `{"placements":{"staging_site":12},"secrets":{"application_api_token":34},"repositories":{"app":56}}`. IDs must be JSON integers, not strings. Only include references applicable to your document. Apply rejects replacement documents or bindings: create a new review instead.

`locally_applied` confirms local configuration only. `awaiting_dispatch` and `awaiting_approval` require further processing or approval. `deploying` is not success. `succeeded` reflects recorded successful build results; `remote_failed` preserves failed or canceled deployment outcomes. Separate unchanged reviews share the latest matching deployment operation, including a prior failure; they do not silently retry a failed remote deployment.

Retry requires the original review requester to retain management access in the current workspace. Cancellation is available to any current manager of that workspace, so revoked or deleted requesters cannot leave uncancelable pending work. Both accept only the operation identity and never replacement commands, bindings or credentials. Deleting a requester preserves the receipt with a null requester, which blocks further execution. Configuration builds retain an encrypted operation identity; if their review/operation is removed, queued builds are canceled instead of falling back to ordinary deployment execution.

## Rollout and rollback

All six configuration migrations (`2026_09_06_010000`–`060000`) were rehearsed against a disposable populated SQLite database, including an existing operation receiving the retry upgrade. Existing application data and build history survive migration rollout, rollback and reapply. The retry migration refuses rollback once retry history exists, preventing a lossy collapse into the older unique identity.

The working application's six migrations were applied on September 8 after a rehearsal on a copy of its live SQLite database; see [the rollout record](verification/configuration-rollout-2026-09-08.md). The instructions below remain applicable to other installations. No provider actions or live operation processing were performed. Deploy code and install production dependencies with the updated lockfile (`symfony/yaml` is now required outside development). During the normal deployment maintenance window, back up the database, apply all migrations, restart queue workers, and verify the scheduler/worker configuration before submitting a fixture review. Rehearse against the actual deployment database engine before rollout.

Rollback before feature usage can use the tested migration downs. After users create reviews, applications or retry history, preserve those records and encrypted payloads in the backup; prefer rolling code forward with a corrective migration. Do not drop the configuration tables as an operational recovery action. The separately authorized paid-provider release drill, restored-data verification and provider-side cleanup remain release gates, not claims made by local tests.

### Configuration delivery scheduling

The daemon installer installs `lessbuild-configuration.timer`, which runs `buildpusher:configuration:process --limit=100` every minute using the selected PHP executable. Its oneshot service does not overlap itself and skips runs while the file-based maintenance marker exists. The worker must also be running to execute queued deployment jobs. Check both the timer and its service result: an active timer alone does not establish successful processing.

Installations already running Laravel's complete scheduler use the existing configuration-processing schedule in `app/Console/Kernel.php`; choose one scheduling mechanism rather than enabling a duplicate timer. The dedicated timer does not enable unrelated backup, scaling or provider jobs from the full Laravel schedule. A successful empty pass establishes command/runtime/schema readiness, not live deployment success.
