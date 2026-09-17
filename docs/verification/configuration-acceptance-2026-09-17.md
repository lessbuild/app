# Configuration-specific provider acceptance — 2026-09-17

## Scope

This record covers the configuration-as-code workflow on the isolated dev
runtime: plan, review, exact apply, delivery, idempotency, freshness,
workspace ownership, approval, cancellation, explicit retry, a reversible
remote health failure, local environment removal and cleanup of all disposable
provider resources.

The runtime used its own application key, SQLite database, storage, cache,
dependencies and database queue worker. The GitHub and DigitalOcean
connections were the already authorized dev connections. No credential
material or secret values are included here.

## Responsibility boundaries

The test exercised the existing boundaries rather than adding an acceptance-
only path:

- API requests validated the versioned YAML and explicit binding arrays.
- The configuration planner and reconciler owned change construction and
  local state reconciliation.
- Review/application services owned exact-input identity, source-secret
  freshness, ownership checks, no-op identity, atomic claims and leases.
- Delivery and result services owned durable build dispatch and terminal
  outcome reconciliation.
- Policies plus `control-plane:manage` authorization protected the API.
- Existing provider adapters and server/website/repository/project actions
  owned remote communication and cleanup.

This is single responsibility and dependency inversion through the existing
planner, application, delivery, result, provider and action collaborators.
No new interface or generic repository was introduced for the acceptance.

## Disposable resources

| Resource | Identifier | Result |
| --- | ---: | --- |
| BuildPusher project | `9` | Removed through `DeleteProjectAction` |
| Staging environment | `15` | Removed by configuration review/apply |
| Website | `11` | Removed by the existing website cleanup job |
| Repository | `9` | Soft-deleted, then finalized by website cleanup |
| BuildPusher server | `16` | Removed through `DeleteServerAction` |
| DigitalOcean droplet | `601315670` | Absent in post-cleanup inventory |
| GitHub fixture revision | `375d556fa50e4f76b880f59f075995bf554036a8` | Used for all deployment attempts |

The seeded provider connections and unrelated demo resources were not
modified. Post-cleanup DigitalOcean inventory contained no droplet with the
disposable identifier or acceptance name prefix and no matching acceptance
SSH key.

## Workflow evidence

### Plan and successful delivery

The initial version-2 plan returned HTTP 200 with five changes and did not
mutate state. Review `1` applied as application `1` and operation `1`.
Processing created build `30`, which succeeded. A real HTTPS request to the
disposable website returned exactly `hello world v9`; the response SHA-256 was
`4c8a16eb64c35d6e94867485acedd39130ff69e2ea17e6ab95917862841bb090`.

### Idempotency

Applying review `1` again returned application `1` and operation `1`, with no
new build or remote resource. Unchanged review `2` created application `2` but
reused operation `1`; the secret-version count and build count did not grow.

### Freshness and ownership

Review `3` was created from the original document, then the source secret was
rotated through the existing environment-variable action. Applying the old
review returned HTTP 422 with the existing “configuration changed after this
review” response. No new application, operation or build was created, and
the rotated value was not present in the response.

A plan using a temporary website from another organization as a placement
binding returned the existing generic HTTP 422 binding error. The foreign
website and server were removed afterward; no cloud resource was created for
that check.

### Approval, cancellation and retry

With the staging environment’s deployment-approval gate enabled, review `4`
created application `3` and operation `2`. Processing created an awaiting-
approval build. Two cancellation requests were idempotent: the operation and
build ended canceled and no remote process started.

After restoring the approval gate, the first explicit retry created operation
`3`/build `32`; a repeated retry returned the same replacement. The replacement
completed successfully at the same fixture revision.

### Reversible failure and recovery

An earlier missing-path attempt was not counted as failure evidence because
the existing Caddy/PHP fallback served the application entry point. The valid
failure test instead kept the health check enabled for `/` and stopped Caddy
only on the disposable host.

Review `6` created application `5` and operation `5`. Build `34` reached the
remote deployment and failed the final health check at
`2026-09-17T07:07:55Z` with the sanitized message
`Deployment health check failed (exit code 1)`. Result refresh marked operation
`5` failed and the application `remote_failed`. No automatic replacement was
created.

Caddy was restored, then the explicit retry endpoint created operation `6` /
build `35`. Repeating the retry returned operation `6` again. Build `35` and
the application succeeded at `2026-09-17T07:09:17Z`.

### Environment removal

The removal plan returned HTTP 200 with four local changes: process removal,
resource detachment, variable removal and environment removal. Every change
reported `remote_data_deleted=false` and `remote_services_changed=false`.

Review `7` applied as local-only application `6` with no operations. The
staging environment, process, resource, target variable and configuration
ownership rows were absent afterward, while the independently owned website,
repository, server and remote data remained until cleanup. Reapplying review
`7` returned the same application. New absent-environment review `8` applied
as local-only application `7` with no operations.

## Cleanup and limitations

The repository was soft-deleted, the website deletion action queued its normal
Caddy placement cleanup, and the queue worker finalized both website and
repository records. `DeleteServerAction` then removed the DigitalOcean
droplet and application-owned SSH key before deleting the local server row.
The disposable project was deleted last, removing the source environment and
its encrypted variable records. The queue was empty after cleanup.

Two failed jobs remained in the isolated dev database, both predating this
run: `CreateWebsiteBackupJob` and `AddWebsiteJob`. They were not caused by this
acceptance sequence.

This record does not claim completion of provider-backed preview-stack
readiness, managed PostgreSQL/Valkey recovery, the generic release/rollback/
backup/restore drill, production acceptance or the separate live acceptance
drill. Those are separate release gates.
