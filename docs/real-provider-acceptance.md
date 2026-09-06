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

The audit fails until one project environment has recorded one causally ordered lifecycle after the start time. It requires a provider-assigned server identifier, two distinct immutable source revisions, a rollback linked to the first revision, a subsequently verified HTTPS backup destination, a restore of that exact later snapshot, and a successful health result after the restore. Evidence cannot be combined across environments, repositories, backups, or cloud providers. It does not expose credentials, logs, addresses, or backup identifiers.
