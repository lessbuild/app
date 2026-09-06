# BuildPusher release security review

Review date: 2026-09-05 UTC

This is a point-in-time release-readiness record, not a guarantee of future security. Repeat the commands below before each production release and after infrastructure changes.

## Verified controls

| Area | Evidence | Result |
| --- | --- | --- |
| PHP dependencies | `composer audit --locked --no-interaction` | Pass: no known vulnerability advisories |
| JavaScript production dependencies | `npm audit --omit=dev` | Pass: zero vulnerabilities |
| Retained application backups | `php artisan lessbuild:backups:verify --all` | Pass: all 138 SQLite backups verified |
| Complete application suite | `php artisan test --compact` | Pass: 841 tests, 7,987 assertions |
| Workspace isolation | Active/inactive workspace policy and HTTP regression tests | Pass: read and mutation access denied until the user switches to that workspace |
| Monetization enforcement | Entitlement HTTP tests, atomic plan-limit tests, and background-task checks | Pass: resource caps and paid capabilities enforced server-side |
| Production debug mode | `php artisan lessbuild:diagnose --json` | Pass: disabled |
| Queue health | `php artisan lessbuild:diagnose --json` | Pass: database queue, zero backlog, zero failed jobs |
| Service scheduling | `php artisan lessbuild:diagnose --json` | Pass: three required services and three timers active |
| Debug-tool exposure | Live requests to `/telescope` and `/_ignition/health-check` | Pass: 403 and 404 respectively |
| Browser protections | Live response-header inspection | Pass: HSTS, MIME sniffing, frame, referrer, permissions protections; application CSP added in this review |
| Feedback privacy | Feature tests and database inspection | Pass: workspace scoped, encrypted at rest, query strings rejected |
| Release migration | `php artisan migrate --force` | Pass: `2026_09_05_290000_create_product_feedback` applied |
| Production readiness | Live `https://buildpusher.com/api/health` request | Pass: HTTP 200 and `ready` after deployment |

Focused test command:

```bash
php artisan test \
  tests/Feature/SecurityHeadersTest.php \
  tests/Feature/ResourceAuthorizationTest.php \
  tests/Feature/SensitiveActionRateLimitTest.php \
  tests/Feature/DatabaseCommandSafetyTest.php \
  tests/Feature/DatabaseBackupCommandTest.php \
  tests/Feature/ExternalMonitoringTest.php \
  tests/Feature/ProductionErrorResponseTest.php \
  tests/Feature/AccountLifecycleTest.php
```

## Open release work

| Priority | Finding | Release action |
| --- | --- | --- |
| Blocker | Production email transport and verified sender are not configured. | Configure SMTP or a transactional email provider, then rerun `php artisan lessbuild:diagnose --section=email`. |
| Blocker | No independent uptime and scheduler-heartbeat monitor is configured. | Configure an external monitor following `docs/external-monitoring.md`, then test alert delivery. |
| High | The queue worker currently runs as `root`; the PHP-FPM pool and Caddy run as `www-data` and `caddy`. | Move the project out of `/root`, create a dedicated least-privilege worker user, migrate ownership deliberately, restart services, and rerun the full suite and acceptance audit. Do not change this ad hoc on production. |
| High | A real cloud-provider deployment/recovery drill has not been run. | Use a disposable project and credentials with `php artisan buildpusher:acceptance:audit`; verify deploy, health, rollback, cleanup, and provider invoices. |
| Medium | Alpine/Livewire compatibility currently requires CSP allowances for inline script/style and `eval`. | Move inline behavior to nonce- or hash-based assets where supported, then tighten the policy and monitor CSP reports. |
| Medium | Runtime directories are group-writable while services run as root. | Resolve as part of the dedicated-service-user migration and restrict permissions to only required paths. |

The current release backup `automatic-database-20260905-210246-847783.sqlite` was created and all 138 retained backups were verified before deployment. Stripe checkout activation is intentionally deferred, but plan limits and feature entitlements are enabled and enforced server-side.

## Recovery evidence to retain per release

1. Record the pre-release backup filename and its successful verification output.
2. Record the migration status and application health report after deployment.
3. Confirm queue workers and timers restarted successfully.
4. Exercise a restore in a disposable environment; file existence alone is not restore evidence.
5. Retain the provider acceptance-audit output and confirm temporary resources were removed.
6. Record the operator, UTC time, version or commit, and any accepted exceptions.

## Release rerun

```bash
composer audit --locked --no-interaction
npm audit --omit=dev
php artisan lessbuild:backups:verify --all
php artisan lessbuild:diagnose --json
php artisan test
npm run build
```

Expected remaining diagnostic failures must not be silently waived. Link each exception to an owner and resolution date before launch.
