# Isolated dev worker restart — 2026-09-17

The isolated `buildpusher-dev-main-worker.service` stopped at
2026-09-16 23:12:25 UTC after its configured one-hour maximum runtime.
Its exit status was zero and its unit used `Restart=on-failure`, so systemd
correctly left it stopped. The queue contained no pending jobs when inspected.

The repository's `scripts/install-daemon.sh` already uses `Restart=always`
for this worker lifecycle. The isolated unit at
`/etc/systemd/system/buildpusher-dev-main-worker.service` was corrected to
match it. No installer/source change was necessary.

Verification:

- `systemd-analyze verify` accepted the corrected unit.
- Only the isolated dev worker was started after the unit reload.
- `php artisan queue:restart` ran with PHP 8.5.10 from the isolated dev
  runtime and its own file-cache path.
- Worker PID 1703182 exited normally at 04:46:50 UTC after receiving the
  restart signal.
- Systemd started replacement PID 1703231 at 04:46:53 UTC; `NRestarts=1`,
  `ExecMainStatus=0`, `ActiveState=active`, `SubState=running`.
- The web service remained active and the dev domain continued to serve.
- Queue retries, backoff, timeout, one-hour worker recycling, database path and
  credentials were unchanged.

This is an isolated runtime correction. The installer remains the existing
source for the correct lifecycle policy. The preceding PostgreSQL cleanup
fix is committed and pushed as `70d7c78`; its full strict PHP suite passed
1,547 tests / 12,962 assertions. No application code changed after that run.

The next task is to recheck the exact disposable Spaces repository prefix
from the earlier drill, using the existing dev credentials without recording
them, then continue the outstanding preview and recovery acceptance checks.

