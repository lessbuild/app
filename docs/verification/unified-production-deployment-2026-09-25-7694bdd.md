# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem. This release was staged
and activated through the local release directories; SSH was not used.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `7694bdd` (`feat: export shared platform account data`).
- Previous release retained for rollback: `releases/25300c1`.
- Active release: `/var/www/buildpusher-unified/current` →
  `releases/7694bdd58e25663394540288af10aea4ec3e2c01` →
  `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/7694bdd58e25663394540288af10aea4ec3e2c01`.
- PHP-FPM (`buildpusher-php-fpm.service`) is active. Caddy configuration did
  not change.
- The release reused the previous production `vendor`, storage, and built
  frontend assets. The Composer lock checksum matches the previous release.
  Laravel config, route, and Blade caches built successfully.
- A read-only schema check confirmed every Core table and selected column used
  by the export is present. No account rows were read during that check.
- This slice changes no database schema or data. No migrations were run; Core,
  Deployer, Monitor, and Analytics databases remain separate and untouched.
- Automated tests remain unrun under the standing instruction to defer them
  until the full implementation plan is complete.

## Static validation

Changed PHP files passed `php -l` and Pint. The account export route was found
in the cached production route list; Blade view caching and `git diff --check`
passed. The module-boundary scanner passed on the implementation source.

## Live HTTPS checks

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200 |
| `https://auth.buildpusher.com/login` | 200 |
| `https://auth.buildpusher.com/account/export` | 302 to central login for a guest |
| `https://auth.buildpusher.com/account/security` | 302 to central login for a guest |
| `https://deployer.buildpusher.com/` | 302 to `/home` |
| `https://monitor.buildpusher.com/` | 302 to central login with return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |

The authenticated account download itself remains covered by the authored,
deferred feature test; no production account session was used for verification.
