# Project handover progress — 2026-09-24

I14 now has a version 1 `buildpusher.project-handover` export and a destination validation screen, both composed from the shared `x-signal.*` components.

Workspace owners and admins can download a private, no-store manifest containing stable Core and product resource references, canonical environment mappings, supported connection capabilities, ownership IDs, configuration-reference kinds, setup instructions, and counts of omitted mappings. The exporter checks workspace/project scope, active membership and product grants, and each product resource's current ownership/access provider. It excludes descriptions, arbitrary metadata, environment values, subscriptions, authentication credentials, and Analytics tracker collection IDs.

Owners and admins can upload a JSON manifest up to 2 MiB for a read-only dry run. The validator accepts only the current allowlisted schema and bounded record counts. It checks destination product access, available product plans, provider registration, resource availability, existing project/resource conflicts, environment and connection references, and plan entitlement for each connection capability. Billing limits appear only to workspace owners and billing managers. The report never echoes raw uploaded content or identifies projects in other workspaces. Validation does not create or update rows, transfer ownership, provision module resources, or move subscriptions. A successful report is not an import approval.

Verification passed:

- `tests/Feature/Core/ProjectHandoverTest.php`: **9 tests, 46 assertions** covering private export headers, credential and metadata exclusions, dry-run no-write behavior, unsafe/dangling input, workspace authorization, shared mappings, billing-detail privacy, missing product access/provider, and connection entitlements.
- `tests/Feature/Core/ProjectConnectionsTest.php` and `tests/Feature/Core/WorkspaceDashboardTest.php`: **25 tests, 200 assertions**.
- `tests/Feature/SignalThemeArchitectureTest.php` and `tests/Feature/LocalUiAssetTest.php`: **76 tests, 3,191 assertions**; the new handover pages use shared Signal form, panel, feedback, status, and action components.
- Blade view cache, Pint, and `git diff --check` passed.

This implements I14's manifest and validation scope. A transfer/import flow, source/destination ownership changes, and subscription moves remain out of scope by design. The changes are on `feature/unified-platform`; they are separate from the already-pushed Deployer Signal header refresh. They have not been released to the production websites.
