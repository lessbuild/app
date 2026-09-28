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

## Promotion and the API (part 4a)

- **Promotion:** a succeeded build of a known commit can be promoted to a later environment of the same project (preview → development → staging → production). It ships through the target environment's repository for the same Git address and host, with that environment's approval, locks and windows. The new build records `promoted_from_build_id` and a note, and both build pages show the lineage.
- **Approval alerts:** when a build waits for approval, the people who could approve it are told by email and in the inbox. That's members with Deploy access, other than whoever asked for the deploy.
- **Deployer API v1:** the same paths, fields and status codes, now authenticated with v2 API tokens (`deploy:read` to read, `deploy:write` to change) and limited to the token's account and the person's Deploy access.
  - Reads: `GET /api/v1/me`, `/projects`, `/projects/{project}`, `/deployments` (optional `limit`/`cursor` pages), `/deployments/{build}`, `/deployments/{build}/log`.
  - Writes: `POST /deployments/{build}/rollback`, `/deployments/{build}/promote` (`target_environment_id`, `promotion_note`), `/environments/{environment}/deploy`, `PATCH /environments/{environment}/scale` (`replicas`, applied with the next deploy), `PUT /environments/{environment}/variables` (`variables`).
- **Differences from Deployer:**
  - Project and environment IDs are v2 ULIDs. Phase 6 maps old numeric IDs for existing tokens and scripts.
  - Old tokens' workspace and project claims become v2 account tokens.
  - `runtime` (hibernate/wake) and `workflow` arrived with automation (part 6); the configuration routes with part 4b.

## Configuration (part 4b)

- **Documents:** Deploy → Configuration takes a version 2 YAML document (Deployer's format) and bindings (JSON).
  - A document declares environments: type, placement, runtime, processes, resources, variables, adoption, removals and a deploy.
  - Bindings map its names to records: placements to websites, repositories to the project's repositories on that website, and secret refs to secret variables.
  - Parsing refuses aliases, deep nesting and oversized documents, and never echoes the document in errors.
- **Plan:** lists every change without writing anything.
  - Owned objects are updated. Existing ones that configuration doesn't own need `adopt: true`.
  - Only owned objects can be removed, and objects the document omits are left alone.
  - The production environment can't be added, removed or retyped.
- **Review:** freezes the plan for 15 minutes behind a keyed fingerprint of everything it read. Only its requester can apply it, and only if planning again gives the same fingerprint.
- **Apply:** runs under a project lock and records ownership. The deploy repository is linked to its environment. Each deploy becomes an operation, unless the environment's last deploy had exactly the same intent.
- **Operations** become builds once their gates pass: access, the repository unchanged, locks and windows, and no other deploy running. Otherwise they're `blocked`, and `configuration:dispatch` (every minute) retries them.
  - Results follow the builds.
  - Failed deploys can be retried as reviewed, by the requester only.
  - Deploys that haven't started can be canceled.
- **API:** the Deployer API's configuration routes keep their paths: `POST /api/v1/projects/{project}/configuration/plan`, `…/reviews` (201), `…/reviews/{review}/apply`, `GET …/applications/{application}`, `POST …/applications/{application}/operations/{operation}/{cancel|retry}`.
- **Differences from Deployer:**
  - v2 environments don't store a placement. It's used to check the deploy repository and to fill managed database variables.
  - Anyone with Deploy access (members and above) can manage configuration; Deployer allowed workspace managers.

## Previews (part 5)

- **Settings live on the source repository** (Deployer kept them on the project and guessed which environment a pull request belonged to). A repository's previews are on or off, with a wildcard domain (`pr-{number}-{project}.{domain}`, whose DNS points at the server), a lifetime in hours since the last activity (1–720, default 72) and an optional initialisation command (e.g. `php artisan migrate --seed`). They need `deploy.previews`; owners and admins change them.
- **Pull requests:** the repository webhook (and the GitHub App webhook) also receives pull/merge-request events. Deliveries are deduplicated like pushes.
  - Opening or updating a pull request into the repository's branch creates or updates its preview. Forks, other target branches and events that don't say where they come from are refused (Deployer's trust policy).
  - Closing or merging closes the preview.
- **A preview is its own stack**, made without an actor:
  - a website on the source website's server, which counts against `deploy.websites.max`;
  - a `preview` environment copying the source environment's runtime, processes (with `deploy.workers`) and managed Redis/Valkey (with `deploy.resources`);
  - a repository on the pull request's branch, with the webhook off.
  - Open previews count against `deploy.previews.max`, checked under an account lock so concurrent webhooks can't overshoot.
- **Configuration:** the preview website's `.env` holds only preview-owned values: its own `APP_KEY`, `APP_URL`, `APP_ENV=preview` and its own database, plus the source environment's non-secret runtime variables. Secrets aren't copied. Someone with Deploy management rights can approve chosen runtime secrets for the preview's current revision. The approval stores variable IDs and versions, never values. It lapses when the revision changes or a secret changes version.
- **Lifecycle:**
  - The preview deploys when its website is ready. A newer revision that arrives mid-deploy follows when the deploy finishes.
  - The status is `provisioning` → `deploying` → `ready` or `failed` → `closed`.
  - The initialisation command runs in the post-deployment stage of each build until one succeeds; a marker file on the server makes it run once.
  - GitHub App repositories get a check run on the revision and one pull-request comment, updated as the preview changes.
- **Closing:** a pull request closing, the lifetime running out (`previews:expire`, hourly), the source repository being deleted, or someone pressing Close.
  - Once no deploy is running, a cleanup job stops the preview's process units, removes its Valkey containers and volumes, and deletes its website (files, Caddy site, database). Then it deletes the preview environment and repository.
  - A failed cleanup shows its error and can be retried.
  - Deleting any website now also stops its `buildpusher-{slug}-*` units, which were left running before.
- Deploy → Previews lists the project's previews, open ones first, with expiry, cleanup state and secret approval. Preview repositories and environments are hidden from the Repositories and Environments lists.

## Automation (part 6)

- Each environment page has an **Automation** tab; members and above with Deploy access manage it (`configureDeploy`).
- **Cron semantics:** schedules use five-field cron in an IANA time zone. `automation:dispatch` runs every minute and claims each due schedule once per minute (`last_run_at`), so overlapping schedulers can't double-run one.
- **Scheduled deploys** (`deploy.scheduled`) deploy every repository connected to the environment, except previews'. They follow its approval, lock and window. A skipped run records why (`last_result`): locked, outside the window, already deploying or not ready.
- **Scaling schedules** (`deploy.scaling`) set the running replicas, within the environment's minimum and maximum, and apply them at once. Applying wakes a hibernated environment.
- **Replicas apply at once:** starting and stopping installed worker units, from the manifest the deploy writes. This also covers the page and `PATCH /api/v1/environments/{environment}/scale`, which used to wait for the next deploy. Raising the maximum still needs a deploy to install more units.
- **Scheduled tasks** (`deploy.scheduled`) run a command in one of the environment's websites' current release, as `www-data` with its `.env`, under a timeout (10–3600 s).
  - They can skip a run while the previous one is running.
  - "Run now" queues one by hand.
  - The last 50 runs keep their output (the last 64 KB, encrypted).
  - A task that starts failing, or recovers, tells the members with Deploy access (email and inbox) when its alerts are on.
- **Hibernation** (`deploy.hibernation`, Starter and above, as Deployer's "idle hibernation"). An environment can hibernate after 5, 15, 30, 60, 120 or 1440 minutes without requests. Its websites' Caddy access logs are checked every five minutes; deploys count as activity.
  - Hibernating puts Laravel apps in maintenance mode (`artisan down`) and stops worker units.
  - The first request after that wakes the environment within a minute, as does a deploy or scaling.
  - Hibernate and wake by hand on the page, or with `PATCH /api/v1/environments/{environment}/runtime` (`state`: `running` or `hibernated`, answered with 202).
- **Workflow (Deployer's version 1 YAML):** `PUT /api/v1/projects/{project}/workflow` (`workflow`), or the form on the Configuration page, applies these per-environment settings in one transaction:
  - a scheduled deploy (`deployment`);
  - scaling and hibernation (`scale`);
  - scaling schedules (`scaling_schedules`, which replace earlier workflow ones);
  - processes (`processes`).
  The last document applied is kept on the project. It needs Deploy management rights and the plan features each section uses.

## Recipes (part 7)

- **Recipes are account-level Bash scripts** (encrypted) that run as root at the end of a new server's provisioning. They're at Account → Recipes.
  - Members with Infrastructure access see them; members and above create, edit, duplicate and delete them.
  - Each save keeps a revision (name, description, script, who, and why: created, edited, installed or refreshed). The last 50 are kept.
- **Server creation** takes an ordered choice of the account's recipes. The server keeps a snapshot, so later edits don't change what a server ran (as in Deployer).
- **Gallery (every account):**
  - Owners and admins can publish a recipe (it's shared with everyone) and unpublish it. Changing a published recipe's script is a new gallery revision.
  - People browse published recipes by category, search, popularity, rating or newest, read the script, and install it.
  - Installing makes an unpublished copy in their account, once per account (installing again opens the copy). It counts one install.
  - A copy knows its source revision. "Refresh from gallery" takes the latest, with the difference shown first.
- **Favourites and ratings:**
  - People keep favourites.
  - Ratings (1–5) come from people whose account installed the recipe and who aren't in the publishing account. The gallery shows the average and count.
- **Reports:**
  - Anyone outside the publishing account can report a published recipe (malicious, broken, spam or other, with details), change the report or withdraw it.
  - The publisher's owners and admins are told, see the reports on their recipes, and resolve them with a note or reopen them.
  - Reporters see their reports' status and are told when one is resolved.
  - Removing abusive recipes platform-wide belongs to the Phase 5 admin panel.
- **Not ported:**
  - Deployer's CSV recipe inventory export.

### Environment recipes (added 2026-09-28, owner request)

Deployer's experimental "blueprint recipes" (I11) prepared a recipe for an environment but never ran it. In v2 they run.

- **An environment's Recipes tab** keeps an ordered list of recipes taken from the account's library. Each is a snapshot (name and script, encrypted), so later library edits don't change it until someone refreshes it; the tab shows which snapshots are behind their library recipe. Members and above with Deploy access (`configureDeploy`) add, refresh, reorder and remove them; deleting the library recipe leaves the snapshot.
- **Run on servers** runs the whole list, in order, on every server the environment's websites are on. Each server gets one command in its command history (Infrastructure → server → Commands), as root, stopping at the first recipe that fails. It needs permission to run commands on those servers.
- **Run on new websites** (a setting, off by default): when a website one of the environment's repositories deploys to finishes setting up, the list runs on its server by itself, recorded in the same history with no person attached. If the server is busy with another command, it tries again every minute for ten minutes.
- Preview environments copy their source environment's recipes when they're made.

## Public contracts kept

- Build callback URLs and their signed parameters.
- `POST /api/repositories/{repository}/webhook` and (part 2) `POST /api/github-app/webhook`.
- Deployer API v1 (`/api/v1/me`, `projects`, `deployments`, `environments/{environment}/deploy|scale|runtime|variables`, configuration routes) arrives with part 4 and Phase 6.

## For the importer (Phase 7)

- Repositories and builds keep their IDs (webhook and callback URLs contain them). Webhook secrets and provider tokens are re-encrypted.
