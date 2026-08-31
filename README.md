# Deployer

## Account registration

Fresh installations allow one bootstrap owner account and then close public
registration automatically. Existing password and linked social accounts can
continue signing in. Set `REGISTRATION_ENABLED=true` to allow additional email
or social accounts, or set `REGISTRATION_ALLOW_FIRST_USER=false` when the first
account will be inserted by another provisioning process.

Social-only accounts may establish a local password once without an existing
password. After that first setup, every password change requires the current
local password while the linked social sign-in remains available.
Password changes and resets invalidate authenticated sessions in other browsers;
the browser performing an in-account change remains signed in.

The control-panel UI uses local initials avatars, system fonts, and bundled
scripts/icons, so page rendering does not depend on third-party visual-asset
hosts.

Provider inventory can be searched and filtered by provider type or whether
resources are attached. Resource counts are visible in the list, and pagination
controls keep every provider, attached repository, and attached server reachable.
The filtered provider inventory can be streamed as a spreadsheet-safe CSV with
attached resource metadata. Encrypted provider tokens are never exported.
Each provider can be checked on demand against its provider's fixed HTTPS account
endpoint. Checks are authorized, rate limited, time bounded, and never display
credentials or upstream response bodies.
The latest safe health state and check time remain visible in provider detail,
inventory filters, and exports. Replacing a credential or changing provider type
resets the state to unchecked so stale success cannot be mistaken for current health.
The existing health timer also checks unchecked or day-stale providers in bounded
batches. Connection failures and recoveries create linked activity and inbox alerts,
while repeated states and concurrent stale results do not create duplicate alerts.
The dashboard summarizes Healthy, Failed, and Unchecked provider credentials and
includes failed providers in the active attention total with direct inventory links.
It also separates active deployments from recent history, with owner-scoped
Queued, Deploying, Running, and Timing out counts plus direct build links.

Reusable provisioning recipe scripts are encrypted at rest and omitted from
serialized recipe data. They are decrypted only when editing a recipe or
rendering an authorized server provisioning plan.
Each server also stores an encrypted snapshot of the ordered recipes selected
at creation. Provisioning retries and server history use that immutable snapshot,
so later recipe edits or deletion cannot silently change an existing server plan.
The recipe inventory can be searched by name or description and filtered by
whether a recipe is assigned to servers. Encrypted script bodies are deliberately
excluded from search results and list output.
Recipes can be duplicated into a new encrypted, unassigned copy. The copy opens
for review and renaming, while the source recipe and all server assignments remain
unchanged.
The current filtered recipe inventory can be exported as a spreadsheet-safe CSV
with assignment metadata. Encrypted script bodies are never included.

## Source control providers

Repositories can be deployed from GitHub, GitLab, and Bitbucket Cloud over
HTTPS. Add the matching provider before creating a repository:

- GitHub: a personal access token with repository contents access.
- GitLab: a personal or OAuth access token with `read_repository` access.
- Bitbucket Cloud: a repository, project, or workspace access token with
  repository read access.

Provider tokens are encrypted at rest and are supplied to Git through a
temporary `.netrc` file that is removed after cloning. Repository URLs must
match the selected provider.

## Run as a daemon

The included systemd installer configures the app to listen on every network
interface on port `8003`, process provisioning jobs in a separate queue worker,
restart both processes after failures, and start them automatically after a
reboot. Service installation and restarts wait for a successful loopback HTTP
response from `/api/health` before reporting the web process ready. This
machine-readable endpoint returns ready only when the database is reachable and
all application migrations have run. On a new host it also creates a local
SQLite database and a persistent
systemd timer that takes a consistent database snapshot every day. Automatic
backups are stored in `storage/app/backups` and retained for seven days by
default. A `sync` queue configuration is automatically upgraded to the database
queue so job retries and backoff work outside web requests.

```bash
sudo ./scripts/install-daemon.sh
```

Pass a public IP explicitly when automatic detection is not appropriate:

```bash
sudo ./scripts/install-daemon.sh 203.0.113.10
```

The app will be available at `http://PUBLIC_IP:8003`. Ensure that TCP port 8003
is allowed by the host firewall and cloud-provider firewall.

Check the web and worker processes with:

```bash
systemctl status lessbuild-app lessbuild-worker lessbuild-backup.timer lessbuild-watchdog.timer lessbuild-health.timer
journalctl -u lessbuild-app -u lessbuild-worker --since today
curl --fail http://127.0.0.1:8003/api/health
php artisan queue:monitor database:default --max=10
php artisan lessbuild:backup
php artisan lessbuild:backups:verify --all
php artisan lessbuild:deployments:watchdog
php artisan lessbuild:websites:health
```

Set `DATABASE_BACKUP_RETENTION_DAYS` or `DATABASE_BACKUP_DIRECTORY` to adjust
automatic backup retention and storage. Manual release backups use different
filenames and are never pruned by the automatic backup command.
Each new snapshot is integrity-checked before publication, and the daily systemd
service rechecks every retained SQLite backup so later corruption fails visibly.
The daemon installer also prohibits destructive database commands (`db:wipe`,
`migrate:fresh`, `migrate:refresh`, `migrate:reset`, and `migrate:rollback`),
even if an Artisan invocation supplies a different environment label.

## Demo data

Local and test environments can load an idempotent full-feature demo workspace
with `php artisan db:seed`. To refresh the same fixtures explicitly, including
on a dedicated externally hosted test installation, run:

```bash
php artisan db:seed --class=DemoSeeder --force
```

New local accounts must verify their email before managing deployments or
infrastructure. Account settings remain available so an incorrect address can
be corrected and the signed verification link resent. Social identities are
treated as verified when their provider supplies the account email.
The account page lists linked GitHub, GitLab, and Bitbucket sign-in identities.
Configured identities can be connected explicitly and disconnected while a
local password or another linked provider remains available, preventing
accidental account lockout. A guest social callback never links an existing
account merely because its email matches; the user must authenticate first.
Accounts with a local password must reconfirm it before starting a new social
connection; the confirmation expires using Laravel's configured password
timeout. Social-only accounts can still connect a second provider as fallback.

The seeded owner signs in as `ncorkish@icloud.com` with password `password`.
Fixtures use a stable `[Demo]` prefix and include provider health states,
recipes, servers, websites, repositories, deployment and webhook history,
logs, server commands, activity, and notifications. Tokens, keys, domains, and
IP addresses are deliberately non-functional examples. Running the seeder again
updates the demo workspace without deleting other records owned by the account.

## Automatic push deployments

Each repository page can enable an authenticated deployment webhook. GitHub
and Bitbucket use a one-time secret generated by Lessbuild; copy it into the
provider webhook settings with the displayed payload URL. For GitLab, create a
push webhook first and paste its generated `whsec_` signing token into
Lessbuild. Only pushes to the repository's configured branch deploy.

Webhook payloads are verified against their raw request bodies, duplicate
delivery IDs are ignored, and GitLab timestamps expire after five minutes. If
a push arrives during a deployment, Lessbuild coalesces newer pushes into one
follow-up deployment rather than running releases concurrently. Repository
pages retain delivery history filterable by status and received-date range with
safe commit metadata and build links plus a private, spreadsheet-safe CSV
export; older coalesced deliveries are marked as superseded. Request payloads,
signatures, provider tokens, and webhook secrets are never included in history
or exports.

Completed delivery history is retained for 90 days by default and pruned only
after the daily database backup has been created and verified. Pending and
in-flight deliveries are always preserved. Set `WEBHOOK_DELIVERY_RETENTION_DAYS`
to choose a different retention window.

Deployment serialization follows the website rather than the repository. When
multiple repositories target one website, only one may write its release path
at a time. Pending webhook pushes from sibling repositories are handed off in
arrival order, while repositories on different websites remain independent.
Queued deployments can be canceled before a worker starts them, immediately
releasing the website for its next pending push.

Completed, failed, and canceled builds can be redeployed from their build page.
Each checkout reports its actual commit through a short-lived signed callback.
Redeploying a recorded build checks out that exact commit and can also be used to
restore an earlier release. Legacy manual builds without a recorded revision
retry the configured branch.
Build history can be filtered by repository, status, trigger, and created-date
range, or searched by repository name, commit message, and revision. The current
filtered view can be exported as a streamed, spreadsheet-safe CSV file.
The repository inventory can be searched and filtered by source provider,
deployment website, and latest deployment state. It also shows each target,
provider, and latest deployment without loading the entire inventory at once.
The current filtered inventory can be exported as a streamed,
spreadsheet-safe CSV without credentials or deployment commands.
The filtered server inventory can also be streamed as a spreadsheet-safe CSV
with platform, address, provider, status, and website-count fields while
excluding server credentials and operational logs.
Website inventory exports preserve provisioning, health, and attention filters
and include target, health, retention, and repository-count fields without
environment values, database credentials, tokens, or raw health errors.
Deployment output, website provisioning output, and allowlisted server log
snapshots can be downloaded as owner-authorized plain-text files for incident
review and support handoff.
The activity timeline can be searched and filtered by category or date range;
the same owner-scoped view can be exported as a streamed, spreadsheet-safe CSV
for audits and incident handoff.
The notification inbox can filter alerts by search text, category, state, and
created-date range, then export that same owner-scoped view as a spreadsheet-safe
CSV without exposing raw notification payloads or framework ownership fields.
Individual alerts can be deleted without clearing other read or unread
notifications.
Read notifications are retained for 90 days after review by default and pruned
only after the daily database backup is verified. Unread notifications are
always preserved. Set `NOTIFICATION_RETENTION_DAYS` to adjust the window.
Each server retains an encrypted command history with status and queued-date
filtering plus owner-authorized output downloads. Queued commands can be canceled atomically;
if a worker has already started one, cancellation safely loses the race instead
of claiming to stop an in-flight remote process. Completed, failed, and canceled
commands can be queued again from history while retaining explicit lineage to
the original encrypted execution. Filtered command history can be exported as
a spreadsheet-safe CSV with lineage and timing metadata; retained output remains
available only through its separate owner-authorized download. Terminal command
records and their encrypted output can also be deleted individually; active
commands remain protected.
Terminal command records and encrypted output are retained for 180 days by
default, then pruned only after the daily database backup is verified. Queued
and running commands are preserved regardless of age. Set
`SERVER_COMMAND_RETENTION_DAYS` to adjust the window.

The daemon installer also runs a deployment watchdog every minute. Remote log,
progress, and revision callbacks refresh a build heartbeat. If a deployment
stops reporting for ten minutes, Lessbuild identifies and stops its exact remote
process before marking the build failed and allowing a queued follow-up release.

Websites can optionally verify an HTTP path after each deployment. Lessbuild
follows redirects and retries transient failures before marking the build
failed. When an earlier release exists, a failed check atomically restores its
symlink; application database migrations are intentionally not reversed.
The daemon also checks enabled sites from their managed servers every five
minutes. Three consecutive failures mark a site unhealthy and create one unread
notification; a successful check records recovery and resets the failure count.
Website owners can also queue an immediate, deduplicated check from the website
page without waiting for the next timer run.
Set `HEALTH_MONITOR_BATCH_SIZE` or `HEALTH_MONITOR_FAILURE_THRESHOLD` to tune
the number of sites checked per run or the number of failures required.
Each website can retain between two and twenty releases on its server; five are
kept by default for rollback and recovery.

Repositories can define encrypted custom build commands that run before release
activation and post-deployment commands that run before the health check. Hooks
execute in isolated Bash processes, and a failed post-deployment hook restores
the previous release through the normal deployment failure path.

Deployment, website, and server failures create unread in-app notifications.
The notification inbox links directly to the failed resource and supports
individual or bulk read acknowledgement, title/message search, category and
read-state filters, reopening acknowledged items, and cleanup that deletes read
notifications while always retaining unread failures.

### Todo
 

- [x] Refactor scripts behind typed plans, renderers, and shared progress callbacks
- [x] Use each server's generated key pair for SSH and encrypt private keys at rest
- [x] Refactor server providers behind a common lifecycle interface
- [x] Run selected user-defined recipes during server provisioning
- [x] Add recipe creation and management
- [x] Persist server log snapshots and display them without render-time SSH commands
- [x] Use a type-specific provisioning plan when creating servers
- [x] Safely reuse existing DigitalOcean SSH keys without deleting user-managed keys
