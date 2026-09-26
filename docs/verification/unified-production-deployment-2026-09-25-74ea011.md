# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem. Release preparation,
database snapshots, cutover, and rollback therefore use its local release volume;
SSH is not part of the deployment path.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `74ea011` (`Add Core account security and passkeys`).
- Previous release retained: `releases/0d6f9af`.
- Active release: `/var/www/buildpusher-unified/current` → `releases/74ea011` →
  `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/74ea011`.
- Caddy configuration validated and reloaded. PHP-FPM, Caddy, all five unified
  queue workers, and the scheduler timer report active.
- Locked PHP dependencies were already present in the prior release and match
  `composer.lock`; Composer confirmed that no package install or update was
  required. Vite rebuilt the production assets, including the platform passkey
  client.
- PHP syntax checks covered all 355 Core PHP files and the account-security
  feature test file. Pint, Blade compilation, route listing, Vite production
  build, and `git diff --check` passed.
- Automated test suites were not run, following the standing instruction to
  defer them until the full plan is complete.

## Data migration

No database was reset. Before migration, consistent SQLite snapshots of all
four production databases were created under
`/mnt/volume_nyc1_1789401255960/buildpusher-unified/backups/release-migrations/74ea011/`.
Each snapshot passed `PRAGMA integrity_check`.

The Core migration added nullable `ip_address`, `user_agent`, and indexed
`last_seen_at` fields to `platform_auth_sessions`. Deployer, Monitor, and
Analytics had no pending migrations. After migration, all four live databases
passed `PRAGMA integrity_check`; a second Core pretend pass reported nothing to
migrate.

## Live HTTPS checks

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200 |
| `https://buildpusher.com/deployer` | 200 |
| `https://buildpusher.com/monitor` | 200 |
| `https://buildpusher.com/analytics` | 200 |
| `https://auth.buildpusher.com/` | 302 to `/login` |
| `https://auth.buildpusher.com/login` | 200 |
| `https://auth.buildpusher.com/account/security` | 302 to central login for a guest |
| `https://deployer.buildpusher.com/` | 302 to `/home` |
| `https://monitor.buildpusher.com/` | 302 to central login with return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |
| Passkey JavaScript bundle on the auth host | 200 |

The passkey relying-party ID resolves to `buildpusher.com`; the allowed origins
resolve to the apex, auth, Deployer, Monitor, and Analytics HTTPS hosts. No
account-specific WebAuthn ceremony was performed during this release check.
