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
- Provisioning passwords (root and MySQL) are shown once after creation and not kept.

## Public contracts kept

- Provisioning callback URLs and their signed parameters, so servers mid-provisioning at cutover still report in.

## For the importer (Phase 7)

- Providers and servers keep their IDs (callback URLs contain the server ID); SSH keys, host keys and tokens are re-encrypted with the new app key.
