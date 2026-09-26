# Analytics workspace data export progress — 25 September 2026

Analytics now has a Signal **Data & privacy** page available from its workspace
management navigation. Workspace owners and administrators can download a
versioned newline-delimited JSON stream for that Analytics workspace. The
controller rechecks the caller's local role and, when Core authority is active,
the current Core membership and Analytics product grant before serving either
the page or the export.

The streaming exporter covers the workspace and memberships, invitations,
current and soft-deleted sites, event batches and event records, pseudonymous
visits, goals and goal-version history, conversions, daily report aggregates,
release/incident annotations, accepted-event usage periods, and report-export
metadata. Queries are scoped to the requested workspace and cursor through
large collections rather than materializing them in memory. The NDJSON header
describes the format and its privacy limits.

Site verification tokens, invitation/report download token hashes, report file
paths, and ingestion/report failure details are excluded. Site public IDs are
included because Analytics uses them as public tracker identifiers. Event and
visit visitor/session IDs are pseudonymous and are included to preserve
relationships needed by Analytics reports; the page explains this before
download. Authentication secrets, sessions, queued jobs, caches, and expiring
generated report files are not exported.

Regression coverage is authored for streaming, owner/admin access, viewer
denial, tenant isolation, soft-deleted site history, relationship data, and
secret exclusion. It remains unrun under the plan-wide test deferral. PHP lint,
Pint, Blade compilation, route registration with Analytics enabled, the module
boundary scanner, and `git diff --check` pass. This closes the Analytics
workspace-export gap but does not complete full product-data portability.
Deployer now has a separate owner/admin-only workspace export; remaining
operational and billing records still need review, and Monitor's export remains
scoped to its current workspace. See [Deployer workspace data export progress](deployer-workspace-data-export-progress-2026-09-25.md).
