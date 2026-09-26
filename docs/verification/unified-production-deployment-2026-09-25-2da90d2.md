# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem. This release was staged
and activated through its local release directories; SSH was not used.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `2da90d2` (`feat: export deployer workspace data`).
- Previous release retained for rollback: `releases/9bdc93ad6707bfab53d172c12a33f7f34f08f5e5`.
- Active release: `/var/www/buildpusher-unified/current` →
  `releases/2da90d2cb941cc377ab11c2daa7e4c32cab8c2a0` →
  `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/2da90d2cb941cc377ab11c2daa7e4c32cab8c2a0`.
- PHP-FPM and all five Buildpusher queue workers are active. Caddy configuration
  did not change.
- The release uses the shared `.env`, persistent `storage/app` and `storage/logs`,
  and release-local framework caches. Composer dependencies were copied from the
  previous release; this slice does not change Composer dependencies or frontend
  assets.
- Laravel config, route, and Blade caches built successfully. The production
  route cache contains both Deployer workspace data endpoints.
- This slice changes no database schema or data. No migrations or database
  resets were run; all four production databases remain untouched.
- Automated tests remain unrun under the plan-wide instruction to defer them
  until the full implementation plan is complete. Export role, tenant, and
  secret-exclusion regressions are authored.

## Static validation

Changed PHP files passed syntax checks and Pint. Blade compilation, production
config/route caching, route-list verification, module-boundary checks, and
`git diff --check` passed. No Vite build was needed because the release changes
only PHP and Blade files and uses existing Signal components.

## Live HTTPS checks

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200; remains the product-suite homepage |
| `https://auth.buildpusher.com/login` | 200 |
| `https://deployer.buildpusher.com/organization/data` | 302 to central Auth with return target for guests |
| `https://monitor.buildpusher.com/` | 302 to central Auth with return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |
| `https://analytics.buildpusher.com/build/manifest.json` | 200 |

No authenticated production account was used. The Deployer workspace export
route is active, but its authenticated page and download were not invoked
against a production account.
