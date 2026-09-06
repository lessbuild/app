# Application configuration contract

Version 2 implementation and operator contract. Existing version 1 workflow uploads remain supported. Local verification and rollout instructions are recorded below; deployment to the working application's database and live-provider release verification remain separate.

## Intended workflow

Upload a document, validate it, review a plan, then apply that exact plan. Validation and planning must not modify application state or contact cloud providers. Apply must recheck workspace permission, entitlements, deployment activity, document identity and the state used to generate the plan. A stale plan requires a new review.

The configuration describes project environments, their runtime, named processes, named resources and secret references. It uses logical names for portable topology. Existing servers, websites and secrets are supplied through explicit workspace-scoped bindings; credentials never belong in the document or plan response.

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

The working application's six migrations remain **pending** as of this verification. No provider actions or live operation processing were performed. Deploy code and install production dependencies with the updated lockfile (`symfony/yaml` is now required outside development). During the normal deployment maintenance window, back up the database, apply all migrations, restart queue workers, and verify the scheduler/worker configuration before submitting a fixture review. Rehearse against the actual deployment database engine before rollout.

Rollback before feature usage can use the tested migration downs. After users create reviews, applications or retry history, preserve those records and encrypted payloads in the backup; prefer rolling code forward with a corrective migration. Do not drop the configuration tables as an operational recovery action. The separately authorized paid-provider release drill, restored-data verification and provider-side cleanup remain release gates, not claims made by local tests.
