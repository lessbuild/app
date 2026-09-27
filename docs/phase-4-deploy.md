# Phase 4: Deploy

Design notes for the Deploy service in Phase 4 of [the plan](platform-v2-plan.md), ported from the Deployer module of the unified app (`app/app/Modules/Deployer`). It builds on Infrastructure: deployments run on its servers and websites.

The parts, in dependency order:

1. **Repositories and deployments** (D06 core, D07 core): repositories linked to a website, deploys with the release-per-build layout, the signed deployment callbacks, logs, cancel, redeploy, rollback, approvals, and repository webhooks.
2. **GitHub App** (D06): connecting, repository discovery, the App webhook, installation tokens for clones.
3. **Environments** (D04): deployment settings (branch, protection, approvals, locks, windows, strategy, observation, automatic rollback), variables and secrets with versions, processes, resources, runtimes.
4. **Configuration** (D05): review, plan and apply, operations, and the control-plane API.
5. **Previews** (D08).
6. **Automation** (D17): deployment and scaling schedules, scheduled tasks, hibernation.
7. **Recipes** (D18), which server creation then also takes.

## Model

- A **repository** belongs to the account's project and deploys to one **website** (Infrastructure), optionally for one of the project's **environments**. It is cloned over HTTPS with a Git provider's token: a GitHub, GitLab or Bitbucket provider from Account → Providers, and later a GitHub App installation. It records the branch, an optional subdirectory to deploy (`deployment_root`), build commands, post-deployment commands, and paths that do or don't trigger automatic deploys.
- A **build** (Deployer's name, kept; Monitoring already uses "deployments" for its markers) is one deploy of a repository. Its states are:
  - `queued`, or `awaiting_approval` when the environment requires approval (then `rejected`)
  - `deploying` while the script is uploaded, then `running` on the server
  - `succeeded`, `failed` or `canceled`
- Each build is a release directory on the server: `/var/www/{slug}/releases/{release}`, with `shared/` for storage and `.env`, and `current` pointing at the live release.
- **Native integration:** a succeeded build records a Monitoring release and deployment marker for its environment (source `deploy`), so issues and incidents link to it without the deployments API.

## Deployments (part 1)

- A deploy uploads one script to the website's server (`RemoteScriptRunner`) and starts it in the background. The script's stages, ported from Deployer:
  1. clone and check out (reporting the revision)
  2. sync the environment
  3. install dependencies
  4. run the build commands
  5. link shared paths and run artisan commands
  6. validate the candidate, then activate it (swap `current`)
  7. configure the web runtime
  8. run the post-deployment commands
  9. verify health, then purge old releases (keeping the website's `release_retention`)
- The script reports each stage to signed callbacks. On failure it restores the previous release.
- **Callback URLs are a public contract and stay unchanged:** `/builds/{build}/deployment/callback/{status,revision,failed,log}`, signed.
- One build runs per website at a time. A deploy while one is active is refused. A build that stops reporting is failed after a timeout.
- **Redeploy** reruns a build's revision. **Rollback** switches `current` back to an earlier succeeded release that's still on the server, without rebuilding.
- **Cancel** stops a queued build, or kills a running one and restores the previous release.
- **Approval:** a build waiting for approval is approved or rejected by someone else with deploy rights.
- **Repository webhook:** `POST /api/repositories/{repository}/webhook` with the repository's secret (GitHub, GitLab or Bitbucket signature formats) queues a deploy for pushes to the branch, subject to the path filters. Deliveries are recorded (deduplicated by delivery ID) and can be retried. This URL is a public contract.
- Who can do what:
  - Anyone with Deploy access sees repositories, builds and logs.
  - Owners and admins manage repositories.
  - Members with Deploy access deploy, redeploy, roll back and cancel.
- Plan limits: `deploy.releases` gates release history and rollback, as in Deployer. Deploy minutes aren't metered, as in Deployer.

## GitHub App (part 2)

- The platform's GitHub App is configured by environment (`GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_WEBHOOK_SECRET`, and `GITHUB_APP_PRIVATE_KEY` or `GITHUB_APP_PRIVATE_KEY_PATH`). Deployer's local-only page for uploading the private key isn't ported; operators set the key in the environment.
- **Installing:** Account → Providers offers "Install the GitHub App" when it's configured (owners and admins).
  - `/github-app/connect` sends the person to GitHub with a one-time state kept (hashed) in their session.
  - GitHub returns to `/github-app/callback`, the App's Setup URL, unchanged. It records the installation as a GitHub provider with `credential_type = app` and the installation ID, named after the GitHub owner.
  - `/github-app/providers/{provider}/repositories` lists what the installation can reach, with links to connect each repository in a project.
- **Clones and webhooks:**
  - Clones mint a short-lived installation token (a JWT signed with the App's key).
  - Repositories on an App provider get push deploys automatically. The App's webhook `POST /api/github-app/webhook` (public contract) is verified with the App's secret and routed to the repository with that URL and installation.
  - Checking the connection mints an installation token.

## Environments (part 3)

- Deploy → Environments lists the project's environments (created under the project, as before) with their Deploy settings. Members and above with Deploy access change them (`EnvironmentPolicy::configureDeploy`); changes apply to the next deploy, because each build captures them (encrypted) when queued.
- **Controls:** a lock, with a reason, and a weekly window (ISO weekdays, start and end times, a time zone; an end before the start runs past midnight). Manual deploys are refused while blocked. Pushes wait (`webhook_pending`), and `builds:release-pending` (every minute) deploys them once allowed.
- **How deploys run:**
  - approval
  - strategy: blue-green, canary (the new release is checked on a local port before going live) or rolling (workers restart one at a time)
  - automatic rollback
  - post-deploy observation: 5, 10, 15 or 30 minutes of health checks by `builds:observe`; a failure rolls back when automatic rollback is on
  - runtime: PHP, Node.js, Python or Docker, with version, build and start commands, port, Dockerfile
  - replicas: more than one needs `deploy.scaling`
- **Variables** are encrypted and versioned. They're used for runtime (`.env`), build only, or both. Secrets are masked. A pasted `.env` can replace them all.
- **Processes:** workers, and the scheduler, which always has one replica. They run as systemd units, restarted each deploy, and need `deploy.workers` (Starter and above).
- **Resources:** managed MySQL (the website's own database), managed Redis or Valkey, or external services described by variables. They need `deploy.resources`.
- Hibernation and scaling schedules come with automation (part 6).

## Public contracts kept

- Build callback URLs and their signed parameters.
- `POST /api/repositories/{repository}/webhook` and (part 2) `POST /api/github-app/webhook`.
- Deployer API v1 (`/api/v1/me`, `projects`, `deployments`, `environments/{environment}/deploy|scale|runtime|variables`, configuration routes) arrives with part 4 and Phase 6.

## For the importer (Phase 7)

- Repositories and builds keep their IDs (webhook and callback URLs contain them). Webhook secrets and provider tokens are re-encrypted.
