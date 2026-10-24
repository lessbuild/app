# Platform health & resilience plan

Asked for on 2026-10-24, from problems that bit during real use of Buildpusher (vacenly.com): a server silently at
96% disk and short of memory, PHP going unpatched until a public exploit, an audit queue filling with no worker to
drain it, deploys needing a human each time, and failures that reported raw errors instead of what to do. The theme:
**Buildpusher should watch itself the way it watches customer apps, and keep people out of fragile manual steps.**

Each stage adds its roadmap item (planned) first, then ships with tests, translations, help, changelog and public
pages — same as docs/launch-plan.md. Status: `[ ]` to do, `[x]` done.

## Stage 1: proactive server health alerts

- [ ] A scheduled check raises an alert (and notifies, through the environment's existing alert destinations) when a
      server crosses disk ≥ 85% (critical ≥ 95%), sustained memory pressure, or a pending reboot — not only when
      someone opens the page. vacenly sat at 96% with nothing shouting.
- [ ] "Queue has work but nothing draining it": when a database/Redis queue has jobs older than a few minutes and no
      worker process is enabled for it, surface it on the environment and alert. (The audit queue filled silently.)
- [ ] Each alert links straight to the fix (Disk clean-up, add a worker, resize).

## Stage 2: fleet security posture

- [ ] A Security view across all of an account's servers: PHP / Node / Ubuntu versions, whether each is behind a
      security release, pending OS updates, and whether automatic updates include PHP.
- [ ] "Patch all" — apply security updates across the chosen servers in one action, reusing PatchServer.
- [ ] Warn when a runtime is at or near end of life (PHP, Node, Ubuntu), with the upgrade path.

## Stage 3: hands-off deploys

- [ ] Auto-deploy on a green build, opt-in per environment (deploy the commit once its CI/build passes), with the
      environment's approvals, windows and freezes still applying.
- [ ] Pre-flight checks before a deploy: enough free disk to unpack a release, enough memory headroom, the queue
      worker healthy. Stop with a clear reason instead of failing part-way. (vacenly was one deploy from failing
      for disk.)

## Stage 4: defaults sized to the server

- [ ] Release retention defaults to the disk size (small disk → keep 2), instead of a flat 5 that filled vacenly's
      10 GB.
- [ ] Surface "this server is undersized for what runs on it" (memory and disk) on the server and at deploy time,
      with the fitting size and price — not only buried on the costs page.

## Stage 5: scan the platform's own dependencies

- [ ] Run composer audit / npm audit (OSV, which already scans customer apps) against Buildpusher's own lockfiles on
      a schedule and raise a Security finding when the platform has an advisory. (The Livewire advisory was only
      found by running it by hand.)

## Stage 6: failures that explain themselves

- [ ] Turn raw provisioning and deploy errors into a cause + next step (as done for the "database is locked" deploy
      and the 60-second update time-out), across the deploy, provisioning and server-task paths.

## Further ideas (not yet scheduled)

- Secret rotation reminders: `rotation_due_at` is stored on variables but not surfaced — notify when one is due.
- Cost anomaly alerts: tell the account when a provider bill jumps against its recent trend.
- One-click environment-wide rollback (not just per repository).
- Server provisioning resumability: resume a provision that stopped half-way, like RunLaunch resumes a launch.
- Backup confidence: show each website's last successful restore drill prominently, not only in the Backups tab.
- Onboarding: measure time-to-first-successful-deploy and guide accounts that stall.
