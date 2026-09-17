# Managed Valkey start failures — 2026-09-17

## Problem and responsibility boundary

Preview acceptance review found that `ConfigureResourcesScript` used
`docker start ... || true` when a managed Valkey container already existed.
A failed restart therefore continued to the successful resource-stage callback.
That callback can mark preview resources ready despite the failed operation.

Affected entry points are deployments with managed Valkey resources, including
configuration-as-code and preview deployments. The script is rendered from the
immutable build environment snapshot and executed by `PublishRepositoryAction`
under `set -Eeuo pipefail` with the existing failure callback.

This is a bug fix, not a structural extraction. Single responsibility remains
with the existing command renderer; the deployment action owns execution and
the callback lifecycle owns persisted outcomes. No additional interface,
service, request or policy is needed.

## Change and compatibility

The existing-container start no longer suppresses its exit status. A failed
restart now stops before the success callback and uses the deployment's existing
failure path. Successful starts and new-container creation are unchanged.

Snapshot compatibility, management intent, image, container/volume names,
loopback port binding, encrypted credentials, shell quoting, signed callbacks,
statuses, retry/lease behavior, job serialization, routes and response contracts
are preserved. No schema or dependency change is needed. Error output remains
suppressed as before; no raw Docker response or password is added to logs.

## Verification

Work used the independently configured `buildpusher-preview-cleanup-Amr47o`
clone documented in [the PostgreSQL record](preview-postgresql-cleanup-2026-09-17.md).

- Fresh focused baseline: 16 tests / 171 assertions passed.
- New executable Bash regression: new-container success, existing-container
  success and new-container failure passed; existing-container failure failed
  against the old code because its exit status was incorrectly zero.
- After correction, all four cases passed. They check the actual generated
  command's exit status and whether the resource success callback occurs,
  including exact container/port/volume identity and a quoted test password.
- The protocol double intercepts Docker, package/service operations and curl.
  No Docker daemon, remote server or real callback is used by these tests.
- Related configuration resource, preview readiness, template, cleanup and
  PostgreSQL coverage: **31 tests / 261 assertions passed**.
- Changed-file Pint, PHP syntax checks and `git diff --check` passed.
- Full strict PHP suite: **1,551 tests / 12,974 assertions passed**, with zero
  failures, errors or skipped tests. Failure-on-warning/risky/deprecation flags
  were enabled. JUnit records 455.34 seconds; log and XML are retained in the
  isolated clone at `storage/logs/valkey-start-tests.log` and
  `storage/logs/valkey-start-junit.xml`.
- Full Pint passed. Lockfiles remain unchanged; this command-generation-only
  slice does not replace the September 16 browser acceptance checkpoint.

This proves error propagation, not Valkey protocol readiness, persistence,
full preview-stack acceptance or PostgreSQL/Valkey restore recovery.

## Publication and next task

Bug-fix commit `14e3bf5` was pushed and integrated into canonical `main` and the
dev runtime, preserving the existing untracked controller plan.

The separate renderer extraction and first-deployment preparation correction
subsequently landed as `b3070a3` and `f3cd675`, without renumbering the 15
callback stages. See [their record](managed-resource-preparation-2026-09-17.md).
Continue the remaining disposable configuration/preview acceptance sequence.
The documented configuration-specific review/apply/delivery/idempotency/recovery
checks precede the full provider-backed preview cycle. No new droplet or other
billable resource was created for this bug-fix slice.
