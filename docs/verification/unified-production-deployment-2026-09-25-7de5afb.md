# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem. The release was staged
and activated through its local release directories; SSH was not used.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `7de5afb` (`fix: guard shared account local deletion`).
- Previous release retained for rollback: `releases/7694bdd58e25663394540288af10aea4ec3e2c01`.
- Active release: `/var/www/buildpusher-unified/current` →
  `releases/7de5afb67d54a98b43db91e21940c93f43b5a5ca` →
  `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/7de5afb67d54a98b43db91e21940c93f43b5a5ca`.
- PHP-FPM (`buildpusher-php-fpm.service`) is active. Caddy configuration did
  not change.
- The release uses the shared `.env`, the prior release's persistent `storage/app`
  and `storage/logs`, and release-local framework caches. Locked Composer
  dependencies were copied from the prior release; the `composer.lock` SHA-256
  remains `379d2d6c8aedfdb2d7768e07708e9170b4985c6f4f2a3fd7133164a76cc71cab`.
  Vite rebuilt the Signal stylesheet and production assets.
- Production configuration confirms Core auth authority for Deployer. Both
  product-local account and workspace deletion routes include the new guard.
- This release changes no database schema or data. No migrations or database
  resets were run; all four production databases remain untouched.
- Automated tests remain unrun under the plan-wide instruction to defer them
  until the complete implementation plan is finished. The new middleware
  regression test is authored.

## Static validation

Changed PHP files passed syntax checks and Pint. Blade templates and Laravel
config/route caches built successfully. Route listing shows
`PreventProductLocalDeletion` on both destructive routes, the product module
boundary scanner passed, the Vite production build passed, and
`git diff --check` passed. The authenticated `409` response was not invoked against a
production account.

## Live HTTPS checks

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200 |
| `https://buildpusher.com/deployer` | 200 |
| `https://buildpusher.com/monitor` | 200 |
| `https://buildpusher.com/analytics` | 200 |
| `https://auth.buildpusher.com/login` | 200 |
| `https://auth.buildpusher.com/account/security` | 302 to central login for a guest |
| `https://deployer.buildpusher.com/` | 302 to `/home` |
| `https://deployer.buildpusher.com/account` | 302 to central login with return target |
| `https://monitor.buildpusher.com/` | 302 to central login with return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |
| Rebuilt Signal stylesheet | 200 |

No authenticated production account was used. The account/workspace deletion
guard remains protected by its deferred automated regression coverage and the
production Core-authority configuration check.
