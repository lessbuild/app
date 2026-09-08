# Live PHP runtime repair — 2026-09-08

## Cause and repair

The live Caddy configuration routed BuildPusher to `/run/php/php8.3-fpm.sock`, while the upgraded Composer dependencies require PHP >= 8.5. The existing background services also invoked `/usr/bin/php` (8.3).

The host now runs an isolated PHP 8.5.10 FPM service named `buildpusher-php-fpm.service`. The matching FPM package was extracted into the existing private runtime at `/root/.local/share/buildpusher/php-8.5.10`; its SHA-256 was checked against the package index before extraction. System PHP, package alternatives and unrelated applications were not changed.

Host configuration:

- FPM unit: `/etc/systemd/system/buildpusher-php-fpm.service` (enabled).
- FPM pool and ini: `/etc/buildpusher/php-fpm.conf` and `/etc/buildpusher/php-fpm.ini`.
- Caddy FastCGI socket: `/run/buildpusher/php8.5-fpm.sock`, owned by `www-data` with mode 0660.
- Original Caddy configuration: `/root/.local/share/buildpusher/runtime-backups/2026-09-08/Caddyfile`.
- CLI wrapper: `/root/.local/share/buildpusher/php-8.5.10/bin/php`.
- PHP command overrides: `/etc/systemd/system/lessbuild-{worker,health,watchdog,backup,app}.service.d/php85.conf`.

The previously failing queue worker was restarted with PHP 8.5. Existing timer commands will use the same runtime. The legacy `lessbuild-app.service` development server remains inactive. The local environment's diagnostic service list now names `buildpusher-php-fpm.service`.

These are host changes, not portable paths to copy into every installation. Future runtime upgrades must keep FPM, CLI and extensions aligned. Use the explicit CLI wrapper for Artisan and Composer on this host; the default `php` command remains 8.3.

## Verification and remaining rollout

- FPM configuration and systemd unit validation passed; Caddy configuration validated and reloaded successfully.
- An actual FastCGI request loaded `vendor/autoload.php` successfully and reported PHP 8.5.10, `fpm-fcgi`, and MySQL/SQLite PDO drivers. The temporary probe was outside the public directory and was removed afterward.
- Composer `check-platform-reqs --no-dev` passed with the private PHP 8.5 CLI.
- Live public `/`, `/login` and `/pricing` returned HTTP 200 without the Composer error. A further local TLS request confirmed `/login` HTTP 200 and `/home` HTTP 302 to the login page.
- FPM and queue-worker master processes remained active with unchanged restart counters during final checks. Two earlier FPM child SIGKILL events and one public health-request timeout were observed; subsequent local TLS checks completed. No kernel OOM event was present in the inspected journal interval.
- `/api/health` returns HTTP 503 with `status: unavailable`. Read-only `artisan migrate:status` confirmed all six configuration migrations remain pending, which is sufficient for the application's readiness gate to reject readiness.

No persistent-database migration, new configuration operation or cloud provisioning was performed. The Composer error is resolved; configuration-as-code schema rollout remains outstanding. No application code or dependency locks changed, so application test suites were not rerun for this host-only repair.
