# Deployer

## Production domain

The production application URL is `https://buildpusher.com`. Caddy terminates TLS,
redirects `www.buildpusher.com` to the apex domain, compresses responses, and proxies
to the supervised Laravel listener on `127.0.0.1:8003`. The checked-in reference
configuration is [`deploy/Caddyfile`](deploy/Caddyfile); install it at
`/etc/caddy/Caddyfile`, validate with `caddy validate --config /etc/caddy/Caddyfile`,
and reload Caddy. Laravel trusts only the loopback reverse proxy and the exact apex
and `www` hostnames. Production sessions are encrypted, domain-scoped, and Secure.
Production diagnostics include `caddy.service` in the required systemd service set,
so the control panel reports a failed public edge alongside application or worker failures.

DNS must contain an `A` record for `@` pointing to `174.138.39.41` and a `CNAME`
for `www` pointing to `buildpusher.com`. Remove Namecheap parking or URL-forward
records for both names. Caddy obtains and renews the public certificate automatically
as soon as those records resolve to this server.

## Account registration

Fresh installations allow one bootstrap owner account and then close public
registration automatically. Existing password and linked social accounts can
continue signing in. Set `REGISTRATION_ENABLED=true` to allow additional email
or social accounts, or set `REGISTRATION_ALLOW_FIRST_USER=false` when the first
account will be inserted by another provisioning process.
When registration is closed, public calls to action use `/request-access` instead
of sending prospective customers into the sign-in screen. Requests are rate
limited, honeypot protected, deduplicated by a normalized email hash, and store
contact details and use-case text encrypted at rest. Platform administrators can
review and classify them at `/admin/access-requests`; ordinary workspace users
cannot list or update leads. Administrators can issue a rotating, single-use
invitation whose plaintext token is sent only by queued email and expires after
`REGISTRATION_INVITATION_DAYS`. Accepted and declined requests are pruned after
`ACCESS_REQUEST_RETENTION_DAYS`; pending requests are retained for follow-up.

## Platform administration

Set `PLATFORM_ADMIN_EMAILS` to a comma-separated allowlist of verified operator
accounts. Only those accounts can open `/admin/analytics` or production Telescope.
The analytics page contains platform-wide signup, active-user, deployment, plan,
conversion, estimated MRR, churn, and monetization-denial trends. Responses are
private and non-cacheable, and denial telemetry records aggregate counters only—no
email address, resource identifier, requested value, or credential is retained.

Password and social sign-in return the user to the protected page they originally
requested, including its filters and query string. Direct visits to the login page
continue to use the verified Dashboard as the default destination.
Successful password and social sign-ins are retained for 90 days with a bounded
user agent, validated IP address, method, and timestamp. The Account page derives
browser/platform labels without exposing credentials or session data. Failed
attempts are never written to this history, so it cannot become an account-discovery
trail. Set `SIGN_IN_RETENTION_DAYS` to change the window; pruning runs only after
the daily database backup has been created and verified.
Owners can export all retained history as a spreadsheet-safe CSV containing only
derived browser/device labels, validated IPs, methods, and timestamps; raw user
agents are never exported. Local-password owners can also clear their history
after current-password validation through the shared sensitive-action limiter.
The full owner-scoped history is paginated and can be filtered by sign-in method
and date range. Its spreadsheet-safe CSV applies the same normalized filters, so
the exported evidence matches the records under review without exposing raw agents.
Filter-aware cards summarize matching events, local-password and recognized social
usage, distinct validated IP addresses, and the latest matching sign-in. Invalid or
missing addresses never inflate the known-IP count, and an empty result is explicit.

Social-only accounts may establish a local password once without an existing
password. After that first setup, every password change requires the current
local password while the linked social sign-in remains available.
Password changes and resets invalidate authenticated sessions in other browsers;
the browser performing an in-account change remains signed in.

The control-panel UI uses local initials avatars, system fonts, and bundled
scripts/icons, so page rendering does not depend on third-party visual-asset
hosts.
Every HTTP response disables MIME sniffing, permits framing only from the same
origin, limits cross-origin referrer detail, and denies camera, microphone, and
geolocation access through browser policy headers. A restrictive content security
policy is intentionally deferred until the remaining Alpine, Livewire, and inline
interaction handlers can be nonce- or hash-backed without breaking the interface.
Requests must target the host in `APP_URL`, a local readiness-probe host, or an
exact comma-separated hostname in `TRUSTED_HOSTS`; arbitrary Host headers are
rejected before they can influence generated URLs. Secure responses additionally
send a one-year HSTS policy, while HTTP development and direct-IP deployments do
not incorrectly advertise HTTPS availability.

Provider inventory can be searched and filtered by provider type or whether
resources are attached. Resource counts are visible in the list, and pagination
controls keep every provider, attached repository, and attached server reachable.
Filter-aware inventory cards summarize matching, in-use, and unused providers as
well as healthy, failed, and unchecked connection states. Usage and connection
filters share model scopes with those metrics so the list and summary stay aligned.
The filtered provider inventory can be streamed as a spreadsheet-safe CSV with
attached resource metadata. Encrypted provider tokens are never exported.
Each provider can be checked on demand against its provider's fixed HTTPS account
endpoint. Checks are authorized, rate limited, time bounded, and never display
credentials or upstream response bodies.
The latest safe health state and check time remain visible in provider detail,
inventory filters, and exports. Replacing a credential or changing provider type
resets the state to unchecked so stale success cannot be mistaken for current health.
The existing health timer checks unchecked providers and then applies each provider's
selected 1, 6, 12, or 24 hour cadence in bounded batches. Connection failures and
recoveries create linked activity and inbox alerts,
while repeated states and concurrent stale results do not create duplicate alerts.
Recovery automatically acknowledges unread failure alerts for that provider while
retaining them as read incident history.
Every accepted provider credential check retains its manual or automatic source,
fixed public endpoint, provider type, HTTP status, measured duration, safe failure
message, and timestamp. Provider details show the newest 20 checks and offer an
owner-scoped, spreadsheet-safe export of the newest 100; credentials and upstream
response bodies are never stored in this history. The full retained history can be
filtered by result, source, and checked-date range with pagination that preserves
those filters. CSV exports apply the same normalized filters. Demo providers include
healthy, failed, recovery, manual, automatic, and empty-history examples.
The full history also has filter-aware cards for healthy and failed counts,
observed success, median successful response, and the latest matching check. These
calculations use only safe metric fields from the bounded retained sample.
Provider details summarize the retained sample with observed connection success,
median successful-response time, and the newest consecutive-failure streak. Empty
or entirely failed samples show explicit unavailable values rather than invented
performance figures, and the UI labels these metrics as observations rather than
an SLA or a guarantee of current credential validity.
Automatic provider credential monitoring can be paused per provider without
disabling owner-authorized manual connection tests. A check already in flight is
discarded if automatic monitoring is paused before it records a result.
`PROVIDER_HEALTH_INTERVAL_MINUTES` selects the supported default interval for new
providers; existing providers keep their chosen cadence. Demo providers cover every
supported interval while automatic monitoring remains paused to prevent network traffic.
Each provider can require 1, 2, 3, or 5 consecutive failures before its status changes
to failed and one incident is created. Every accepted failure still appears immediately
in retained history, and a successful check resets the counter and recovers an active
incident. `PROVIDER_HEALTH_FAILURE_THRESHOLD` selects the supported default for new
providers. Demo providers cover every threshold, including a three-failure GitLab state.
The dashboard summarizes Healthy, Failed, and Unchecked provider credentials and
includes failed providers in the active attention total with direct inventory links.
Authenticated visits to the public root and the sidebar Dashboard link both enter
the verified dashboard flow; signed-out visitors continue to see the landing page.
The public landing page presents the full provision, release, observability, and
recovery workflow with responsive navigation, descriptive page metadata,
registration-aware calls to action, and a keyboard-visible content skip link.
It publishes canonical Open Graph and summary-card metadata with a local SVG icon,
retains section navigation without JavaScript, and enables smooth anchor scrolling
only when the visitor has not requested reduced motion. Authenticated and auth-flow
pages default to `noindex, nofollow` and do not emit public sharing metadata.
The landing page omits the unused Livewire runtime, `robots.txt` directs crawlers away
from private application routes, and legacy favicon requests permanently redirect to
the local SVG. The systemd web service runs Laravel's static-aware router directly with
PHP version advertising disabled instead of launching a second unsupervised child process.
It names the currently supported cloud/source providers, explains recovery and concrete
operational guardrails, and uses native disclosure controls for concise answers about
hosting, release reuse, and credentials. A compact tabbed product stage covers overview,
provisioning, deployments, website monitoring, server operations, and reusable recipes
without stacking a separate full-height preview for every feature. These previews use
illustrative data and responsive HTML/CSS instead of raster screenshots. The landing
page uses the shared primary, secondary, tertiary, and accent theme tokens throughout
and does not advertise subscription prices or resource tiers the application does not
implement.
On mobile, the authenticated navigation opens above the app header, locks background
scroll, uses the dynamic viewport and safe-area padding, and keeps every destination
reachable in its own scrollable drawer. Opening and closing the drawer transfers
focus predictably, Escape restores the navigation toggle, and pre-JavaScript markup
stays cloaked to avoid a drawer flash. The drawer also includes the signed-in
identity and a CSRF-protected mobile logout action, so session controls remain
available without first dismissing the navigation.
While open, Alpine's focus integration traps keyboard navigation inside the drawer,
makes the covered page inert, and restores normal page interaction after dismissal.
The sidebar also provides account-wide search across websites, servers,
repositories, providers, recipes, and deployment revisions or commit messages.
Each group is owner-scoped, capped at five results, and queried with explicit
metadata-only columns so encrypted operational data never enters search results.
It also separates active deployments from recent history, with owner-scoped
Queued, Deploying, Running, and Timing out counts plus direct build links.
Queued and running server commands receive a separate cross-server summary with
safe server/history links; its active-command query omits encrypted command text
and output. The account-wide Command Center expands that summary into an
owner-scoped, paginated view with server, status, retained-output, active-only, and
normalized queued-date filters. It loads metadata only and links to the authorized per-server
history focused on the selected execution for command text, retained output, and
lifecycle actions. The filtered account-wide metadata can also be exported as a
private, spreadsheet-safe CSV without command text or output content.
History rows and exports include elapsed runtime when both trustworthy start and
finish timestamps are available.
When filtered results contain a queued or running command, the Command Center
offers a status refresh that preserves the current filters and page.
Webhook deliveries from the last 24 hours are summarized across the account by
status with direct, filtered repository-history links. The dashboard query loads
only internal record IDs, repository IDs, statuses, and timestamps; provider
delivery IDs, revisions, and commit messages remain outside dashboard data.
Queued and provisioning servers and websites are combined into a safe infrastructure
progress panel. It loads only resource IDs, owner IDs, names, statuses, and creation
timestamps, leaving credentials and environment configuration outside dashboard data.
Installed gallery recipes with newer published revisions appear in an owner-scoped
dashboard panel with direct comparison and private-copy links. The summary selects
only recipe metadata, so encrypted scripts are not loaded into the dashboard request.
Reports against the account's published recipes appear in a separate contributor
feedback panel with affected-recipe and report totals. It links to anonymous detail
without selecting encrypted feedback or script bodies into the dashboard request.

Reusable provisioning recipe scripts are encrypted at rest and omitted from
serialized recipe data. They are decrypted only when editing a recipe or
rendering an authorized server provisioning plan.
Each server also stores an encrypted snapshot of the ordered recipes selected
at creation. Provisioning retries and server history use that immutable snapshot,
so later recipe edits or deletion cannot silently change an existing server plan.
The recipe inventory can be searched by name or description and filtered by
whether a recipe is assigned to servers. Encrypted script bodies are deliberately
excluded from search results and list output.
Filter-aware cards summarize matching, assigned, and unused recipes, total
recipe-to-server assignments, distinct covered servers, and the latest update.
Usage filters and metrics share model scopes so their counts remain aligned.
Recipes can be duplicated into a new encrypted, unassigned copy. The copy opens
for review and renaming, while the source recipe and all server assignments remain
unchanged.
The current filtered recipe inventory can be exported as a spreadsheet-safe CSV
with assignment metadata. Encrypted script bodies are never included.
Recipe detail pages show owner-scoped server assignments in their per-server
execution position, with ready, provisioning, and failed status totals. The usage
view selects only server metadata and never decrypts or renders the recipe script.
It also explains that existing servers retain their immutable encrypted plan snapshot.
Recipes can be explicitly published to the authenticated community gallery with a
category and contributor attribution. Gallery visitors can search, sort, inspect the
complete Bash script, and add a private encrypted snapshot to their own account.
Install counts track reuse without linking the imported copy to future source edits.
Imported copies retain contributor provenance and their installed gallery revision.
The app highlights newer upstream revisions, prevents accidental duplicate installs,
and refreshes only private copies after the user reviews the changed script.
An owner-scoped comparison page places the encrypted installed snapshot beside the
current gallery script and metadata before any refresh action is confirmed.
Installed users can leave one editable 1–5 rating per gallery recipe. Aggregate
scores and rating counts appear in the gallery, with a top-rated sort; contributors
cannot rate their own recipes and uninstalled users cannot submit ratings.
Personal gallery filters show recipes installed by the current account, imports with
new upstream revisions, and recipes published by that account. Cards expose installed,
update-available, and contributor ownership states without loading private scripts.
Operators can also save published recipes without installing executable copies.
Saved recipes are private to each account, available as a gallery collection filter,
and can be added or removed from both gallery cards and recipe detail pages.
Users can privately report security, broken, outdated, misleading, or other issues
and revisit them through a personal gallery filter. Contributors receive structured
counts and recent report details without reporter identities, while audit entries
record report actions without copying the submitted details. Free-text details are
encrypted at rest and loaded only for the contributor feedback view.
Contributors can resolve reviewed reports to clear dashboard attention without
deleting the anonymous feedback. A reporter update reopens the report so changed or
recurring issues return to the contributor's review queue.
An account-wide feedback inbox keeps every report reachable with anonymous details,
recipe search, status, reason, and reported-date filters, filter-aware totals, and
pagination. Minimum-age filters isolate feedback older than 24 hours, 7 days, or 30
days and carry through to pagination and CSV exports. Newest, oldest, and
recently-updated ordering supports both incoming and
stale-feedback triage. Priority ordering ranks security, broken, misleading, outdated,
and other issues deterministically within each review state, and the CSV export uses
the same order. Resolved reports can be reopened when further review is needed.
Contributors can resolve up to one page of selected reports at once. The action
validates ownership for the complete selection before changing anything and records
anonymous per-recipe audit summaries.
Resolved reports can also be selected and reopened in batches of up to 20. Bulk
reopening clears stale resolution notes and restores contributor and reporter
notifications while preserving anonymous per-recipe audit summaries.
Each batch action has independent select-all-visible controls, a live selection
count, and action-specific validation feedback in mixed-status inbox views.
Single-report resolution can include an optional encrypted note explaining what was
addressed. Only the contributor and original reporter can view it; reopening or
updating the report clears the stale note before another review cycle.
While a report remains resolved, its contributor can correct or clear that note
without reopening the issue. Actual changes create a metadata-only audit event and
replace the reporter's unread resolution notice; unchanged submissions are no-ops.
The filtered inbox can be streamed as a spreadsheet-safe CSV for offline review;
the export remains owner-scoped and contains no reporter identity.
New feedback creates one anonymous contributor notification per open report. Report
updates do not duplicate an unread alert, while reopening creates a new alert after
the previous one was acknowledged. Resolution marks matching alerts read while
retaining history; withdrawal removes notifications whose report no longer exists.
Opening a contributor feedback notification uses an owner-scoped focused inbox view
and anchors the exact report card, including when notification history is revisited
after the report was resolved.
Reporter lifecycle notifications open a private status page rather than depending on
the public gallery route. The page remains usable after unpublishing, reveals no
recipe script, and allows the original reporter to withdraw their report.
Resolution and reopen notifications continue after unpublishing because their
destination no longer depends on public gallery availability.
Withdrawing a report or deleting its recipe removes only the corresponding contributor
and reporter notifications before the report row disappears, preventing dead links
without disturbing unrelated notification history. Notification cleanup, row deletion,
and the matching audit entry commit atomically, so a failed audit write restores the
report or recipe and its notification history instead of leaving partial state.
Submission, resolution, resolution-note changes, and single or bulk reopening use the
same guarantee: report state, contributor acknowledgement, reporter notification, and
anonymous audit history either all commit or all roll back.
Single-report decisions lock the recipe and report rows in a consistent order, so
overlapping submissions, reviews, withdrawals, and recipe deletion cannot act on a
stale state or duplicate lifecycle notifications and audits.
SQLite deployments reserve the writer before reading that state and use a bounded
busy timeout with WAL journaling, allowing competing writers to wait while readers
remain available.
A private My Reports history lists every report owned by the reporter, including
unpublished recipes, with recipe search, lifecycle and availability filters,
unread/reviewed contributor-update and issue-type filters,
newest/oldest/recently-updated ordering, filter-aware metrics,
pagination, and links to the durable status page. List queries
omit encrypted report text and recipe scripts.
Unread contributor changes are highlighted only on the reporter's current history
page and private status view. Reporters explicitly review them through the existing
CSRF-protected notification action; simply opening a status page does not mutate state.
My Reports also shows a filter-independent unread-update total and can review every
report update at once without changing unrelated or other users' notifications.
Reporters can stream the filtered history as a private spreadsheet-safe CSV containing
their own details and contributor resolution notes, without reporter account identity
or recipe scripts.
The dashboard summarizes open feedback without loading encrypted text and links
directly to all open reports, security reports, and reports at least seven days old.
Each affected-recipe card opens an owner-scoped inbox focus containing the complete
matching report set rather than only the recent subset on the gallery detail page.
Reporters receive private informational notifications when contributors resolve or
reopen their feedback. These contain only the recipe name and note availability,
replace contradictory unread lifecycle notices, and link back to the gallery recipe.
The reporter's gallery collections can separate all reported recipes into feedback
still needing contributor review and resolved feedback. Cards show only the safe
issue type and review state; encrypted report and resolution text stays unloaded.
Recipe creation, publication changes, gallery installs and refreshes, and rating
changes are recorded in the owner-scoped activity history without script contents.

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

Operators can also create a shallow local checkout of a public repository for
inspection or tooling without registering it as a deployment target:

```bash
php artisan lessbuild:repository https://github.com/lessbuild/app.git main --name=lessbuild-app
```

The command accepts public GitHub, GitLab, and Bitbucket HTTPS or common SSH URL
forms, validates the branch and destination name, disables interactive Git
credential prompts, and invokes Git without a shell. Existing checkouts are
left untouched unless `--force` is supplied; forced replacement clones into a
temporary directory first and only swaps it into place after Git succeeds.
Checkouts default to `storage/repositories`; set
`REPOSITORY_CHECKOUT_DIRECTORY` to use another operator-controlled directory.

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

Unexpected production failures return a self-contained recovery page, or a compact
JSON error for API clients, with a UUID reference and no exception details. The same
reference is attached to the structured exception log so an operator can correlate a
user report without asking for sensitive request data. It is also returned in the
`X-Incident-ID` response header for proxies and API tooling. Error responses are
private and non-cacheable; expected HTTP errors such as 404 retain their normal status
pages. Locate the newest matching entry across retained and rotated Laravel logs
without printing exception details or stack traces:

```bash
php artisan lessbuild:incident 123e4567-e89b-42d3-a456-426614174000
```

Run a read-only operational check after installation, configuration changes, or
an incident:

```bash
php artisan lessbuild:diagnose
php artisan lessbuild:diagnose --json
```

The command checks key presence, URL validity, database connectivity, migrations,
writable runtime directories, production debug mode, queue configuration, pending
database-queue depth/age, failed jobs, and (for systemd installations) the required
web, worker, watchdog, health-monitor, and SQLite backup units. `DIAGNOSTIC_QUEUE_BACKLOG_LIMIT` and
`DIAGNOSTIC_QUEUE_OLDEST_MINUTES` set the pending-work limits (100 jobs and 15
minutes by default). The daemon installer enables timer inspection automatically;
custom deployments can opt in with `DIAGNOSTIC_SYSTEMD_TIMERS=true`. External queue backends are identified as requiring their own
inspection rather than being reported as locally verified. It
returns a failing exit code when any check fails and never prints keys, credentials,
connection names, job payloads, or exception details.
Verified users can review the same fresh, read-only snapshot from **System health**
in the primary navigation. The page is private and non-cacheable, reports an explicit
overall state, and preserves the command's summarized output boundaries. Its download
action produces a timestamped JSON incident artifact with the same sanitized checks,
summary counts, and no credentials, queue payloads, or exception details.
The dashboard caches only the overall counts and up to three failing check names for
60 seconds, making degraded platform state visible without running system commands on
every page view. Set `DIAGNOSTIC_DASHBOARD_CACHE_SECONDS` to tune this short summary TTL.

The production stack writes dated Laravel logs and retains 14 days by default instead
of growing one file indefinitely. Set `LOG_STACK` to a comma-separated channel list
or `LOG_DAILY_DAYS` to adjust the bounded retention policy; the daemon installer
enforces the daily production defaults.

```bash
sudo ./scripts/install-daemon.sh
```

Pass a public IP explicitly when automatic detection is not appropriate:

```bash
sudo ./scripts/install-daemon.sh 203.0.113.10
```

The daemon installer provides a simple private-IP runtime at
`http://PUBLIC_IP:8003`. Do not expose that development server as the public
production runtime.

The control plane requires PHP 8.5 with the extensions verified by `composer check-platform-reqs`; PHP 8.5.10 was used for this upgrade. Run the web process, workers, scheduler, and Composer with the same supported PHP runtime. The daemon installer accepts `BUILDPUSHER_PHP_BINARY=/usr/bin/php8.5` when several PHP versions are installed. See [the dependency upgrade record](docs/php-dependency-upgrade-2026-09-06.md) for rollout requirements and upstream version constraints.

For the BuildPusher domain, Caddy serves `public/` directly and forwards PHP
requests to the PHP 8.5 FPM Unix socket using [deploy/Caddyfile](deploy/Caddyfile).
The FPM pool uses `ondemand` process management with four children on the
current small host. The `www-data` account needs read access to the application
and write access only to `database/`, `storage/`, and `bootstrap/cache/`.
Production diagnostics should set:

```dotenv
DIAGNOSTIC_SYSTEMD_SERVICES=php8.5-fpm.service,lessbuild-worker.service,caddy.service
```

Check the web and worker processes with:

```bash
systemctl status php8.5-fpm lessbuild-worker caddy lessbuild-backup.timer lessbuild-watchdog.timer lessbuild-health.timer
journalctl -u php8.5-fpm -u lessbuild-worker -u caddy --since today
curl --fail https://buildpusher.com/api/health
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
Changing a local-password account's email requires its current password, logs
out other browser sessions, and automatically sends a verification link to the
new address. Name-only profile edits do not require password confirmation.
The account page lists linked GitHub, GitLab, and Bitbucket sign-in identities.
Configured identities can be connected explicitly and disconnected while a
local password or another linked provider remains available, preventing
accidental account lockout. A guest social callback never links an existing
account merely because its email matches; the user must authenticate first.
Accounts with a local password must reconfirm it before starting a new social
connection and provide it again when disconnecting one; both operations are rate
limited. Connection confirmation expires using Laravel's configured password
timeout. Social-only accounts can still connect or disconnect a second provider
while retaining another provider as fallback.
Browser sessions are encrypted in the application database and the account page
shows each active browser's derived browser/platform label, IP address, and last
activity time without loading session payloads. Local-password users can revoke
one owner-scoped session or invalidate every other browser session after providing
their current password. The current browser cannot be individually revoked; bulk
revocation rehashes the password, stale sessions are rejected by session middleware,
and the initiating browser remains authenticated.
Password confirmation, profile updates, password changes, session revocation,
reset-link requests, and reset submissions share a six-attempt-per-minute
sensitive-action limiter.
Authenticated attempts are isolated per account; guest reset flows also enforce
a broader IP ceiling and use hashed IP/email keys without storing email addresses
in rate-limit cache keys.
Forgot-password requests return the same generic result for registered and
unregistered addresses, while failed reset submissions use one invalid-or-expired
message. Reset emails are still delivered only to real accounts, preventing
public response content from disclosing account existence.
Profile and email changes, password changes and resets, other-session revocation,
and social sign-in connection changes are recorded in an owner-scoped Account
activity category. These entries contain action descriptions only—never IP
addresses, session identifiers, provider IDs, tokens, or credential content.
The account page shows the five most recent security actions beside credential
and session controls, with a direct link to the full filtered audit for verified
owners. Unverified owners can still review this local security history.
The same metadata-only actions create neutral Account alerts in the notification
inbox, including password resets performed while signed out. Account alerts can
be filtered, exported, marked read, or opened directly back to account settings.

The seeded owner signs in as `ncorkish@icloud.com` with password `password`.
Fixtures use a stable `[Demo]` prefix and include provider health states,
recipes, servers, websites, repositories, deployment and webhook history,
logs, server commands, activity, and notifications. Tokens, keys, domains, and
IP addresses are deliberately non-functional examples. Server and website
fixtures cover every provisioning state, including queued, waiting-for-IP, and
in-progress records, without dispatching real cloud jobs. Running the seeder
again updates the demo workspace without deleting other account-owned records.
The account also includes a harmless non-authenticating Chrome-on-macOS session
fixture using a documentation-only IP address so individual session revocation
is testable. Successful password, GitHub, and GitLab sign-in fixtures cover desktop
and mobile history rendering without creating functional external identities.
Automatic provider and website monitoring is paused for all demo fixtures, so
scheduled timers never contact their deliberately non-functional endpoints.
Each website can choose a 5, 10, 15, 30, or 60 minute automatic health-check
interval. The due-site query applies the stored cadence independently while
manual and post-deployment checks remain immediate. Demo websites permanently
cover every supported interval without enabling network traffic.
Every accepted website health check records its manual or automatic source,
endpoint, HTTP status, curl duration, bounded safe error, and check time. Website
details show the newest 20 results and offer a spreadsheet-safe export; each
website retains only its newest 100 results. Demo websites include idempotent
healthy, failing, manual, and automatic history without contacting their hosts.
Website detail also summarizes the retained sample with an observed check success
rate, median successful-response time, and newest consecutive-failure streak.
These figures are explicitly labeled as recorded observations rather than SLA
uptime, and empty or unreported samples never produce invented percentages.
The full retained website health history has an owner-scoped paginated view with
result, source, and checked-date filters. Its spreadsheet-safe CSV export applies
the same normalized filters, so an operator can download exactly the evidence
currently under review without exposing another account's checks.
Filter-aware history cards summarize healthy and failed checks, observed success,
median healthy response time, and the latest matching check. Calculations inspect
only safe metric fields from the bounded retained sample and never imply SLA uptime.
Server-command fixtures likewise cover queued, running, succeeded, failed, and
canceled states without dispatching remote command jobs.
Deployment detail pages show a validated elapsed duration and stable previous/next
navigation within the same repository. The permanent build fixtures include timed
history for trying both controls; unfinished or inconsistent timestamps are shown
as not recorded instead of producing misleading durations.
Adjacent deployments can be compared side by side without contacting a source
provider. The comparison is owner-scoped and limited to distinct builds from the
same repository, with escaped revision, outcome, timing, commit, failure, and
operator-note metadata plus an explicit faster/slower duration delta.
Repository pages summarize all-time deployment totals, succeeded and failed runs,
and a completed-run success rate that explicitly excludes canceled and active
runs. They also show a median duration calculated from at most the 20 newest valid
timings and link directly into the repository-filtered full deployment history.
Server log snapshots cover queued, refreshing, ready, and failed states so polling
and result views remain testable without opening SSH connections or dispatching
log-refresh jobs.
Server detail includes a metadata-only overview across the five allowlisted log
types, with ready, queued, refreshing, failed, and not-collected counts plus the
latest refresh time. Non-selected log and error bodies are not loaded for it.
Servers can also have an optional 80-character control-panel display name. The
label is used consistently in inventories, search, dashboards, related-resource
views, and exports while the original provider hostname remains unchanged and
visible on the server page. Clearing the label restores the cloud hostname.

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
History can also be scoped to a website across every repository targeting it,
with a direct link from website detail. Website filters remain owner-scoped and
apply consistently to the list, insight cards, pagination, and CSV export.
The same history can be scoped to a server across all websites and repositories
hosted there, with a direct server-detail link and the server display label in
the filter selector. Server filters retain the same ownership and export guarantees.
Source-provider filtering combines deployment history across every repository
using one GitHub, GitLab, or Bitbucket credential. Source-provider detail links
open the filtered view directly; infrastructure-only providers are excluded.
Filter-aware history cards summarize matching and active deployments, successful
and failed outcomes, an observed completed-run success rate that excludes active
and canceled runs, and the latest matching deployment. Empty or canceled-only
views report an explicit unavailable rate instead of inventing a percentage.
Owners can attach a 2,000-character operational note to any deployment for
incident IDs, rollback reasons, approvals, and handoff context. Notes are escaped,
searchable in build history, and spreadsheet-safe in exports. Note changes create
metadata-only deployment activity without copying note contents into the audit.
The repository inventory can be searched and filtered by source provider,
deployment website, and latest deployment state. It also shows each target,
provider, and latest deployment without loading the entire inventory at once.
Filter-aware repository cards summarize matching and never-deployed repositories,
active, successful, and failed latest deployments, and enabled push webhooks.
Latest-status filtering and metrics share model scopes so older failed builds do
not misclassify a repository whose newest deployment recovered.
Repository detail also summarizes matching webhook deliveries across queued,
pending, unavailable, superseded, and received states. These filtered counts use
one owner-authorized grouped query without loading revision or commit metadata.
The current filtered inventory can be exported as a streamed,
spreadsheet-safe CSV without credentials or deployment commands.
The filtered server inventory can also be streamed as a spreadsheet-safe CSV
with display label, cloud hostname, platform, address, provider, status, and
website-count fields while
excluding server credentials and operational logs.
Filter-aware server cards summarize matching, ready, provisioning, and failed
servers, the number of attached websites, and the latest matching record. The
counts use metadata-only owner-scoped queries, and empty views explicitly report
that no matching server timestamp is available.
Website inventory exports preserve provisioning, health, and attention filters
and include target, health, retention, and repository-count fields without
environment values, database credentials, tokens, or raw health errors.
Filter-aware website cards summarize matching, active, provisioning, failed,
unhealthy, and attention-required sites. The attention filter and metric share
one model scope, so a provisioning failure or an enabled unhealthy check is
classified consistently without loading encrypted environment data.
Deployment output, website provisioning output, and allowlisted server log
snapshots can be downloaded as owner-authorized plain-text files for incident
review and support handoff.
The activity timeline can be searched and filtered by category or date range;
the same owner-scoped view can be exported as a streamed, spreadsheet-safe CSV
for audits and incident handoff.
Filter-aware activity cards summarize matching deployment, infrastructure,
server-command, and account-security events plus the latest matching event.
General or legacy events remain in the total without being misclassified, and
empty filtered views report an explicit unavailable latest event.
The notification inbox can filter alerts by search text, category, failure/recovery/
information status, read state, and created-date range, then export that same
owner-scoped view as a spreadsheet-safe CSV without exposing raw notification
payloads or framework ownership fields.
Filter-aware inbox cards summarize matching alerts, unread work, recognized failure,
recovery, and information states, and the latest matching alert. Empty filtered
views report zero values and an explicit unavailable latest event.
Individual alerts can be deleted without clearing other read or unread
notifications.
Read notifications are retained for 90 days after review by default and pruned
only after the daily database backup is verified. Unread notifications are
always preserved. Set `NOTIFICATION_RETENTION_DAYS` to adjust the window.
Each server retains an encrypted command history with status, retained-output, and
queued-date filtering plus owner-authorized output downloads. Queued commands can be canceled atomically;
if a worker has already started one, cancellation safely loses the race instead
of claiming to stop an in-flight remote process. Completed, failed, and canceled
commands can be queued again from history while retaining explicit lineage to
the original encrypted execution. Filtered command history can be exported as
a spreadsheet-safe CSV with lineage and timing metadata; retained output remains
available only through its separate owner-authorized download. Terminal command
records and their encrypted output can also be deleted individually; active
commands remain protected.
Server command history likewise offers a filter-preserving status refresh while
matching commands remain queued or running.
Filter-aware history cards summarize matching, active, successful, failed, and
canceled commands plus records with downloadable output. Counts remain scoped to
the authorized server and never require decrypting command text or retained output.
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
minutes. Each website can require 1, 2, 3, 5, or 10 consecutive failures before
it is marked unhealthy and one unread notification is created; a successful
check records recovery and resets the failure count.
Recovery also acknowledges unread failure alerts for that website and adds a green
recovery alert, preserving both sides of the incident in the inbox.
Website owners can also queue an immediate, deduplicated check from the website
page without waiting for the next timer run.
Scheduled website monitoring can be paused independently of deployment-time and
manual health checks. This preserves the last known state while stopping outbound
scheduled checks and alerts until monitoring is resumed.
Set `HEALTH_MONITOR_BATCH_SIZE` to tune the number of sites checked per run.
`HEALTH_MONITOR_FAILURE_THRESHOLD` selects the default for newly created sites;
each site's saved outage-confirmation setting governs subsequent checks.
Each website can retain between two and twenty releases on its server; five are
kept by default for rollback and recovery.

Repositories can define encrypted custom build commands that run before release
activation and post-deployment commands that run before the health check. Hooks
execute in isolated Bash processes, and a failed post-deployment hook restores
the previous release through the normal deployment failure path.

Successful releases can be promoted forward from preview or development through
staging to production. Promotion pins the exact verified commit, snapshots the
target environment's runtime, variables, processes, and resources, and retains
source-build lineage plus an encrypted change note. It intentionally rebuilds
that revision for the target rather than claiming a portable binary artifact.
Target readiness, source identity, active-deployment serialization, deployment
locks, maintenance windows, and approval requirements are all enforced. Locks
and windows are checked again when an administrator approves a waiting release.
The workflow is available in the application view, deployment evidence, CSV
history, and the scoped control-plane API.

Deployment, website, and server failures create unread in-app notifications.
When failed server or website provisioning later reaches active state, Lessbuild
acknowledges its open failure and adds a green recovery alert. Intermediate retry
states do not close the incident prematurely.
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
### GitHub App

For repository discovery and automatic push webhooks, create a GitHub App with
repository Contents read permission, Metadata read permission, and Push and Pull
request event subscriptions. Set its setup URL to
`https://buildpusher.com/github-app/callback` and webhook URL to
`https://buildpusher.com/api/github-app/webhook`, then configure:

```dotenv
GITHUB_APP_ID=123456
GITHUB_APP_SLUG=buildpusher
GITHUB_APP_PRIVATE_KEY=/absolute/path/to/buildpusher.private-key.pem
GITHUB_APP_WEBHOOK_SECRET=a-long-random-secret
```

The install option remains hidden until all four values exist. Installation
tokens are minted only when needed and are not persisted. Keep the PEM outside
the repository, readable only by the application service account, then clear
the configuration cache and restart the application and worker services.

### Stripe subscriptions

BuildPusher uses Laravel Cashier with Stripe Checkout and the Stripe customer portal. Create recurring monthly prices for **Starter ($9)**, **Pro ($19)**, **Team ($49)**, **Business ($99)**, and **Unlimited ($199)** in Stripe, then configure:

```dotenv
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_STARTER_PRICE_ID=price_...
STRIPE_PRO_PRICE_ID=price_...
STRIPE_TEAM_PRICE_ID=price_...
STRIPE_BUSINESS_PRICE_ID=price_...
STRIPE_UNLIMITED_PRICE_ID=price_...
```

Register `https://buildpusher.com/stripe/webhook` as the Stripe webhook endpoint and enable Cashier's required customer, subscription, invoice, and payment-method events. After changing the environment, run `php artisan optimize:clear` and restart the application and worker services.
