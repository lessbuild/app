# Unified production route and scheduler cutover — 2026-09-24

The active release is `d3f6c5c` at `/var/www/buildpusher-unified/current`.
Caddy serves the same unified Laravel public directory for the Buildpusher,
Auth, Deployer, Monitor, and Analytics hosts. The apex remains the public
product overview; `/deployer`, `/monitor`, and `/analytics` each return HTTP
200 as product-description pages.

The release's Deployer provider-form migration uses shared Signal choice,
input, textarea, and select controls. Its focused regression set passed **11
tests / 100 assertions**. After deployment, external smoke checks returned:

| Route | Result |
| --- | --- |
| `https://buildpusher.com/` | 200 |
| `https://auth.buildpusher.com/login` | 200 |
| `https://deployer.buildpusher.com/` | 302 to `/home` |
| `https://monitor.buildpusher.com/` | 302 to the central login with a return target |
| `https://analytics.buildpusher.com/` | 302 to `/dashboard` |
| `https://buildpusher.com/deployer` | 200 |
| `https://buildpusher.com/monitor` | 200 |
| `https://buildpusher.com/analytics` | 200 |

These are unauthenticated route checks. They verify the public overview,
product descriptions, and intended dashboard/login handoffs; they do not prove
an authenticated cross-host SSO journey or complete product feature parity.

The legacy Analytics scheduler and queue worker targeted the separate
`/var/www/analytics` installation and its `database/analytics.sqlite`. Caddy
already routed public traffic to the unified release. Before stopping the old
worker, its `jobs` and `failed_jobs` tables both reported zero rows. The old
scheduler timer and worker were stopped and disabled with:

```sh
systemctl disable --now buildpusher-analytics-scheduler.timer buildpusher-analytics-worker.service
```

The unified Analytics scheduler and `buildpusher-worker@analytics.service`
remain active on the unified Analytics database. The legacy database and
release files remain in place, and the separate Analytics backup timer was
left untouched. No product database migration, import, identity reconciliation,
or subscription change was part of this service handoff.

The unified scheduler completed its observed runs from 06:30 through 06:36 UTC.
During that window, `project-connections:deliver` took 12–59 seconds and
`telemetry:recover` took 2–22 seconds. The slowest delivery run nearly fills its
one-minute schedule interval, so continue monitoring those task durations and
host load under normal traffic.
