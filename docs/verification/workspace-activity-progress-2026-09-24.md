# Workspace activity progress

## 24 September 2026 — include Deployer builds in Core activity

Core's workspace activity page now combines cross-app connection delivery steps with a read-only Deployer build activity provider. The provider is registered by Deployer and owns the lookup and mapping from local builds into the Core activity contract.

Before it returns a build, the provider requires an active Core Deployer project association, a current member product grant, an active local Deployer organization membership, and an active explicit mapping from the Core project to the build's canonical environment. It does not search unmapped Deployer projects or environments. Statuses are translated into the shared activity vocabulary; provider failure text is not rendered. Each item links to the existing authorized Deployer build page using a request-local organization context, which preserves the user's saved workspace selection.

When Deployer's activity tables are unavailable, the module returns an unavailable snapshot and Core still renders the other providers' activity. The workspace page distinguishes that degraded state from a genuinely empty activity feed.

Verification on 24 September:

- `php artisan test --compact tests/Feature/Core/WorkspaceWorkflowActivityTest.php`: **4 tests, 42 assertions passed**. Coverage includes mapped succeeded and approval-pending builds, exclusion of unmapped environments, and hiding items after mapping disconnection, grant revocation, or local membership loss; module outage leaves connection delivery activity visible.
- `php artisan test --compact tests/Feature/SignalThemeArchitectureTest.php tests/Feature/Core/WorkspaceWorkflowActivityTest.php tests/Feature/Core/ProjectConnectionDiagnosticsTest.php tests/Feature/Core/ProjectProductLinksTest.php`: **37 tests, 223 assertions passed**.
- Production Vite build and the Signal theme browser regression passed in `npm run test:signal-theme`.

Remaining activity work: add Analytics export/job providers and any other module-owned background work that is appropriate for a workspace-level activity feed. Provider adapters remain read-only; product-specific retry and cancellation controls need separate capability review.
