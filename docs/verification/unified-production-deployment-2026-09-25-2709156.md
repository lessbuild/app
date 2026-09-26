# Buildpusher production release verification — 25 September 2026

This release was staged and activated through the host's local release directories; SSH was not used.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `2709156faf80ca653906b42482538346021d6a90` (`feat: reconcile shared deployer billing per workspace`).
- Active release: `/var/www/buildpusher-unified/current` → `releases/2709156faf80ca653906b42482538346021d6a90` → `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/2709156faf80ca653906b42482538346021d6a90`.
- Previous release retained for rollback: `releases/2da90d2cb941cc377ab11c2daa7e4c32cab8c2a0`.
- The release uses the shared `.env`, persistent `storage/app` and `storage/logs`, release-local framework caches, and unchanged Composer dependencies and frontend assets. No Caddy configuration change was needed.
- PHP-FPM reloaded successfully. All five Buildpusher workers remained active.
- No database migration, reset, subscription import, Stripe request, or billing-data write was performed.

## Validation

Changed PHP files passed `php -l`; Pint and `git diff --check` passed. Laravel package discovery and production config, route, and Blade caches built successfully. The Deployer import command's help and route listing rendered successfully. Automated tests remain unrun under the plan-wide instruction to defer them until the full implementation plan is complete.

## Live HTTPS checks

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200; product-suite homepage |
| `https://auth.buildpusher.com/login` | 200 |
| `https://deployer.buildpusher.com/organization/data` | 302 to central Auth with the original return target |
| `https://monitor.buildpusher.com/` | 302 to central Auth with the original return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |
| `https://analytics.buildpusher.com/build/manifest.json` | 200 |

## Deployer billing preview

The read-only `platform:import-deployer-subscriptions` preview reports 3 organizations, 1 source subscription, 1 already-mapped workspace plan, and 2 plans held for review. It reports 0 subscriptions ready or imported, 0 customers imported, and 0 review records created. A source-data query identified the two organizations sharing the owner-level subscription; their specific Core allocation remains pending owner confirmation. No production billing state was changed.
