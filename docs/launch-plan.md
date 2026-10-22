# Faster, easier set-up: plan

Asked for on 2026-10-22, after setting up vacenly.com showed where Buildpusher leaves people to assemble the pieces
themselves: the data ended up in the web site's database while the API's own sat empty, production settings were left
at Laravel's development defaults, a Laravel worker ran on the Node site, a 512 MB server ran Nuxt, PHP and MySQL, the
GitHub workflow was copied by hand and went stale, and the domain's TXT record needed explaining.

Each stage adds its roadmap item (planned) first, then ships with tests, translations, help, changelog and public
pages. Status: `[ ]` to do, `[x]` done.

## Stage 1: production check

- [x] `ProductionCheck` reads an environment (its variables, resources, processes, runtime, server) and lists what's
      wrong for production, each with a one-click fix where there is one:
      - `APP_DEBUG=true`, `APP_ENV` not `production` (production environments only)
      - `LOG_LEVEL` unset or `debug`; `LOG_STACK` unset (one log file that never rotates)
      - `APP_URL` with a trailing slash, or `http://`
      - cache and sessions left on the database default (file, or Redis/Valkey when the environment has one)
      - queued jobs (`QUEUE_CONNECTION` other than `sync`) with no worker
      - the server too small for what runs on it
- [x] Environment page: a Production check card with Fix and Fix all.
- [x] Each deploy's log starts with the check's warnings.

## Stage 2: one database per environment

- [x] A managed MySQL resource names the website whose database it uses (defaulting to the environment's PHP site),
      instead of silently taking the first repository's.
- [x] New websites only get a MySQL database when they need one (not for Node, Python or static sites).
- [x] A website's Database tab lists the environments using its database, and says when nothing does.

## Stage 3: stack detection

- [ ] `RepositoryInspector` reads a repository through its Git provider (no clone): the apps in it (root and one
      level down, such as `api/` and `web/`), each one's framework and version (Laravel, Symfony, WordPress, Nuxt,
      Next.js, Astro, Vite, Express, Django, Flask, Rails, Docker, Compose), what it needs (database engine, queue,
      scheduler, Redis) and its build and start commands.
- [ ] The repository page shows what was found and offers what's missing, such as a queue worker or the scheduler on
      the site that runs them.

## Stage 4: launch from a repository

- [ ] Projects → Launch from a repository: pick the repository and branch; Buildpusher inspects it and proposes one
      plan on one page: the server (existing, or a new one at a size that fits the stack, with its monthly cost),
      a website and environment per app with its domain, the database, workers, production variables, the secrets
      still needed, path filters and the build cache.
- [ ] Launch creates it all in order and deploys the API first, then the rest, ending on a checklist (DNS, TLS,
      health checks).

## Stage 5: the GitHub workflow by pull request

- [ ] Build server settings: Open a pull request adds or updates the workflow file through the GitHub App, instead
      of copying it by hand.
- [ ] When a repository's workflow is older than Buildpusher's, the environment says so and offers the pull request.

## Stage 6: faster deploys

- [ ] Pipelines can deploy steps side by side (a step can start with the one before it).
- [ ] Launch turns on the build cache and path filters, so a push to `web/` doesn't redeploy the API.
