# Managed-resource preparation — 2026-09-17

## Problem and boundaries

At the starting checkpoint, the deployment plan ran Laravel migrations at
stage 7 and created managed resources only at stage 11. On a first deployment,
migrations could therefore run before their managed PostgreSQL database existed.
Dependency installation hooks and custom build commands may also require those
resources.

Changing the shared stage order directly would reinterpret persisted progress,
running callbacks, preview readiness and deployment timelines. Preserve all 15
stages and their meanings instead. Prepare resources without a stage callback
before dependency installation, then retain the normal idempotent resource
stage and its existing callback. Record the timing change separately from the
structural extraction below.

## Slice A: command-rendering extraction

`ConfigureResourcesScript` combined resource command construction with stage
reporting. `ManagedResourceScript` now owns only construction from the immutable
resource snapshot; the existing deployment script injects it and appends the
same signed callback. Single responsibility separates reusable remote commands
from progress reporting, and constructor injection makes the collaborator
explicit. No new interface, generic orchestrator or provider framework is used.

This slice intentionally changes no execution timing. Legacy management intent,
external-resource exclusion, validation, escaping, generated identifiers,
password handling, package/service commands and the Valkey failure correction
from `14e3bf5` are unchanged. The service performs no queries, writes or calls.

Verification in the isolated `buildpusher-preview-cleanup-Amr47o` clone:

- Five frozen-time generated-script comparisons are byte-identical before and
  after extraction: empty, legacy, explicitly managed, external and invalid
  identity inputs. This includes the signed progress callback.
- The existing execution/contract tests passed **36 tests / 391 assertions**,
  including managed Valkey success/failure, PostgreSQL, external credentials,
  preview readiness/cleanup, template lifecycle and provisioning contracts.
- Changed-file Pint and `git diff --check` passed.
- The immediately preceding full strict baseline is **1,551 tests / 12,974
  assertions**, with full Pint passing. It predates this extraction; the focused
  run and identical generated output establish this slice's verification.
- No dependency, schema, route, callback stage, serialized job or runtime
  configuration changed.

Extraction commit `b3070a3` was pushed and integrated into canonical `main` and
the dev runtime before the timing fix below. No cloud host was created.

## Slice B: first-deployment preparation

`InstallDependenciesScript` now injects the same renderer and prepares the
captured managed resources before dependency hooks and custom runtime builds.
Laravel migrations run later against resources that have already been created.
The original stage 11 still reconciles the same identities idempotently and
sends its original callback. Preparation does not emit an early resource
callback, change any stage number or change historical progress interpretation.

This is an intentional bug fix to remote execution timing. A later dependency
or application failure can now leave already-prepared managed resources; they
retain their captured identities for retry and the existing ownership-aware
preview cleanup. No remote-resource rollback or exactly-once guarantee is
introduced. External resources remain excluded and legacy snapshots retain
their original management-intent interpretation. Credentials, reviewed inputs,
queued jobs, leases, cancellation, request contracts and persistence schemas
are unchanged. No credential is copied from another environment.

Verification:

- New executable first-deployment cases failed against `b3070a3` for managed
  and legacy snapshots and for the intended preparation-failure path. The
  external-resource case already passed.
- All four cases pass after the fix, checking database-before-hook ordering,
  migration ordering, unchanged callbacks at stages 4/7/11, external-resource
  exclusion and stopping before hooks/callbacks when preparation fails.
- Related provisioning, configuration, resource, preview, deployment-hook,
  approval, health, callback, monorepo-root and timeline coverage passed
  **71 tests / 659 assertions**.
- The full strict PHP suite passed **1,555 tests / 12,986 assertions**, with
  zero failures, errors or skipped tests. JUnit reports 733.58 seconds; the
  isolated clone retains `storage/logs/resource-preparation-tests.log` and
  `storage/logs/resource-preparation-junit.xml`.
- Full Pint, syntax checks and `git diff --check` passed. Composer manifest
  validation and locked-platform checks passed using PHP 8.5.10, with plugins
  and scripts disabled. The system Composer libraries emitted their existing
  deprecation notices; lockfiles were unchanged.
- Browser/asset checks were not repeated for this PHP command-generation
  change. The September 16 browser record remains the preceding UI checkpoint,
  not new evidence for the unexecuted full preview workflow.

### Real PostgreSQL evidence

The previously extracted PostgreSQL 16.15 binaries ran a disposable cluster as
`nobody`, with a private Unix socket and no TCP listener. The generated commands
created the first-deployment database and role. A dependency-hook double then
connected as that role, and a migration double wrote a real SQL marker. This
verifies the database boundary, not execution of a complete Laravel application.

The same generated sequence succeeded twice; callbacks stayed at 4/7/11 and
the marker survived. The generated preview cleanup then removed that database
and role while an unrelated database/role/marker remained intact. All smoke-test
objects were removed, the temporary server was stopped, and its empty socket
directory was removed. No host database service or cloud resource was installed.

The private, non-secret harness and fixture are retained below
`/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-postgres-check-kCvubY/`
as `prepare.php` and `preparation-fixture-cRrTV5/`. Its socket location was
disposable; recreate a private socket directory before any future rerun.

After this timing fix is committed and pushed, resume the documented
configuration-specific provider acceptance before the full preview cycle.
Complete Laravel/worker/PostgreSQL/Valkey deployment, provider lifecycle and
broader recovery acceptance remain distinct from these local checks.
