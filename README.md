# Deployer

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
reboot. On a new host it also creates a local SQLite database. A `sync` queue
configuration is automatically upgraded to the database queue so job retries
and backoff work outside web requests.

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
systemctl status lessbuild-app lessbuild-worker
journalctl -u lessbuild-app -u lessbuild-worker --since today
php artisan queue:monitor database:default --max=10
```

### Todo
 

- [ ] Need to refactor scripts -- too much logic in them.
- [x] Use each server's generated key pair for SSH and encrypt private keys at rest
- [x] Refactor server providers behind a common lifecycle interface
- [x] Run selected user-defined recipes during server provisioning
- [x] Add recipe creation and management
- [x] Persist server log snapshots and display them without render-time SSH commands
- [x] Use a type-specific provisioning plan when creating servers
