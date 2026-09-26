# Deployer workspace data export progress — 25 September 2026

Deployer now has a Signal **Data & privacy** page in workspace administration.
Workspace owners and administrators can download a versioned newline-delimited
JSON stream for the active Deployer workspace. Each request passes the shared
authentication and workspace-access middleware, then rechecks the local
owner/admin role before either the page or export is served.

The export covers workspace membership and invitations, project/environment
configuration, environment-variable metadata, process/resource metadata,
provider and infrastructure metadata, websites/domains/health history,
repositories/builds/webhook status, preview deployments, deployment and
scaling schedules, scheduled-task metadata/history, status pages/incidents,
backup destination/schedule/restore metadata, metric alert rules, and alert
destination metadata. Related records use workspace-scoped subqueries and
cursor-based streaming. Soft-deleted websites and repositories are retained.

Provider tokens, SSH credentials, environment values, shell commands, workflow
and resource configuration bodies, deployment payloads, logs/output, backup
contents and credentials, alert endpoints/signing secrets, invitation token
hashes, and status subscribers are excluded. The authenticated, private export
includes configuration metadata and stable product record IDs for review and
reconstruction without disclosing those secrets.

Regression coverage is authored for owner/admin access, viewer denial, tenant
isolation, streamed NDJSON headers/format, record relationships, and secret
exclusion. It remains unrun under the plan-wide test deferral. PHP lint, Pint,
Blade compilation, route registration, the module-boundary scanner, and
`git diff --check` pass. This adds an organization-scoped export alongside the
existing personal account export; full portability still needs review of
remaining operational and billing records and a migrated-customer rehearsal.
