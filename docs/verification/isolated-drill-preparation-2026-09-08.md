# Isolated deployment-drill preparation — 2026-09-08

The DigitalOcean account/budget/cleanup authorization is already granted. This checkpoint prepares the current configuration feature's real deployment verification without altering the production subscription or existing infrastructure.

## Authoritative preflight

- DigitalOcean provider ID 6 can read droplets, sizes and SSH keys. Existing resources are outside the cleanup scope.
- All four saved source-control connections returned HTTP 401 on fresh connection checks. A working connection and an authorized disposable repository remain required; the user has been asked for them.
- The live workspace uses the Free plan, has five retained server records against its one-server allowance, and does not allow backups or managed resources. Its billing configuration and records were left unchanged.

## Prepared installation

A detached Git worktree at `/root/.local/share/buildpusher/drill-20260908/app` pins application code to `c1105b8`. Its parent directory is restricted to the operator. Vendor files and built assets were copied independently and its autoloader regenerated; the installation does not share mutable application storage or its SQLite database with production.

The private environment has a new application key, `APP_ENV=testing`, debug disabled, mail sent to the array driver and billing enforcement disabled only for this test installation. Its queue is database-backed but no worker or scheduler is running. No production credentials were copied. The application is configured for localhost port 8056, not a publicly reachable deployment callback URL.

All 119 migrations applied to `database/drill.sqlite`. Runtime assertions established that the environment is testing, its database path is the isolated file and the loaded application model comes from the isolated checkout. Readiness is true; provider and job counts are zero.

A temporary HTTP server bound only to localhost returned 200 for `/api/health`, `/login` and `/`, then was stopped. Logs are `/tmp/buildpusher-drill-isolated-migrations.log` and `/tmp/buildpusher-drill-local-http.log`. This is installation verification, not live-provider deployment evidence.

## Next prerequisites

After the user connects source control and identifies the fixture repository, complete its two-revision/data fixture and bounded-cost resource plan. Arrange a temporary reachable callback endpoint for the isolated instance, then configure only the authorized test connections and supervised worker. Before creating resources, make the cleanup inventory and lifetime limit concrete. Execute the configuration-specific review/apply, retry/cancel, rollback/restore and removal drill in `docs/real-provider-acceptance.md`, retaining provider-side cleanup evidence.

No cloud resource was created. The £10 budget is unspent. Configuration remains the current feature; this checkpoint does not authorize moving to previews.
