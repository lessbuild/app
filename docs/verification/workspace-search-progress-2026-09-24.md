# Workspace search progress

## 24 September 2026 — enforce product workspace ownership in cross-app search (verification deferred)

Core search already combines authorized project, Deployer server/deployment, Monitor incident, and Analytics site results. Monitor incidents can reference an alert rule, an uptime monitor, or—because both foreign keys are nullable—records with both references. The search adapter previously chose the alert-rule workspace first, even when the uptime monitor belonged elsewhere. It now constrains every non-null source relation to the Monitor workspaces explicitly mapped to the active Core workspace where the signed-in user still has local membership. It applies that check before the bounded result limit; a match on one side cannot leak a record whose other source is inaccessible. Authorized multi-source incidents use Monitor's existing primary-source rule for their result link.

The Deployer search adapter also omitted `environment_id` from its selected build columns, which made an environment-only deployment lose its workspace relation. It now selects that key, constrains every non-null repository/environment relation to the active workspace's mapped organizations before applying the result limit, and requires both sources to resolve to the same organization. This also excludes malformed cross-organization builds that happened to have a repository in the active workspace.

## 25 September 2026 — make authorized product administration searchable (verification deferred)

Core's searchable quick actions now come from the same workspace administration catalog rendered on Workspace management. The catalog is a single Core service, filters products through the current workspace membership and active product grants, and builds links through the configured product-origin/SSO adapter. The Signal command palette receives these labels and descriptions on every Core workspace screen, making advanced Deployer, Monitor, and Analytics settings discoverable without duplicating the route catalog. Destinations retain their own authorization checks; product revocation or an unavailable configured route removes that catalog entry on the next page request.

Deferred feature coverage verifies administration entries appear in the command palette for an active grant and disappear after revocation. Existing Core workspace search regressions remain unrun under the plan-wide test deferral. Cross-host acceptance, stale-palette behavior after access changes, and full keyboard/focus acceptance remain open.

Feature regressions create a mapped alert-rule incident plus a cross-workspace mixed incident, and an environment-only Deployer build plus a build whose repository and environment belong to different organizations. They expect only the unambiguous, correctly scoped records and verify each result URL's workspace context. The tests are authored but have not been run, following the instruction to defer test execution until the plan is complete.

Pint formatting and `php -l` passed for both module providers and the feature-test file, and `git diff --check` passed. No test runner was invoked. Full search acceptance still requires route-level revoked-access checks, destination reauthorization, keyboard/focus browser coverage, result-count validation, and partial-product-outage checks.
