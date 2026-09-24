# Deployer Signal topbar release verification

At this earlier topbar release verification, the Deployer shell and shared
Signal component updates from commit
`7bad0f02684867d1ab90760a5f6ab67349634525` were live at
`/var/www/buildpusher-unified/current`, resolving to release `7bad0f0`. That
release was superseded on 24 September by `b0741b4-signal`; see the current
[Signal theme release log](signal-theme-integration-progress.md).
The previous release `d3f6c5c` remains on disk for rollback. No database
migrations were needed or run.

The release now serves the refreshed Vite assets. CSS changed from
`app-DbFqG-nm.css` to `app-D3fDVJb9.css`; the product JavaScript changed from
`app-BVd4jc9L.js` to `app-BYEt9ftM.js`. Both new assets returned HTTP 200. The
served CSS includes the latest Signal responsive popover rules, and the product
bundle includes the Signal command-palette trigger behavior.

External smoke checks returned:

| URL | Result |
| --- | --- |
| `https://buildpusher.com/` | 200 |
| `https://auth.buildpusher.com/login` | 200 |
| `https://deployer.buildpusher.com/` | 302 to the authenticated dashboard flow |
| `https://monitor.buildpusher.com/` | 302 to central authentication |
| `https://analytics.buildpusher.com/` | 302 to its authenticated dashboard flow |
| `https://buildpusher.com/deployer` | 200 |
| `https://buildpusher.com/monitor` | 200 |
| `https://buildpusher.com/analytics` | 200 |

Caddy, PHP-FPM, the scheduler timer, and the database and Analytics workers
were active after the release. The feature suites passed with **17 tests and
154 assertions**; the Signal command-palette browser interaction passed, as did
the production Vite build, Blade view cache, Pint, and JavaScript syntax check.
An authenticated browser session was not used for the external smoke checks;
the Deployer server-rendered Signal shell and quick actions are covered by the
feature test.
