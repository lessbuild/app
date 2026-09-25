# Native project access implementation progress

This source change carries Core project restrictions into interactive Deployer, Monitor, and Analytics entry points. It is separate from the deployed pricing/Auth/help release and is not production acceptance evidence.

## Shared authority

`MappedProjectResourceAccess` resolves the current product principal through explicit identity maps and requires an active canonical user, reconciled workspace map, active workspace membership, and current product grant. Mapped resources additionally require an active canonical project, explicit project membership, and active project product. Local product roles and native tenant scoping remain required by module callers.

Both `ProjectResource` and legacy identity mappings are checked. Pending, conflicting, orphaned, archived, or cross-workspace mappings deny access rather than becoming unlinked resources. Environment resource mappings require a canonical environment; a deleted environment whose foreign key has become null remains denied. Truly unmapped product resources retain native authorization, and legacy authentication authority preserves the existing product behavior.

Analytics site collection pauses and Monitor environment pauses remain accessible to authorized members so they can inspect history and resume collection. This follows the existing importers' lifecycle semantics; it does not bypass project membership or product access. Lookups are bounded to native workspace candidate IDs, chunked at 500, and use a batch project-permission query. No authority decision survives the helper invocation.

## Module coverage

- Deployer applies mapped restrictions to project/environment and dependent resource policies, navigation, collections, search, API token operations, configuration/preview actions, manual deployment launches, backups, databases, operational incidents, and exports. Shared server/website/repository operations require permission for all linked project environments. Workspace quotas still use full organization relations; unattended webhook and scheduled launches retain their existing authority.
- Monitor uses explicit visibility scopes on interactive queries, preserving unfiltered ingestion, workers, and quota accounting. Coverage includes policies, telemetry, issues, releases/deployments, incidents, monitors/alerts/SLOs, reports, status management, audit records, delivery history/retry, search, and exports. An incident requires every populated source to be authorized. Mutation services recheck the selected environment within their transactions.
- Analytics restricts site policies, Livewire mount/render, navigation/counts/search, Core-facing links, downloads, workspace exports, and queued reports. Reports recheck the current requester before reading and publishing output. An authorized retry records the current requester. Source roles and collection pauses are retained.

## Validation and remaining work

Regression tests have been authored for native access after Core revocation, local role preservation, legacy/unmapped behavior, stale/conflicting maps, paused collection, API/job paths, and bounded batch queries. Automated tests remain unrun under the user's plan-wide test deferral. PHP syntax, formatting, whitespace, and module dependency checks are static evidence only.

The new regression files contain 13 shared-gate cases, 5 Deployer cases, 10 Monitor cases, and 10 Analytics cases. The Core cases include 505 distinct mappings across the lookup chunk boundary and deletion of a canonical environment. Analytics additionally preserves imported-account access through the reconciled identity map when the native `platform_user_id` backlink is absent. Changed PHP files passed sequential syntax checks and Pint; module dependency and whitespace checks passed.

Remaining requirements before full permission-parity acceptance:

- Core archived-project/resource histories currently fail the interactive gate. Historical exports need an explicit authority rule that preserves permitted archived data without reviving revoked membership. Analytics and Monitor scoped exports omit those mapped histories; Deployer's complete workspace export may deny when any included resource is inaccessible.
- Historical Deployer inbox/activity payloads, provider aggregates, and advanced status/shared-resource management still require a deeper mapping-aware audit.
- Monitor scheduled issue digests and persisted per-recipient notification history need a separate permission audit. This interactive query change does not change background collection or the recipient semantics of scheduled email/alert transport.
- Canonical hard deletion must not erase mapping history and accidentally turn a previously restricted native resource into an unmapped resource. There is no current Core project hard-delete route; any future deletion path needs mapping tombstones or coordinated cleanup before deletion.
- Authenticated cross-host browser acceptance, production database query performance, and the deferred regression suite remain open.

The pricing/Auth/help fixes were deployed independently. This progress record does not claim that the broader native-access source changes are deployed or that the full plan is complete.
