# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem. This release was staged
and activated through its local release directories; SSH was not used.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `9bdc93a` (`feat: export analytics workspace data`).
- Previous release retained for rollback: `releases/7de5afb67d54a98b43db91e21940c93f43b5a5ca`.
- Active release: `/var/www/buildpusher-unified/current` →
  `releases/9bdc93ad6707bfab53d172c12a33f7f34f08f5e5` →
  `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/9bdc93ad6707bfab53d172c12a33f7f34f08f5e5`.
- PHP-FPM and all five Buildpusher queue workers are active. Caddy configuration
  did not change.
- The release uses the shared `.env`, persistent `storage/app` and `storage/logs`,
  and release-local framework caches. Composer dependencies were copied from the
  previous release; this slice does not change Composer dependencies. Vite built
  the Signal assets included in this release.
- Laravel config, route, and Blade caches built successfully. The production
  route cache contains the Analytics workspace data page and export endpoints.
- This slice changes no database schema or data. No migrations or database
  resets were run; all four production databases remain untouched.
- Automated tests remain unrun under the plan-wide instruction to defer them
  until the full implementation plan is complete. Export authorization,
  tenant-isolation, privacy, and relationship tests are authored.

## Static validation

Changed PHP files passed syntax checks and Pint. Blade compilation, production
config/route caching, route-list verification, module-boundary checks, the Vite
production build, and `git diff --check` passed.

## Live HTTPS checks

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200; remains the product-suite homepage |
| `https://auth.buildpusher.com/login` | 200 |
| `https://deployer.buildpusher.com/` | 302 to `/home` |
| `https://monitor.buildpusher.com/` | 302 to central login with return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |
| `https://analytics.buildpusher.com/build/manifest.json` | 200 |

No authenticated production account was used. The owner/admin-only Analytics
export route was confirmed in the production route cache; its authenticated
download response was not invoked against a production account.
