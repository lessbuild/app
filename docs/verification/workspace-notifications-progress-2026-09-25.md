# Core workspace notification inbox progress — 25 September 2026

## Implemented in the working tree

- Added the Signal Core inbox at `/workspaces/{workspace}/notifications`, linked through a bell icon in the shared topbar. It groups activity by canonical Core project and shared environment and links back to Core projects and product-owned records.
- The module-owned activity providers supply authorized operational state without moving product databases into Core. Monitor now contributes open, acknowledged, resolved, and recovered incident state, alongside its existing health checks, heartbeat runs, telemetry receipts, and alert delivery state. Deployer contributes its mapped workflow, deployment, backup/recovery, provisioning, and operational records. Analytics contributes mapped export and ingestion processing state.
- Every inbox request resolves the active Core membership, current product grant, accessible project membership, and active product/project association. Source providers recheck the product-local membership, mapping, and workspace boundary. Provider database outages are reported independently so other product activity remains available.
- Read/unread state and project/workspace display preferences are stored per Core user. Bulk read actions apply the current inbox filters. Project-level preferences override workspace defaults and cannot target a project that lacks an active association with that product.
- The unread count, topbar indicator, rendered rows, and bulk-read action now share the same visible state/product/severity/project-filtered items. A severity or project filter therefore cannot leave a stale unread indicator for an item hidden from the current inbox.
- Inbox preferences affect only what appears in Core. Product-owned emails, alert destinations, retries, and escalation behavior remain unchanged. Result URLs use configured product origins; provider-supplied external hosts are discarded, and the link is omitted if no trusted product origin is configured. The inbox omits Monitor incident titles and source payload/error details.
- Added regression coverage for canonical project/environment links, untrusted result hosts, per-user read state, preference precedence, filtered bulk read, revoked workspace membership, product/project preference boundaries, partial product outages, and Monitor incident lifecycle/redaction.

## Remaining acceptance

- Inventory and map Deployer's existing user notification records to Core recipients and project/resource mappings without losing security and account notifications that do not belong in project threads.
- Decide and implement historical read-state/preference migration where source identities and notification records can be mapped unambiguously. Add Core inbox export/saved-filter parity if those remain in the accepted parity inventory.
- Expand controller-route coverage for product-grant and project-membership revocation plus individual read/unread and preference update/reset flows; verify screen-reader announcements and keyboard flow.
- Expand activity sources where current-state summaries cannot represent an existing product notification or lifecycle event. Preserve each module's original detail and delivery route.
- Run authored tests only after the entire unified-application plan is complete, per the user's instruction. No tests have been run for this slice.

No migration, deployment, or production write was performed for this slice.
