# Managed-resource preparation — 2026-09-17

## Problem and boundaries

The deployment plan currently runs Laravel migrations at stage 7 and creates
managed resources at stage 11. On a first deployment, migrations can therefore
run before their managed PostgreSQL database exists. Dependency installation
hooks and custom build commands may also require those resources.

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

After committing and pushing this extraction, the exact next task is an
executable first-deployment regression and the separate early-preparation fix.
No cloud host has been created for this work.
