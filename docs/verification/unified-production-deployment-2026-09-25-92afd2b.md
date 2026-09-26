# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem. The release was staged
on its local volume and activated through the host's release symlink; SSH was
not used.

## Release

- Source: `lessbuild/app`, branch `feature/unified-platform`.
- Commit: `92afd2b` (`Add shared Core social authentication`).
- Previous release retained for rollback: `releases/74ea011`.
- Active release: `/var/www/buildpusher-unified/current` → `releases/92afd2b` →
  `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/92afd2b`.
- The configured PHP-FPM service is active. Caddy configuration validated and
  did not change.
- Composer dependencies were copied from the previous release; the staged
  `composer.lock` SHA-256 matches both the workspace and previous release.
  The updated Signal CSS bundle was rebuilt with Vite and its manifest copied
  into this release. Laravel config, route, and Blade view caches built
  successfully.
- The shared production config reports GitHub, GitLab, and Bitbucket as
  unconfigured. Core keeps their sign-in buttons hidden until credentials are
  supplied.
- No database schema files changed, so this release ran no migrations and made
  no database changes. All four product/Core databases remain separate and
  untouched.
- Automated tests remain unrun under the standing instruction to wait until
  the full implementation plan is complete.

The first symlink attempt pointed `current` at the backing-volume release
without registering it under `/var/www/buildpusher-unified/releases`; Caddy
returned 404 during that brief attempt. I restored `74ea011`, added the host's
release alias, switched to `92afd2b`, and verified the auth login returned 200
before completing the full smoke check below.

## Static validation

Changed PHP files and the deferred feature-test file passed `php -l`; Pint,
`git diff --check`, Core social route listing, Blade view caching, and the Vite
production build passed. The route cache includes the auth-host social redirect
and callback routes, plus authenticated Core account-link and disconnect
routes.

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
| `https://auth.buildpusher.com/social/redirect/github` | 302 to central login while provider is unconfigured |
| `https://auth.buildpusher.com/build/assets/app-B_poimw_.css` | 200 |
| `https://deployer.buildpusher.com/` | 302 to `/home` |
| `https://monitor.buildpusher.com/` | 302 to central login with return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |

No provider OAuth handshake was attempted because the production credentials
and provider-console callback registrations are not present. Account-level
callback, MFA, invitation, and cross-host acceptance remain open.
