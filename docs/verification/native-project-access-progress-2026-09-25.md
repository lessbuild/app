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

The initial source commit `047a3ed` contains 13 shared-gate cases, 5 Deployer cases, 10 Monitor cases, and 10 Analytics cases. The Core cases include 505 distinct mappings across the lookup chunk boundary and deletion of a canonical environment. Analytics additionally preserves imported-account access through the reconciled identity map when the native `platform_user_id` backlink is absent. Changed PHP files passed sequential syntax checks and Pint; module dependency and whitespace checks passed.

## Retained history and recipient follow-up

An explicit `HistoricalExport` purpose now permits retained archived projects, resources, and environments and inactive project-product records during independently authorized read-only product exports. It still requires current canonical account, workspace and project membership, product grant, native export role, and exact reconciled mappings. Interactive entry points retain their active-state checks. Missing, suspended, conflicting, orphaned, and revoked authority remains denied. Deployer's complete workspace export verifies every included source; Monitor and Analytics preserve retained records within their authorized scopes. None of these reads changes lifecycle state.

Monitor digests are built for each recipient, rebuilt after claiming a delivery attempt, and checked again before transport. Each stored summary records its source application/environment pairs. Retained delivery history checks those sources before showing aggregate counts; older rows without source provenance keep their delivery metadata but hide counts in Core mode. Legacy mode preserves the prior display. Native verified-email, plan, opt-in, retry and deduplication behavior remains. The mail view uses the Monitor namespace. Independent source review found no concrete blocker; no messages were sent during implementation.

Deployer recipient history remains global across the actor's currently authorized native workspaces. Source filtering precedes totals, pagination, exports and notification mutations. Account/security and gallery correspondence retain their recipient-owned handling. Missing, unsupported or inaccessible sources do not expose historical payloads. The existing complete-workspace NDJSON format still does not contain activity or inbox rows; the separate interactive CSVs use current interactive access. Shared provider, status, load-balancer, investigation, backup and alert controls now check affected resources.

Independent review identified and fixed a direct dependency gap through provider/server/website/repository/build references. The helper now applies a finite dependency order with native ownership and retained-parent checks. Shared mutations separately check affected descendants and environment placements. Ordinary parent views remain available when only child records are denied. Build notes and server display labels retain their separate local-metadata permissions. The stronger remote-operation gates also apply to load-balancer target selection and backup-destination mutations.

Website/repository inventories, CSVs, Dashboard attention, Project resource graphs, previews, webhook history and deployment insights filter source rows before pagination, counts and aggregation. A denied exact latest build becomes unavailable; an older permitted build is not silently relabeled as latest. Permitted history remains separately available, and never-deployed filters still mean actual absence of build history. Source authority decisions are reused only within an invocation; no persistent permission cache was added. These changes have dedicated dependency, nested-projection and nested-history regression files, authored but unrun.

Analytics batches workspace site authorization, applies the project-link limit after filtering, and rechecks authority without persistent decisions. Its export service independently enforces the native owner/admin role before historical reads. Analytics has no existing native restore action; collection pause/resume remains supported, and export can preserve only source rows that still exist.

The follow-up brings the shared-gate file to 17 cases, Deployer's mapped-access file to 15, Monitor's canonical-access file to 23, and Analytics' canonical-access file to 13. New cases cover archived exports without permission revival, recipient separation and retry changes, history provenance, global workspace history, shared-resource controls, and bounded site batches. These tests remain unrun.

The additional Deployer regression files contain 13 dependency/mutation cases, six nested inventory/projection cases and five Dashboard/Project/history cases. The local server-label regression checks the request, controller and policy while confirming that remote operations remain denied. Final static validation passed across all 89 changed PHP files and all 508 compiled Blade files; explicit-file Pint, module boundaries, whitespace checks and the Vite production asset build passed. No automated regression or browser suite was executed.

## Workspace rollout controls

The existing shared credential inventory and delivery-history pages now have explicit server availability, pilot-workspace allowlists, server-managed settings, and owner/admin workspace preferences. Both remain enabled by default. Audited preference changes and daily aggregate exposure/completion/partial/failure/rejection/hold counts contain no request payloads or exception text. Pausing a view preserves native tools, subscription entitlements and background delivery. Eight behavioral regressions were authored and the existing workflow fixture was updated; no tests were run.

Before releasing this follow-up, apply the additive Core rollout migration `2026_09_25_210000_create_workspace_feature_rollouts.php` and Monitor digest-provenance migration `2026_09_25_120000_add_source_scope_to_issue_digest_deliveries.php`. Neither has been applied to production. The source-only status and remaining implementation are tracked in [the finite source checklist](../implementation-remaining-2026-09-25.md).

Remaining requirements before full permission-parity acceptance:

- Monitor's native application/environment restoration needs an explicit durable workflow for imported archived mappings. It must require the shared project to be explicitly restored first, reconcile exact native lifecycle results, retain paused/independently archived children, and leave revoked collectors/checks disabled. The export purpose is not authorization for mutation.
- Canonical hard deletion must not erase mapping history and accidentally turn a previously restricted native resource into an unmapped resource. There is no current Core project hard-delete route; any future deletion path needs mapping tombstones or coordinated cleanup before deletion.
- Authenticated cross-host browser acceptance, production database query performance, and the deferred regression suite remain open.

The pricing/Auth/help fixes were deployed independently. This progress record does not claim that the broader native-access source changes are deployed or that the full plan is complete.

Operational maintenance moved the Playwright browser cache and the development worktree's `node_modules` to the mounted volume, preserving their original paths with symlinks. The Vite build was repeated after dependency relocation to verify resolution through that path. Root free space remains limited (roughly 329 MB at the last check); application configuration, customer data, production release files and storage were unchanged. The active production pointer still resolves to `cdd92c690535ad66acbeda0a8d22df58304ddc79`.

Subsequent source work implements the Monitor restoration gap above; its migrations and deferred acceptance remain pending. See [restoration progress](monitor-restoration-progress-2026-09-25.md). A separate formatted-price hotfix moved production to `49371e115726d42c829cd242eca9ffa6b1da5e09`; [the pricing release record](core-pricing-auth-help-project-access-deployment-2026-09-25-dbb4502.md) contains the current live verification. The broader native-access/restoration work remains undeployed.
