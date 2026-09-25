# Remaining source implementation

This checklist separates missing source behavior from pending validation in the unified application plan. It does not narrow the approved plan or authorize skipping product features. Automated tests remain deferred until source implementation is complete; test execution, browser acceptance, migration rehearsal, and production configuration remain subsequent acceptance work.

## Access and lifecycle preservation

- Implement coordinated shared account/workspace deletion. Core security currently states that deletion is unavailable, and Deployer blocks its local deletion routes under Core authority. The replacement must track per-module cleanup/retention outcomes durably, support retry, preserve ownership/billing safeguards, and prevent partial local deletion from stranding other apps. `SetCanonicalProjectArchiveState` is a Core metadata operation, not this workflow.

## Remaining approved shared capabilities

1. **Core administration.** Replace remaining administration catalog handoffs with actual Core pages/actions backed by module-owned operations. Shared team/status/cost/feedback and Deployer analytics/access-request administration already exist. Remaining areas include Monitor alert/integration administration and Analytics sites/goals/data controls, along with the remaining catalog capabilities. Inventory the catalog before claiming coverage.
2. **Versioned project blueprints (I11).** Add definitions, preview of resources/environment bindings/plan impact, durable apply/resume, and idempotent module provisioning. Reuse deployment recipes and module operations. Keep secrets separate, subscription changes explicit, and domain/tracker verification real. No blueprint implementation currently exists.
3. **Native notification projection (I3).** Bridge native notifications and their existing read/preference state into the Core inbox without losing actionable or account/security notifications. The Core inbox currently projects workflow summaries and has its own read/preferences/filter records; that does not reconcile the native Deployer inbox. Retain delivery channels and per-recipient access checks.
4. **Complete resource-map inventory (I12).** Add authorized module-provided repository/server/deployment relationships and destinations to the existing canonical project map. Deployer's destination adapter currently supports only project/environment mappings. Keep shared-resource edge/count filtering and the existing accessible list alternative.
5. **Scoped credential management (I13).** Add Core create/rotate/revoke actions through product contracts. The current Core credential route/provider is read-only inventory. Retain native secret ownership, one-time secret display, native scope restrictions, current-role checks, and audit history.

## Items that are not missing source features

- Monitor application/environment restoration is now implemented through durable Core requests and native receipts, retained archive views, current manager authorization, explicit shared-project restoration, and fenced recovery. Native child archive/pause states and revoked credentials/checks are preserved. The additive migrations and deferred acceptance remain pending; see [restoration progress](verification/monitor-restoration-progress-2026-09-25.md). Analytics has no native restore action; collection pause/resume remains supported. Deployer backup restoration and release rollback remain ordinary authorized operations.

- Archived-data exports, recipient-scoped Monitor digests, Deployer history/shared-resource controls, direct dependency checks, and nested source projections are implemented. Independent review identified and closed concrete dependency and local-metadata permission regressions. Current membership/product grants remain mandatory; the export exception does not authorize interactive mutation. Deferred behavioral and performance acceptance remains distinct from source implementation.
- Workspace feature rollouts (§7.2) are implemented for shared credential inventory and delivery history: workspace preferences, server availability/pilot/management policy, audited changes, and daily exposure/outcome metrics. Existing views remain enabled by default; flags neither grant entitlements nor change background delivery. Migration and deferred validation are still required before release.
- I5 release comparisons are implemented in Monitor deployment details, including equal windows, source metrics, connected Analytics traffic/conversions, overlap context, and coverage limits.
- I14 transfer execution is outside the approved initial behavior; versioned manifest export and destination dry run are implemented.
- Held identity/billing ownership, unpublished Analytics paid pricing, and provider credentials are reconciliation/product/configuration inputs. They must be resolved before affected production behavior is enabled, but they are distinct from missing code.
- Authored but unrun tests are deferred validation. Stale matrix statements about already-implemented operational saved filters, exports, and task providers must be updated from source evidence rather than treated as new scope.

Once the source gaps above are closed, perform the full requirement-by-requirement audit in the main plan, then run the deferred regression/static/build/browser checks and production acceptance. Do not infer completion from a clean static check or a successful public-page request.
