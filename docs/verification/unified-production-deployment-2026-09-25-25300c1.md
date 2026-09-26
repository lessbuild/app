# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem. This release was staged
and activated through the local release directories; SSH was not used.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `25300c1` (`refactor: register module-owned connection dispatchers`).
- Previous release retained for rollback: `releases/92afd2b`.
- Active release: `/var/www/buildpusher-unified/current` →
  `releases/25300c1eeafe4c599b5c47029131ced04fbcd1a3` →
  `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/25300c1eeafe4c599b5c47029131ced04fbcd1a3`.
- PHP-FPM (`buildpusher-php-fpm.service`) is active. Caddy configuration did
  not change.
- The release reused the previous production `vendor`, storage, and built
  frontend assets. The Composer lock checksum matches the previous release.
  Laravel config, route, and Blade caches built successfully.
- This slice changes no database schema or data. No migrations were run; Core,
  Deployer, Monitor, and Analytics databases remain separate and untouched.
- Automated tests remain unrun under the standing instruction to defer them
  until the full implementation plan is complete.

## Static validation

Changed PHP files passed `php -l` and Pint; `git diff --check`, Laravel command
discovery, Composer platform requirement checks, and the module-boundary scanner
passed. Composer blocked its script wrapper because this host runs as root, so
the same scanner was invoked directly with `php scripts/check-module-boundaries.php`.

## Live HTTPS checks

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200 |
| `https://buildpusher.com/deployer` | 200 |
| `https://buildpusher.com/monitor` | 200 |
| `https://buildpusher.com/analytics` | 200 |
| `https://auth.buildpusher.com/login` | 200 |
| `https://deployer.buildpusher.com/` | 302 to `/home` |
| `https://monitor.buildpusher.com/` | 302 to central login with return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |
