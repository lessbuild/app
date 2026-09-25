# Buildpusher production release verification — 25 September 2026

The production host shares this workspace's filesystem, so release inspection
and deployment use the local release volume; SSH is not required. At this check,
`/var/www/buildpusher-unified/current` resolved to
`/mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/0d6f9af`, matching
the checkout's `HEAD` (`0d6f9af08082720f3628ad88c3304f7c0aab7cf6`). The host's
deployment record identifies `0d6f9af` as the active `feature/unified-platform`
release and records its backups, migrations, cache rebuild, service reload, and
initial smoke checks.

Live HTTPS checks at the time of this verification returned:

| Host and path | Result |
| --- | --- |
| `https://buildpusher.com/` | 200 |
| `https://auth.buildpusher.com/` | 404 |
| `https://auth.buildpusher.com/login` | 200 |
| `https://auth.buildpusher.com/two-factor-challenge` | 302 |
| `https://deployer.buildpusher.com/` | 302 |
| `https://monitor.buildpusher.com/` | 302 |
| `https://analytics.buildpusher.com/` | 302 |

The authenticated release does not change during this check. The working tree
contains uncommitted account-security changes and an additive Core migration;
they are not in the active release and have not changed production databases.
Regression tests were added but not run, following the plan-wide test deferral.
