# Phase 4: Infrastructure

Design notes for the Infrastructure service in Phase 4 of [the plan](platform-v2-plan.md), ported from the Deployer module of the unified app (`app/app/Modules/Deployer`). Deploy comes after it, because deployments run on these servers.

1. **Providers and servers** (D09): cloud, DNS and source-control credentials, connection checks; creating cloud servers, provisioning, callbacks, retries, deletion; importing an existing server.
2. **Server operations** (D10): commands and their history, logs, metrics, diagnostics, the troubleshooting terminal.
3. **Websites and domains** (D11, D12).
4. **Health checks, backups and restores** (D13).
5. **Databases** (D14).
6. **Load balancers and scaling** (D16).
7. **Costs** (D15).

## Model

- **Providers** belong to the account: they're credentials, used by Infrastructure (DigitalOcean, Hetzner Cloud, Vultr, Cloudflare) and later Deploy (GitHub, GitLab, Bitbucket). Tokens are encrypted. Account admins manage them on the account's **Providers** page. Each has a connection health state; a manual check or the `providers:check` command (every five minutes, for providers due by their interval) records the result, keeps the last 100 checks, and tells the provider's creator when the connection fails or recovers. Automatic checks are on for every plan (they were a paid Deployer feature; they cost nothing to run).
- **Servers** belong to the account, like Deployer's organisation servers, so every project can deploy to them. The Infrastructure section of any project lists them. The count is limited by `infrastructure.servers.max` (from the Deploy plan). Managing servers needs account settings access (owners and admins); anyone who can use Infrastructure sees them.
- **Creating a server** makes an SSH key pair, registers the public key with the provider, and creates the cloud server with the provisioning script as user data. `InitialiseServer` waits for the public IP and pins the SSH host key. The script reports each stage to signed callback URLs (`/servers/{server}/provisioning/callback/{status,failed,log}`, unchanged); the last stage makes the server active. A failed creation removes what it created. Failed initialisation and failed remote provisioning can be retried; a remote retry uploads the remaining stages over SSH.
- **Importing a server**: an admin gives the address, SSH port and a root private key; a read-only SSH inspection checks Ubuntu version, architecture, memory, disk and existing services and shows warnings. Confirming (within 30 minutes, once) creates the server and runs provisioning over SSH.
- **Deleting a server** deletes the cloud server and the SSH key it created, then the record.
- The root password is shown once after creation and not kept. The MySQL root password is shown once too, and kept encrypted because websites need it.

## Server operations (part 2)

- **Commands**: account admins run a root shell command on an active server, one at a time (it times out after `SSH_COMMAND_TIMEOUT`). Command and output are encrypted; the history can be filtered, run again, deleted when finished, and exported as CSV (formula cells escaped). `servers:prune-commands` deletes finished commands after 180 days. Deployer only let a server's creator run commands; here any owner or admin can.
- **Logs**: the last 200 lines of APT, Caddy, MySQL, PHP-FPM and cloud-init logs, fetched on request.
- **Metrics**: `servers:collect-metrics` (every five minutes) reads load, CPU, memory, disk, network and process counts; 30 days are kept and the server page charts the last 24 hours. Deployer's server metric alert rules come with part 4.
- **Diagnostics**: a read-only script checks SSH, root access, architecture, PHP, the application path, disk and memory (warn above 90%) and processes. It needs an active server with a pinned host key.
- Everything over SSH goes through `ServerShell` (tests fake it).

## Websites and domains (part 3)

- A **website** belongs to the account (like servers) and lives on an active app server with MySQL: a Caddy site under `/etc/caddy/websites/{slug}.conf`, a MySQL database and user named after the slug, and an encrypted `.env` at `/var/www/{slug}/.env`. The count is limited by `deploy.websites.max`. Deploy will link websites to environments (`environment_id`) and add releases.
- Setup runs over SSH and reports each stage to signed callbacks (`/websites/{website}/provisioning/callback/{status,failed,log}`, unchanged). Changing the server, domain or `.env` sets it up again; a move keeps the old copy until the new one is live, then removes it (retryable if that fails). Deleting removes the files, Caddy site and database from the server in the background.
- **Import** adopts an application already in `/var/www/{slug}` without touching it.
- **Domains**: the primary domain follows the website's URL; aliases and redirects are added to Caddy (`ApplyWebsiteDomains`). With a Cloudflare provider the A/AAAA record is kept pointing at the server (in the longest matching zone, not proxied). `TEMPORARY_APP_DOMAIN` enables random temporary hostnames. `domains:check` (hourly) records whether DNS points at the server and when the certificate expires (warning at 21 days).
- The troubleshooting terminal from Deployer (a broker process streaming an SSH PTY through the database) isn't ported: nothing in the unified app's UI used it. It's listed as an open item.

## Health and backups (part 4)

- **Health checks** on a website linked to an environment are Monitoring HTTP monitors (`WebsiteHealthChecks::sync`), so failures open incidents and use Monitoring's alert routing. **Server alert rules** (one server or the whole account) tell owners and admins after enough breaching readings in a row, and again on recovery.
- **Backup destinations** belong to the account (Infrastructure → Backups): S3-compatible buckets (DigitalOcean Spaces, Amazon S3, Cloudflare R2, others), with keys and a generated restic password stored encrypted. "Check connection" writes, reads and deletes a test object with Signature V4 requests. The endpoint, bucket and prefix are fixed once backups are stored there; a destination in use can't be deleted.
- **Backups** run restic on the website's server over `ServerShell`: a MySQL dump, the `.env` and `shared/storage`, one repository per website (`{prefix}/websites/{id}`), pruned to the schedule's retention. One backup per website at a time; a failed run is retried once. Schedules are daily or weekly at a UTC time; `backups:run` (every five minutes) queues the due ones. Running and scheduling need the `deploy.backups` plan flag (Pro and above); restoring works on any plan.
- **Restores** put a snapshot over the live site in maintenance mode, keeping a safety copy of the database, `.env` and storage and rolling back if any step (including the health check) fails. They need owner or admin rights and password confirmation.
- **Verification** restores a snapshot into a temporary directory and database on the same server, checks the dump, runs `php artisan migrate:status` against it, and removes both; the script's `BP_*` markers record which stage passed. The Backups page shows when a backup, a restore and a verified restore last succeeded.
- Deployer also blocked backups and restores during a deployment; that check returns with Deploy.

## Databases (part 5)

- Deployer's database tools worked on environment resources (MySQL and PostgreSQL entries in an environment's configuration). Here they work on each website's own MySQL database, the one Infrastructure creates; PostgreSQL and other resources come back with Deploy's environments (D04) and reuse the same commands.
- **Inspection** records the database's size, open connections and tables (`databases:inspect` daily for live websites, or on request). Snapshots are kept 30 days.
- **Users**: extra MySQL logins on `localhost` with read-only, read-and-write or full access and an optional expiry (1, 7, 30 or 90 days). The password is generated and shown once. `databases:expire-users` (every 15 minutes) drops expired users. Removing a user works on any plan.
- **Copying** replaces another website's database with a dump of this one's. Both must be on the same server; websites linked to a production environment can't be overwritten; the person types the target's name and confirms their password.
- Inspecting, adding users and copying need the `deploy.resources` plan flag (Pro and above, like Deployer's `resources` entitlement). `deploy.cost_controls` was added alongside it for part 7.

## Load balancers and scaling (part 6)

- A **load balancer** belongs to the account and runs on one active server that has Caddy (a Load balancer, Web or App server). Its Caddy site (`/etc/caddy/websites/ha-{id}.conf`, the Deployer path) proxies the hostname to its **nodes** (servers in the account, a port, a weight of 1–10, in or out of rotation) with least-connections balancing and active health checks on the health path; with no usable nodes it serves a 503 page. It can name the website it fronts, which must live on another server.
- Every change rewrites the site and reloads Caddy (`ApplyLoadBalancer`); a failure is shown and can be retried. Deleting removes the site from the proxy server first; if that fails the load balancer stays, marked, until a retry works.
- Adding one needs the `deploy.high_availability` plan flag (Business and above, Deployer's `high_availability`). Changing and deleting work on any plan.
- **Scaling** in Deployer (replica counts, hibernation, scaling schedules) acts on an environment's processes, so it moves to Deploy with environments and processes (D04, D17).

## Public contracts kept

- Server and website provisioning callback URLs and their signed parameters, so anything mid-setup at cutover still reports in.

## For the importer (Phase 7)

- Providers and servers keep their IDs (callback URLs contain the server ID); SSH keys, host keys and tokens are re-encrypted with the new app key.
