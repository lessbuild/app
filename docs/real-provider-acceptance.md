# Real-provider release acceptance

Run this on a disposable staging project in a dedicated DigitalOcean, Hetzner, or Vultr test account. It creates real infrastructure and can incur provider charges, so the operator must perform the actions deliberately through BuildPusher. Record the UTC start time before creating anything; the audit's `--since` filter prevents an old or partially unrelated run from passing the release gate.

1. Connect a restricted cloud token and a source-control provider.
2. Create a fresh server and wait for every provisioning stage to finish.
3. Create a website and application environment, enable health monitoring, and deploy a known test repository.
4. Verify the deployed revision and a successful health result.
5. Deploy a second revision, then roll back to the first and verify it becomes healthy.
6. Configure a disposable offsite backup destination, create a backup, mutate disposable data, and restore it.
7. Confirm the restored data and health result.
8. Run `php artisan buildpusher:acceptance:audit PROJECT_ID --provider=PROVIDER --since=START_TIME --json` and retain the output as release evidence. `PROVIDER` must be `digitalocean`, `hetzner`, or `vultr`, and `START_TIME` must be the ISO-8601 UTC time recorded before step 1.
9. Delete the disposable server and confirm it disappears from the selected provider.

The audit fails until one project environment has recorded all seven lifecycle stages after the start time. It requires a provider-assigned server identifier, a successful post-rollback health result, and a completed snapshot/restore pair. Evidence cannot be combined across environments or cloud providers. It does not expose credentials, logs, addresses, or backup identifiers.
