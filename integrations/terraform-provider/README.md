# Terraform provider for BuildPusher

Manage BuildPusher projects, servers, websites and uptime monitors as code. Servers and websites run in your own
cloud accounts; BuildPusher creates and sets them up through the providers you've connected.

```hcl
terraform {
  required_providers {
    buildpusher = { source = "lessbuild/buildpusher" }
  }
}

provider "buildpusher" {
  # Or set BUILDPUSHER_TOKEN (and BUILDPUSHER_URL for another address).
  token = var.buildpusher_token
}
```

Create the token under **Account → API tokens** with the scopes for what you manage: `projects:read`,
`projects:write`, `infrastructure:read`, `infrastructure:write`, `monitoring:read` and `monitoring:write`.

## Resources

| Resource | What it is | Changing it |
| --- | --- | --- |
| `buildpusher_project` | A project with its services (`deploy`, `infrastructure`, `monitoring`, `security`, `analytics`); `environments["production"]` gives an environment's ID. | In place; the services are synced. |
| `buildpusher_server` | A server in one of your connected cloud providers (`provider_id` from Account → Providers). Creating it waits until it's set up (about ten minutes). | Replaces it. |
| `buildpusher_website` | A website on an app server, with its domain, health check and self-healing. Creating or moving it waits until it's set up. | In place; a new server or address sets it up again. |
| `buildpusher_monitor` | An uptime check in a project environment: `http` (default), `tls` or `tcp`. Destroying it archives it and keeps its history. | In place, except `project_id` and `check_type`. |

Import existing ones with their IDs: `terraform import buildpusher_project.shop <project-id>`, and monitors as
`<project-id>/<monitor-id>`.

See [examples/main.tf](examples/main.tf) for a project with a server, a website and a check.

## Developing

```sh
go build ./...
go test ./...                       # unit tests
TF_ACC=1 go test ./internal/provider # runs Terraform against an in-memory API
```

The provider talks to the resources API (`/api/v2`), documented in the app's OpenAPI description at `/api/openapi.json`.

## Releasing

The Terraform Registry publishes from a public GitHub repository named `terraform-provider-buildpusher` with signed
GoReleaser releases. Copy this directory there, add a GPG key to the Registry and the repository's secrets
(`GPG_PRIVATE_KEY`, `PASSPHRASE`), and push a `v*` tag; `.goreleaser.yml` and `terraform-registry-manifest.json` are
ready.
