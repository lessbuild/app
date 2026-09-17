# Preview PostgreSQL cleanup — 2026-09-17

## Problem and scope

Continuing the provider-backed preview acceptance review from `main` at
`5d00873` found a remote cleanup defect. `PreviewStackCleanupScript`
submitted `DROP DATABASE` and `DROP ROLE` inside one `psql --command`.
PostgreSQL executes a multi-statement command as a transaction, and rejects
`DROP DATABASE` in that transaction. A closed preview could therefore retain
its owned database and role while the cleanup job kept failing.

The [PostgreSQL psql command documentation](https://www.postgresql.org/docs/16/app-psql.html#APP-PSQL-OPTION-COMMAND)
describes the transaction boundary and the use of repeated command options.

Affected entry points are preview close/expiry and the existing authorized
cleanup retry, through `QueuePreviewStackCleanupAction`,
`CleanupPreviewStackJob`, `CleanupPreviewStackAction` and
`PreviewStackCleanupScript`.

This is a bug fix within the existing single-responsibility boundary: the
script service owns remote command construction, the action owns execution,
and the job owns leases, retries and completion. No new extraction or
interface is needed.

## Isolation

Implementation and tests used a separate clone on its own `main`:

```text
/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-preview-cleanup-Amr47o
```

The clone has independently copied locked dependencies and built assets, a
new application key, its own storage and no copied credentials. Before Artisan,
configuration bootstrap asserted SQLite `:memory:`, array cache/sessions,
sync queue, array mail, clone-local storage and no cached configuration.
The canonical checkout's pre-existing untracked controller plan was preserved.

For real SQL evidence, Ubuntu PostgreSQL 16.15 packages were downloaded and
extracted into a separate temporary directory without installing a host
database service. A fresh cluster ran as `nobody`, with a private Unix socket,
no TCP listener and small memory limits. The application database remained
SQLite in memory. The generated cleanup script ran against this disposable
cluster; systemd operations were intercepted and the `sudo -u postgres`
wrapper selected the cluster's test administrator.

Tooling note: invoking the host's `lxc` discovery wrapper unexpectedly
installed the LXD snap. No containers were created. That newly installed snap
was subsequently removed, with its automatic recovery snapshot retained.
PostgreSQL verification did not use LXD.

## Fix and preserved behavior

Database and role deletion now use separate `--command` options on the same
`psql` invocation. The database is removed first; `ON_ERROR_STOP=1` and
Bash error propagation stop cleanup if either operation fails.
`IF EXISTS` permits retry after partial completion or a previously completed
deletion.

Exact captured identities, identifier validation and quoting, ownership
selection, shared-resource exclusion, process and Valkey cleanup, the planned-
resource fallback, authorization, routes, response/flash formats, persisted
states, job payloads, claim tokens and leases are unchanged. No schema,
dependency or lockfile change was needed.

## Verification

- Fresh focused baseline: 13 tests / 136 assertions passed.
- Three new Bash execution regressions failed against the original generator.
- Corrected cleanup/readiness/template suite: 16 tests / 142 assertions passed.
- Real PostgreSQL reproduction: the original generated script failed with
  `DROP DATABASE cannot run inside a transaction block`; both owned
  objects remained.
- Real PostgreSQL success: the fixed generated script removed the exact
  database and role, and a repeated invocation succeeded. An unrelated
  database, role and data marker were preserved.
- Real PostgreSQL partial failure: an additional database owned by the role
  prevented role deletion after the preview database was removed. Cleanup
  returned failure, preserved the dependency, and succeeded on retry after
  that dependency was deliberately removed.
- Real PostgreSQL denial: a non-owner could not remove the database, and
  execution stopped before role deletion.
- All disposable smoke-test databases and roles were removed, the temporary
  server was stopped, and its empty socket directory was removed.
- Full strict PHP suite: **1,547 tests / 12,962 assertions passed** in
  603.78 seconds, with failure-on-warning/risky/deprecation flags enabled.
  The log and JUnit report are retained under the isolated clone's
  `storage/logs/preview-cleanup-tests.log` and
  `storage/logs/preview-cleanup-junit.xml`.
- Full Pint, changed-file PHP syntax checks and `git diff --check` passed.
  Composer and npm lockfiles are unchanged. Browser checks were not repeated
  for this PHP command-generation fix; the September 16 UI evidence remains
  the preceding browser checkpoint.

No cloud resource or billable operation was created for this slice. This
proves the PostgreSQL command and retry behavior locally; the full preview
stack, provider lifecycle and PostgreSQL/Valkey recovery acceptance remain open.

## Publication and next task

Publication is recorded in the product-expansion ledger after verification.
The next concrete runtime correction is the isolated dev queue worker's
restart policy: its normal one-hour exit currently leaves it stopped. The
repository installer already declares `Restart=always`; the isolated unit
must match that contract. Afterwards, continue the remaining preview and
recovery acceptance checks.
