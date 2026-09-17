# Provider-backed preview-stack acceptance — 2026-09-17

Status: passed for the representative Laravel preview stack on the isolated
dev runtime. The disposable DigitalOcean server, source website, repository,
project and preview resources were removed before this record was written.
No credentials or secret values are recorded.

## Scope and responsibility boundary

The run used the normal BuildPusher webhook, preview lifecycle, website
provisioning, repository deployment, managed-resource and cleanup paths. It did
not create resources through provider-only setup. The preview lifecycle remained
responsible for preview identity and revision state; deployment jobs coordinated
durable remote work; managed-resource and cleanup services rendered only the
captured PostgreSQL/Valkey identities; and the existing signed callback
protocol recorded progress, readiness, failure and completion.

The test fixture was `natecorkish/Deployer-Test` on its disposable
`feature/preview-acceptance` branch. The fixture-only setup commits were
`3cce6d5` (minimal Artisan command shim), `b681f0c` (revision update),
`2762ae3` (intentional HTTP 503 health failure) and `57ba65b` (healthy
recovery). Each fixture commit was pushed to the fixture repository. The
initial checkout failure caused by advertising a branch before it existed, and
one discarded malformed test SHA, were setup mistakes and are not application
failures.

## Acceptance sequence

### Open and initial stack

- A signed GitHub pull-request webhook created the preview through the real
  application endpoint. Preview configuration did not contain the source
  website's sentinel environment value or source application-key marker.
- The preview received independent generated database and cache credentials;
  the database password differed from the source website's password.
- The Laravel stack declared preview-owned queue and scheduler processes plus
  preview-owned managed PostgreSQL and Valkey resources.
- After the corrected fixture branch was available, build 37 succeeded. Both
  managed resources reached `ready`, initialization succeeded, the preview
  marker was present, both process units were active, and the preview returned
  HTTP 200.

### Revision update

The pushed `b681f0c` revision was delivered by a signed synchronize webhook.
Build 39 succeeded and initialization/resource readiness remained successful.
This verified that a new revision reuses the preview stack without duplicating
the declared resources or process identities.

### Failure and recovery

The pushed `2762ae3` fixture returned HTTP 503. Build 40 failed at health
validation and the preview entered `failed` while PostgreSQL and Valkey stayed
ready. The first run exposed that the restored `current` symlink could still be
served from PHP-FPM opcode cache after a failed candidate was replaced.

Commit `3895a31` refreshes the configured PHP-FPM service after a successful
failed-release restoration. Its focused deployment/health suite passed 14
tests and 119 assertions. Replaying the same failing revision produced build
41: health validation still failed as expected, but the previous release was
immediately served with HTTP 200 without a manual server reload.

The pushed `57ba65b` healthy revision then produced build 42 successfully,
with initialization and both managed resources ready.

### Close, reopen and cleanup

Closing the first stack created cleanup record 1. The cleanup worker removed
the preview-owned process units, Valkey container and volume, PostgreSQL
database and role, deployment directory and related remote placement.

The first reopen attempt exposed a unique project/environment slug conflict:
the old environment is retained long enough to preserve cleanup identity while
the website/repository placement is removed. Commit `c582dd8` selects the next
unused preview environment slug for every new stack generation, preserving the
public preview URL while ensuring an older cleanup record cannot target a
reopened stack. Its preview lifecycle, cleanup and readiness suite passed 35
tests and 337 assertions.

After that commit was deployed to the dev runtime, reopening the same pull
request created environment generation `pr-42-2`. Build 43 succeeded; the new
stack returned HTTP 200, initialization succeeded, PostgreSQL and Valkey were
ready, the queue and scheduler units were active, and the initialization marker
was present. Closing it created cleanup record 2, distinct from record 1, and
both cleanup records completed successfully.

## Cleanup evidence

After cleanup 2, bounded remote checks reported:

- zero preview queue or scheduler unit files;
- no preview Valkey containers or volumes for either environment generation;
- no preview deployment directory;
- zero matching PostgreSQL databases and roles; and
- zero pending application queue jobs.

The disposable server was deleted through `DeleteServerAction`; its local
server record was absent afterward. The disposable project was then deleted,
removing its source website/repository and environment records. DigitalOcean
inventory no longer contained droplet `601322391`. The source provider record
used for the run was retained because it is an existing dev account setting.

## Local verification

The following focused checks passed on the required PHP runtime
`/root/.local/share/buildpusher/php-8.5.10/bin/php`:

- deployment log and health recovery: 14 tests / 119 assertions;
- preview deployment, stack cleanup and readiness: 35 tests / 337 assertions;
- full Pint and `git diff --check` for the changed application slice.

The complete strict suite and final release-gate checks remain separate from
this provider-specific record. Production deployment, paid cloud acceptance,
other provider adapters, independent monitoring, billing/SSO, and the separate
live acceptance drill remain outside this run.
