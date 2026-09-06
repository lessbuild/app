# BuildPusher release roadmap

Billing activation is intentionally deferred until the product is ready to release.

Independent monitoring configuration and the live paid-provider acceptance drill are deferred until release by user direction. They remain release requirements, but do not block pre-release product development. See [the next development sequence](NEXT_ROADMAP.md).

## Release foundations

- [x] Responsive visual audit across mobile, tablet, and desktop
- [x] Full automated feature and unit test baseline
- [x] Public service-level status with private admin diagnostics
- [x] Authenticator-app two-factor authentication and single-use recovery codes
- [ ] Independent uptime monitoring and heartbeat configuration — deferred until release
- [ ] Repeatable real-provider acceptance test for provision, deploy, rollback, backup, and restore — live drill deferred until release
- [x] Production email readiness diagnostics and operator documentation
- [x] Privacy policy, terms, account data export, and safe account/workspace deletion

## Integrations and infrastructure

- [x] GitHub App repository discovery and automatic webhook installation
- [x] Import an existing SSH server and application
- [x] Hetzner provider
- [x] Vultr provider
- [x] PostgreSQL application resources
- [x] Laravel, Node, Python, and Docker runtimes with application templates
- [x] Cloudflare DNS automation, aliases, redirects, temporary domains, and certificate inspection
- [x] Managed Valkey resources and guarded database inspection, credentials, and cloning
- [x] GitHub pull-request checks and durable preview comments
- [x] Health-checked multi-node load balancing with weighted upstreams and failover

## Deployment and operations

- [x] Deployment locks and maintenance windows
- [x] Blue/green release switching, pre-traffic canary validation, and rolling replicated-worker restarts
- [x] Versioned, scoped secrets with rotation reminders
- [x] Searchable and filterable live logs with retention controls
- [x] Scheduled maintenance, incident history, and status subscriptions
- [x] Email, Discord, Microsoft Teams, and PagerDuty alert destinations
- [x] CPU, memory, disk, network, process telemetry, charts, and threshold alerts
- [x] Encrypted application scheduled tasks with run history and failure alerts
- [x] Configurable process restart policies and delays
- [x] Forward-only environment promotion with immutable revision lineage and policy revalidation

## API and usability

- [x] OpenAPI specification and browsable API documentation
- [x] Named, scoped, expiring API tokens with rotation and request examples
- [x] Guided first-deployment validation
- [x] Keyboard command palette
- [x] Saved filters and customizable dashboard
- [x] Contextual product documentation
- [x] Per-workspace notification preferences
- [x] Extended CLI plus dependency-free MCP server
- [x] Granular operator, developer, auditor, billing, and viewer roles
- [x] Workspace IP restrictions, member-domain policy, mandatory 2FA, idle expiry, and OpenID Connect SSO
- [x] Keyboard-only and screen-reader accessibility audit

## Final release gate

- [x] End-to-end tests for every new workflow
- [x] Mobile, tablet, and desktop browser audit
- [x] Production security, backup, diagnostics, and concurrency checks
- [x] Roadmap requirement-by-requirement evidence review

## External release dependencies

These cannot be completed safely from source code alone and remain intentionally open:

- Configure a production SMTP provider and verified sender; `buildpusher:email:diagnose --send-to=…` is the acceptance check.
- Configure independently hosted uptime and heartbeat destinations; `buildpusher:monitoring:heartbeat --verify-status` and `lessbuild:diagnose` verify them.
- Authorize a disposable paid cloud account for the real provision/deploy/rollback/backup/restore acceptance drill; `buildpusher:acceptance:audit` records the evidence.
- Create the production GitHub App and supply its app ID, slug, PEM private key, and webhook secret. The code path is complete but stays hidden until all four values are configured.
- Activate Stripe products and price IDs only when release billing is approved.

The rolling strategy applies to replicated background workers. Web requests continue to use an atomic blue/green filesystem switch; BuildPusher does not claim multi-server percentage-based traffic splitting.
