# Real-provider release acceptance

Run this on a disposable staging project in a dedicated DigitalOcean, Hetzner, or Vultr test account. It creates real infrastructure and can incur provider charges, so the operator must perform the actions deliberately through BuildPusher. Record the UTC start time before creating anything; the audit's `--since` filter prevents an old or partially unrelated run from passing the release gate.

1. Connect a restricted cloud token and a source-control provider.
2. Create a fresh server and wait for every provisioning stage to finish.
3. Create a website and application environment, enable health monitoring, and deploy a known test repository.
4. Verify the deployed immutable revision.
5. Deploy a distinct second revision, then roll back specifically to the first revision.
6. After the rollback, configure and verify a disposable HTTPS offsite backup destination, create a backup, mutate disposable data, and restore that exact backup.
7. Confirm the restored data, then run and retain a successful health check after the restore completes.
8. Run `php artisan buildpusher:acceptance:audit PROJECT_ID --provider=PROVIDER --since=START_TIME --json` and retain the output as release evidence. Both filters are mandatory: `PROVIDER` must be `digitalocean`, `hetzner`, or `vultr`, and `START_TIME` must be the ISO-8601 UTC time recorded before step 1. The command rejects a missing filter so stale or cross-provider evidence cannot accidentally satisfy the release gate.
9. Delete the disposable server and confirm it disappears from the selected provider.

A passing command verifies recorded lifecycle evidence, not complete release acceptance. JSON explicitly identifies this scope and lists outstanding external verification. Retain a comparison of the restored application fixture with its pre-backup contents, provider-side cleanup confirmation, and evidence that the drill used real infrastructure. Seeded or manually inserted database records cannot establish those facts. Capture the audit before cleanup because deleting resources can remove their associated history.

Successful backup jobs now record HTTPS transport evidence on the backup itself. Later destination verification does not change that historical evidence. Existing backups are not backfilled: run a new backup after applying migration `2026_09_06_000000_add_backup_transport_evidence` for acceptance eligibility. A successful HTTPS transfer alone does not prove independent hosting or data integrity; those remain operator checks.

The audit fails until one project environment has recorded an ordered lifecycle after the start time. It requires a provider-assigned server identifier, two distinct immutable source revisions, a rollback linked to the first revision and its artifact, a later backup with its own HTTPS transport evidence, a restore of that exact snapshot, and a successful health result after the restore. It examines alternative rollback and backup candidates, so an incomplete later attempt does not hide a complete earlier chain within the requested window. Resource ownership and placement must agree. It does not expose credentials, logs, addresses, or backup identifiers.
