# Workspace activity progress

## 24 September 2026 — include Deployer builds in Core activity

Core's workspace activity page now combines cross-app connection delivery steps with a read-only Deployer build activity provider. The provider is registered by Deployer and owns the lookup and mapping from local builds into the Core activity contract.

Before it returns a build, the provider requires an active Core Deployer project association, a current member product grant, an active local Deployer organization membership, and an active explicit mapping from the Core project to the build's canonical environment. It does not search unmapped Deployer projects or environments. Statuses are translated into the shared activity vocabulary; provider failure text is not rendered. Each item links to the existing authorized Deployer build page using a request-local organization context, which preserves the user's saved workspace selection.

When Deployer's activity tables are unavailable, the module returns an unavailable snapshot and Core still renders the other providers' activity. The workspace page distinguishes that degraded state from a genuinely empty activity feed.

Verification on 24 September:

- `php artisan test --compact tests/Feature/Core/WorkspaceWorkflowActivityTest.php`: **4 tests, 42 assertions passed**. Coverage includes mapped succeeded and approval-pending builds, exclusion of unmapped environments, and hiding items after mapping disconnection, grant revocation, or local membership loss; module outage leaves connection delivery activity visible.
- `php artisan test --compact tests/Feature/SignalThemeArchitectureTest.php tests/Feature/Core/WorkspaceWorkflowActivityTest.php tests/Feature/Core/ProjectConnectionDiagnosticsTest.php tests/Feature/Core/ProjectProductLinksTest.php`: **37 tests, 223 assertions passed**.
- Production Vite build and the Signal theme browser regression passed in `npm run test:signal-theme`.

## 24 September 2026 — include Analytics CSV exports

Analytics now registers a module-owned activity provider for CSV exports. It rechecks the active Core Analytics project grant, explicit site resource mapping, project-workspace-to-Analytics-workspace mapping, local workspace membership, and current Core product grant before returning an item. Core receives only safe status text and a link to the Analytics-host export record; filters, tokens, and raw job failure messages are not included. Expired pending work is reported as unknown, while completed history stays visible with its download retention explained.

The Analytics export record supports status, site-scoped ID download, and a locked owner/admin retry for failed records that have not expired. Retry clears private failure detail, refreshes retention, and queues the existing CSV job. Viewers retain record/download access under the existing site policy but cannot retry. Existing token-hash status and download URLs remain compatible. Analytics workspace roles now disappear as soon as the required Core grant is revoked, and project links reject sites mapped to a different Core workspace.

Verification on 24 September:

- `ANALYTICS_ENABLED=true ANALYTICS_HOST=analytics.test TRUSTED_HOSTS=analytics.test vendor/bin/phpunit tests/Modules/Analytics/Feature`: **41 tests, 322 assertions passed**. Coverage includes activity isolation across Analytics workspace mappings, revoked grants, safe export status, owner/admin retry, viewer denial, site-scoped ID download, and expired-file denial.
- `vendor/bin/pint --test` passed for all changed PHP files; `git diff --check` passed.

The workspace feed now covers connected-app delivery, Deployer builds, and Analytics CSV exports. The broader I8 background-task center, Analytics provisioning jobs, remaining module task providers, retry/cancellation for other task types, and customer-data rehearsal remain open.
