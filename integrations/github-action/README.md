# BuildPusher deploy (GitHub Action)

Deploys the commit that triggered the workflow to a BuildPusher environment, streams the deploy log into the job, and
fails the job if the deploy fails.

```yaml
name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: buildpusher/deploy-action@v1
        with:
          token: ${{ secrets.BUILDPUSHER_TOKEN }}
          project: shop
          environment: production
```

Inputs: `token` (required), `project` (required), `environment` (default `production`), `ref` (default: the pushed
commit), `wait` (default `true`), `comment` (default `true`), `github-token` (default: the workflow's token) and `url`
(default `https://buildpusher.com`).

Outputs: `deployment-id`, `deployment-url` and `status` (`succeeded`, `failed`, or `queued` without waiting). The job
summary shows the result too.

On pull requests the action adds a comment with the result and a link, and updates the same comment on later runs (one
per environment). Give the job `permissions: pull-requests: write` for that, or set `comment: false`.

Create the token under **Account → API tokens** with the Deploy scopes and save it as the `BUILDPUSHER_TOKEN`
repository secret. The action installs the [BuildPusher CLI](https://buildpusher.com/help/use-the-cli), so the runner
needs PHP 8.1 or later (GitHub's Ubuntu runners have it).

## Publishing

This folder is the action. Publish it as its own public repository (for example `buildpusher/deploy-action`) with
`action.yml` at the root, tag a release `v1`, and list it on the GitHub Marketplace.
