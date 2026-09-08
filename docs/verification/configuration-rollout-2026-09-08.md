# Configuration-as-code rollout — 2026-09-08

## Scope and outcome

The six configuration migrations are now applied to the live BuildPusher SQLite database. Readiness changed from HTTP 503/unavailable to HTTP 200/ready. Application maintenance is disabled; the PHP 8.5 worker and original timers are restored. Existing application data was preserved.

The new configuration-delivery timer closes an actual operational gap: the host previously ran health, watchdog and backup timers but had neither the full Laravel scheduler nor a configuration-processing timer. The daemon installer now writes and enables `lessbuild-configuration.service` / `.timer`. The service runs the existing bounded processing command every minute, skips the file-based maintenance marker and does not overlap itself. It uses the installer's selected PHP executable; this host uses the private PHP 8.5.10 wrapper.

## Data verification

Private artifacts reside at `/root/.local/share/buildpusher/rollout/20260908T104831Z` (restricted directory; not committed). They include the consistent initial SQLite snapshot, a separate rehearsal copy, the fresh maintenance-window snapshot, the protected environment backup needed for encrypted data, result JSON, exact rehearsal/application scripts and logs.

- The initial snapshot passed SQLite integrity and foreign-key checks. The job queue was empty.
- The rehearsal applied precisely migrations `2026_09_06_010000` through `060000` on the database copy, rolled back precisely those six, and reapplied them successfully.
- SHA-256 fingerprints of the contents of every one of the 71 pre-existing application tables were unchanged after rollout, rollback and reapply. Migration history matched the expected state at each step. Integrity and foreign-key checks passed; the original backup remained unchanged.
- For the live change, maintenance was enabled, the worker and active timers were paused, and a fresh SQLite snapshot was taken. Exactly the rehearsed six migrations were applied. Before restoring service, the same content-hash comparison verified all 71 existing tables, and migration history, integrity and foreign keys passed.
- The live database was never rolled back. No saved configuration or deployment operation was created by this migration procedure.

## Runtime and code checks

- `DaemonInstallerTest` and `ApplicationConfigurationMigrationTest`: **3 tests / 120 assertions passed**, using isolated test databases. The former includes shell syntax and the latter verifies migration constraints and retry-history rollback protection.
- `bash -n scripts/install-daemon.sh` and `git diff --check` passed.
- The two new host units were generated directly from the installer's heredocs and passed `systemd-analyze verify` before activation. The broad installer itself was not rerun on the live host.
- The configuration service exited successfully: **0 operations inspected, 0 processing errors**. Its timer is enabled/active; the existing queue worker is active with exit status zero.
- Local TLS through Caddy returned `{"status":"ready"}` and HTTP 200 from `/api/health` after rollout.

## Remaining feature gate

The empty processor pass proves schema and command/runtime readiness only. A disposable live deployment, restored-data verification and provider-side cleanup confirmation are still required. The user has been asked for a disposable provider/account or existing server and maximum spend. No paid resource was created and no live deployment was submitted during this rollout. Keep the current feature open until that drill has evidence; do not substitute local tests for provider acceptance or move to preview environments yet.

The broader Laravel scheduler remains inactive on this host; the new dedicated timer covers configuration delivery specifically. Avoid also scheduling the same command through Laravel cron without removing this duplicate mechanism.
