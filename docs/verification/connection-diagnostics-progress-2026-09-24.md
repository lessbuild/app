# Connection diagnostics progress — 2026-09-24

## I10 slice — finite Monitor event-allowance diagnostics

Monitor project connections now report a warning when the authorized Monitor workspace reaches its configured usage-warning threshold and a danger diagnostic when its finite monthly event allowance is exhausted. Both reports show the UTC calendar month, accounted event count, cap, percentage, and a plan or telemetry-volume next step. The details state that Monitor's allowance is shared across the workspace's applications.

The provider resolves usage only after `MonitorProjectLink` confirms the active Core environment mapping and current product-workspace membership. It reads only Monitor's usage ledger and plan authority; it does not create alerts, send notifications, contact external services, or change retries. The quota result takes precedence over “No telemetry” for that environment when the workspace has exhausted its shared cap. Missing or unavailable plan data and explicitly unlimited event allowances do not produce a quota claim. Deployer's stored provider-check diagnostics already identify HTTP 429 responses, and Analytics currently has an explicitly unlimited event allowance.

The usage summary now distinguishes a finite event cap from an unavailable, unconfigured, or explicitly unlimited limit. An unlimited Monitor allowance is represented internally with `PHP_INT_MAX`; the threshold calculation now avoids overflowing when evaluating that value.

Verification:

- `php artisan test --compact tests/Feature/Core/ProjectProductLinksTest.php tests/Feature/Core/ProjectConnectionDiagnosticsTest.php tests/Feature/Core/DeployerConnectionDiagnosticTest.php tests/Feature/Monitor/CorePlanAuthorityTest.php`: **35 tests, 186 assertions passed**.
- Coverage includes an exhausted cap, the configured 80% warning, missing legacy limit configuration, explicit Core unlimited limits, no diagnostic details after membership is revoked, existing delivery precedence, freshness behavior, and no provider network calls.
- Pint, PHP syntax checks, and `git diff --check` passed.

This is a read-only Monitor quota slice. I10's broader production visual/accessibility acceptance and any future finite-cap diagnostics remain open.
