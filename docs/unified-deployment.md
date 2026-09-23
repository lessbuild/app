# Unified platform deployment

## Runtime

Caddy serves the same Laravel `public` directory on `buildpusher.com`,
`auth.buildpusher.com`, `deployer.buildpusher.com`, `monitor.buildpusher.com`, and
`analytics.buildpusher.com`. `www.buildpusher.com` redirects to the apex domain.
The apex root is a public product overview; `/deployer`, `/monitor`, and `/analytics`
are the product descriptions. The three product subdomain roots enter their respective
authenticated dashboards. Keep the Caddy site block passing `buildpusher.com/` to
Laravel so the public overview can render instead of redirecting to sign-in.
The active release is `/var/www/buildpusher-unified/current`; persistent environment
and storage files live under `/var/www/buildpusher-unified/shared`. The PHP-FPM pool
is `buildpusher-php-fpm.service` and exposes
`/run/buildpusher/php8.5-fpm.sock`.

The platform has four independent SQLite databases under
`/var/lib/buildpusher-unified`: Core owns identity and shared projects; Deployer,
Monitor, and Analytics own their product data and plans. Do not use Laravel's
`migrate:fresh` against a deployed database. Apply module migrations separately:

```sh
php artisan platform:migrate core --force
php artisan platform:migrate deployer --force
php artisan platform:migrate monitor --force
php artisan platform:migrate analytics --force
```

The deployer, monitor, and analytics plan authorities remain product-specific.
Core authentication is enabled after all four schemas are current. New product
accounts are provisioned from Core on first access; an existing local account is
never linked by email alone. Existing legacy database files are retained separately
for rollback and explicit account reconciliation.

Set `SESSION_CONNECTION=core` so central authentication sessions do not depend on
the Deployer database. The Core migration creates the Laravel session table.

## Queues and scheduler

Install `deploy/systemd/buildpusher-worker@.service`,
`deploy/systemd/buildpusher-schedule.service`, and
`deploy/systemd/buildpusher-schedule.timer` into `/etc/systemd/system`, then enable
the timer and one worker instance for each queue:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now buildpusher-schedule.timer
sudo systemctl enable --now buildpusher-worker@database.service
sudo systemctl enable --now buildpusher-worker@telemetry.service
sudo systemctl enable --now buildpusher-worker@checks.service
sudo systemctl enable --now buildpusher-worker@alerts.service
sudo systemctl enable --now buildpusher-worker@analytics.service
```

The default worker uses Deployer's queue database. The telemetry, checks, and alerts
workers use Monitor's database. Analytics uses its own queue database. The scheduler
dispatches product checks and retention tasks once per minute. Configure
`DIAGNOSTIC_SYSTEMD_TIMERS=true` and set `DIAGNOSTIC_SYSTEMD_SERVICES` to the FPM and
worker service names so the control panel checks the active runtime.

## Release procedure

Build assets and install locked PHP dependencies before switching traffic. Keep the
shared `.env`, storage, and database files outside release directories. Copy the new
release into a versioned directory, link shared state, build Laravel's production
caches, and run the four module migrations against the shared databases. Validate
the Caddy configuration and the new release before atomically moving `current` and
reloading Caddy. Retain the previous release and database files until the new hosts,
login handoff, workers, and scheduled tasks have passed their smoke checks.

Set each product's `*_AUTH_AUTHORITY=core` only after the product migration has run.
Keep `SESSION_DOMAIN` empty: the central auth host uses a signed one-time handoff to
the requested product host, while every application session cookie stays host-only.
Configure a real mail transport before relying on email verification, invitations,
or notifications in production.
