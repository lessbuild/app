# Core notification export and saved-filter release

Application commit: `7bb953f865090058434fd2789a83c613ba1d404c`

Branch: `feature/unified-platform`

GitHub: `lessbuild/app`

Production release: `/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/7bb953f865090058434fd2789a83c613ba1d404c`

Active pointer: `/var/www/buildpusher-unified/current`

Buildpusher Core now has personal saved filters in each workspace inbox. Users can keep up to ten filters; saving a case-insensitive duplicate name updates that filter. The CSV export uses the same active workspace membership, product grants, project scope, and state/product/severity/project filters as the inbox. It exports the existing feed bound of up to 100 recent items, marks formula-leading cells safe for spreadsheets, and sends private no-store headers. Core owns the filter metadata; product activity and delivery records remain in their source modules.

The additive `workspace_notification_saved_filters` migration ran against `/var/lib/buildpusher-unified/core.sqlite`. Before migration, an SQLite online backup was saved at `/mnt/volume_nyc1_1789401255960/buildpusher-unified/backups/release-migrations/d21-notification-export-saved-filters-20260925/core.sqlite` with mode `0600`. Its SHA-256 is `cae35c5548c773bac67d06eaa428101bc6f2b7852f4dbfa0867d328d91485468`; `PRAGMA integrity_check` returned `ok`. The migration preview showed only the new table, its user/workspace cascade foreign keys, and two indexes. The migration completed, the follow-up preview reported no pending Core migrations, and the live database passed another integrity check. No existing rows were changed or reset.

The release was staged on the mounted volume and activated through the host-local `current` symlink; no SSH or Caddy configuration change was used. Composer dependencies were installed from the lock file and the optimized autoloader was generated in the release so application classes resolve from this release. The production config cache was carried forward with release-specific paths updated, preserving the configured SQLite connections. Package discovery, route cache, and Blade cache completed. PHP-FPM reloaded and remains active.

Live loopback HTTPS checks returned 200 for the Buildpusher homepage, central login, and the Buildpusher Deploy, Monitor, and Analytics description pages. The Core notifications route redirects unauthenticated requests to `auth.buildpusher.com/login`. Deployer and Analytics roots continue to hand off to their dashboard paths, and Monitor's root continues to hand off to central login. All three product roots and their subdomain behavior remain available.

Static validation passed: changed PHP syntax checks, Pint, `git diff --check`, Blade compilation, and route listing confirmed the authenticated Core inbox, export, save, and delete routes. Automated tests were authored in `tests/Feature/Core/WorkspaceNotificationInboxTest.php` for private ownership, case-insensitive replacement, deletion boundaries, and filtered CSV export; they remain unrun under the plan-wide test deferral. Authenticated browser acceptance remains open.

The host root filesystem reported 100% use with about 94 MB available after deployment. The release itself and verified backup are on the mounted volume. Review host disk use before the next deployment; old releases and logs were left intact.

Remaining D21 work includes importing or reconciling Deployer's native notification records and saved views, product/project access revocation routes, keyboard acceptance, and final test evidence. This release does not close D21.
